"""
Database module for Samband API.
Handles SQLite storage with WAL mode for durability.
Data is stored indefinitely - no automatic cleanup of events.
"""

import json
import shutil
import sqlite3
from contextlib import contextmanager
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any

from app.config import get_settings


def get_db_path() -> Path:
    """Get database path and create directory if needed."""
    settings = get_settings()
    db_path = Path(settings.database_path)
    db_path.parent.mkdir(parents=True, exist_ok=True)
    return db_path


def get_backup_dir() -> Path:
    """Get backup directory path."""
    settings = get_settings()
    backup_dir = Path(settings.backup_path)
    backup_dir.mkdir(parents=True, exist_ok=True)
    return backup_dir


def init_database() -> None:
    """
    Initialize database with schema.
    Uses WAL mode for better concurrency and crash resistance.
    Runs integrity check on startup.
    """
    db_path = get_db_path()

    with sqlite3.connect(db_path) as conn:
        # Enable WAL mode for better performance and durability
        conn.execute("PRAGMA journal_mode=WAL")
        conn.execute("PRAGMA synchronous=NORMAL")
        conn.execute("PRAGMA cache_size=-64000")  # 64MB cache
        conn.execute("PRAGMA temp_store=MEMORY")
        conn.execute("PRAGMA foreign_keys=ON")
        conn.execute("PRAGMA auto_vacuum=INCREMENTAL")

        # Run integrity check
        result = conn.execute("PRAGMA integrity_check").fetchone()
        if result[0] != "ok":
            raise RuntimeError(f"Database integrity check failed: {result[0]}")

        conn.executescript("""
            -- Events table - stores raw data from Police API forever
            CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY,
                datetime TEXT NOT NULL,
                name TEXT NOT NULL,
                summary TEXT,
                url TEXT,
                type TEXT NOT NULL,
                location_name TEXT NOT NULL,
                location_gps TEXT,
                raw_data TEXT NOT NULL,
                fetched_at TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            -- Indexes for fast queries
            CREATE INDEX IF NOT EXISTS idx_events_datetime ON events(datetime DESC);
            CREATE INDEX IF NOT EXISTS idx_events_location ON events(location_name);
            CREATE INDEX IF NOT EXISTS idx_events_type ON events(type);
            CREATE INDEX IF NOT EXISTS idx_events_location_datetime ON events(location_name, datetime DESC);
            CREATE INDEX IF NOT EXISTS idx_events_type_datetime ON events(type, datetime DESC);

            -- Fetch log - keeps track of API calls
            CREATE TABLE IF NOT EXISTS fetch_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                fetched_at TEXT NOT NULL,
                events_fetched INTEGER NOT NULL,
                events_new INTEGER NOT NULL,
                success INTEGER NOT NULL,
                error_message TEXT
            );

            -- Backup log
            CREATE TABLE IF NOT EXISTS backup_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                backup_at TEXT NOT NULL,
                filename TEXT NOT NULL,
                size_bytes INTEGER,
                success INTEGER NOT NULL,
                error_message TEXT
            );
        """)
        conn.commit()


def checkpoint_wal() -> dict[str, Any]:
    """
    Checkpoint WAL file to main database.
    Prevents WAL file from growing too large.
    Returns checkpoint stats.
    """
    db_path = get_db_path()
    with sqlite3.connect(db_path) as conn:
        # TRUNCATE mode: checkpoint and truncate WAL file
        result = conn.execute("PRAGMA wal_checkpoint(TRUNCATE)").fetchone()
        return {
            "blocked": result[0],  # 0 = success, 1 = blocked
            "pages_written": result[1],
            "pages_remaining": result[2]
        }


def verify_database_integrity() -> dict[str, Any]:
    """
    Run full integrity check on database.
    Returns status and any errors found.
    """
    db_path = get_db_path()
    with sqlite3.connect(db_path) as conn:
        result = conn.execute("PRAGMA integrity_check").fetchall()
        errors = [row[0] for row in result if row[0] != "ok"]
        return {
            "ok": len(errors) == 0,
            "errors": errors if errors else None
        }


@contextmanager
def get_connection():
    """Context manager for database connection with WAL mode."""
    db_path = get_db_path()
    conn = sqlite3.connect(db_path, timeout=30)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    try:
        yield conn
    finally:
        conn.close()


def insert_event(conn: sqlite3.Connection, event: dict[str, Any]) -> bool:
    """
    Insert event if it doesn't exist.
    Returns True if new event was inserted.
    """
    try:
        conn.execute("""
            INSERT OR IGNORE INTO events
            (id, datetime, name, summary, url, type, location_name, location_gps, raw_data, fetched_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, (
            event["id"],
            event["datetime"],
            event["name"],
            event.get("summary", ""),
            event.get("url", ""),
            event["type"],
            event["location"]["name"],
            event["location"].get("gps", ""),
            json.dumps(event, ensure_ascii=False),
            datetime.utcnow().isoformat()
        ))
        return conn.total_changes > 0
    except sqlite3.IntegrityError:
        return False


def log_fetch(conn: sqlite3.Connection, events_fetched: int, events_new: int,
              success: bool, error_message: str | None = None) -> None:
    """Log a fetch operation."""
    conn.execute("""
        INSERT INTO fetch_log (fetched_at, events_fetched, events_new, success, error_message)
        VALUES (?, ?, ?, ?, ?)
    """, (
        datetime.utcnow().isoformat(),
        events_fetched,
        events_new,
        1 if success else 0,
        error_message
    ))
    conn.commit()


def cleanup_old_logs() -> int:
    """
    Remove fetch logs older than 30 days.
    Events are NEVER deleted - only logs.
    Returns number of deleted log entries.
    """
    cutoff = (datetime.utcnow() - timedelta(days=30)).isoformat()
    with get_connection() as conn:
        cursor = conn.execute(
            "DELETE FROM fetch_log WHERE fetched_at < ?", (cutoff,)
        )
        conn.commit()
        return cursor.rowcount


def create_backup() -> dict[str, Any]:
    """
    Create a verified backup of the database.
    Uses SQLite backup API for safe backup while database is in use.
    Verifies backup integrity after creation.
    """
    db_path = get_db_path()
    backup_dir = get_backup_dir()

    # Run WAL checkpoint before backup to ensure all data is in main DB
    checkpoint_wal()

    timestamp = datetime.utcnow().strftime("%Y%m%d_%H%M%S")
    backup_filename = f"events_backup_{timestamp}.db"
    backup_path = backup_dir / backup_filename

    try:
        # Use SQLite backup API for safe backup while database is in use
        with sqlite3.connect(db_path) as src_conn:
            with sqlite3.connect(backup_path) as dst_conn:
                src_conn.backup(dst_conn)

        size_bytes = backup_path.stat().st_size

        # Verify backup integrity
        with sqlite3.connect(backup_path) as verify_conn:
            result = verify_conn.execute("PRAGMA integrity_check").fetchone()
            if result[0] != "ok":
                # Delete corrupt backup
                backup_path.unlink()
                raise RuntimeError(f"Backup verification failed: {result[0]}")

            # Verify event count matches
            src_count = None
            with sqlite3.connect(db_path) as src_conn:
                src_count = src_conn.execute("SELECT COUNT(*) FROM events").fetchone()[0]
            backup_count = verify_conn.execute("SELECT COUNT(*) FROM events").fetchone()[0]

            if src_count != backup_count:
                backup_path.unlink()
                raise RuntimeError(f"Backup event count mismatch: {backup_count} vs {src_count}")

        # Log successful backup
        with get_connection() as conn:
            conn.execute("""
                INSERT INTO backup_log (backup_at, filename, size_bytes, success, error_message)
                VALUES (?, ?, ?, 1, NULL)
            """, (datetime.utcnow().isoformat(), backup_filename, size_bytes))
            conn.commit()

        # Cleanup old backups (keep last 30 days)
        cleanup_old_backups()

        return {
            "success": True,
            "filename": backup_filename,
            "path": str(backup_path),
            "size_bytes": size_bytes,
            "events_count": backup_count,
            "verified": True,
            "created_at": datetime.utcnow().isoformat()
        }

    except Exception as e:
        # Clean up failed backup file if it exists
        if backup_path.exists():
            backup_path.unlink()

        # Log failed backup
        with get_connection() as conn:
            conn.execute("""
                INSERT INTO backup_log (backup_at, filename, size_bytes, success, error_message)
                VALUES (?, ?, NULL, 0, ?)
            """, (datetime.utcnow().isoformat(), backup_filename, str(e)))
            conn.commit()

        return {
            "success": False,
            "error": str(e)
        }


def cleanup_old_backups(keep_days: int = 30) -> int:
    """
    Remove backup files older than keep_days.
    Returns number of deleted backups.
    """
    backup_dir = get_backup_dir()
    cutoff = datetime.utcnow() - timedelta(days=keep_days)
    deleted = 0

    for backup_file in backup_dir.glob("events_backup_*.db"):
        if backup_file.stat().st_mtime < cutoff.timestamp():
            backup_file.unlink()
            deleted += 1

    return deleted


def get_database_stats() -> dict[str, Any]:
    """Get database statistics."""
    db_path = get_db_path()

    with get_connection() as conn:
        # Event count
        total_events = conn.execute(
            "SELECT COUNT(*) as count FROM events"
        ).fetchone()["count"]

        # Location count
        location_count = conn.execute(
            "SELECT COUNT(DISTINCT location_name) as count FROM events"
        ).fetchone()["count"]

        # Date range
        date_range = conn.execute("""
            SELECT MIN(datetime) as oldest, MAX(datetime) as newest FROM events
        """).fetchone()

        # Database file size
        db_size = db_path.stat().st_size if db_path.exists() else 0

        # Last fetch
        last_fetch = conn.execute("""
            SELECT fetched_at, events_new FROM fetch_log
            WHERE success = 1 ORDER BY fetched_at DESC LIMIT 1
        """).fetchone()

        # Last backup
        last_backup = conn.execute("""
            SELECT backup_at, filename, size_bytes FROM backup_log
            WHERE success = 1 ORDER BY backup_at DESC LIMIT 1
        """).fetchone()

    return {
        "total_events": total_events,
        "unique_locations": location_count,
        "date_range": {
            "oldest": date_range["oldest"] if date_range else None,
            "newest": date_range["newest"] if date_range else None
        },
        "database_size_mb": round(db_size / (1024 * 1024), 2),
        "last_fetch": {
            "at": last_fetch["fetched_at"] if last_fetch else None,
            "new_events": last_fetch["events_new"] if last_fetch else None
        },
        "last_backup": {
            "at": last_backup["backup_at"] if last_backup else None,
            "filename": last_backup["filename"] if last_backup else None,
            "size_mb": round(last_backup["size_bytes"] / (1024 * 1024), 2) if last_backup and last_backup["size_bytes"] else None
        }
    }


def get_events(
    location: str | None = None,
    event_type: str | None = None,
    date: str | None = None,
    from_date: str | None = None,
    to_date: str | None = None,
    limit: int = 500,
    offset: int = 0,
    sort: str = "desc"
) -> list[dict[str, Any]]:
    """
    Get events with optional filters.
    Returns data in same format as Police API.
    """
    query = "SELECT raw_data FROM events WHERE 1=1"
    params: list[Any] = []

    if location:
        query += " AND location_name = ?"
        params.append(location)

    if event_type:
        query += " AND type = ?"
        params.append(event_type)

    if date:
        # Supports YYYY, YYYY-MM, YYYY-MM-DD
        query += " AND datetime LIKE ?"
        params.append(f"{date}%")

    if from_date:
        query += " AND datetime >= ?"
        params.append(from_date)

    if to_date:
        query += " AND datetime <= ?"
        params.append(f"{to_date}T23:59:59")

    # Sorting
    order = "DESC" if sort.lower() == "desc" else "ASC"
    query += f" ORDER BY datetime {order}"

    # Pagination
    query += " LIMIT ? OFFSET ?"
    params.extend([limit, offset])

    with get_connection() as conn:
        rows = conn.execute(query, params).fetchall()
        return [json.loads(row["raw_data"]) for row in rows]


def get_event_count(
    location: str | None = None,
    event_type: str | None = None,
    from_date: str | None = None,
    to_date: str | None = None
) -> int:
    """Count events with filters."""
    query = "SELECT COUNT(*) as count FROM events WHERE 1=1"
    params: list[Any] = []

    if location:
        query += " AND location_name = ?"
        params.append(location)

    if event_type:
        query += " AND type = ?"
        params.append(event_type)

    if from_date:
        query += " AND datetime >= ?"
        params.append(from_date)

    if to_date:
        query += " AND datetime <= ?"
        params.append(f"{to_date}T23:59:59")

    with get_connection() as conn:
        row = conn.execute(query, params).fetchone()
        return row["count"] if row else 0


def get_locations() -> list[dict[str, Any]]:
    """Get all unique locations with event counts."""
    query = """
        SELECT location_name as name, COUNT(*) as count
        FROM events
        GROUP BY location_name
        ORDER BY count DESC
    """
    with get_connection() as conn:
        rows = conn.execute(query).fetchall()
        return [{"name": row["name"], "count": row["count"]} for row in rows]


def get_types() -> list[dict[str, Any]]:
    """Get all unique event types with counts."""
    query = """
        SELECT type, COUNT(*) as count
        FROM events
        GROUP BY type
        ORDER BY count DESC
    """
    with get_connection() as conn:
        rows = conn.execute(query).fetchall()
        return [{"type": row["type"], "count": row["count"]} for row in rows]


def get_stats(location: str | None = None) -> dict[str, Any]:
    """Get statistics, optionally for a specific location."""
    with get_connection() as conn:
        # Base filter
        where = "WHERE location_name = ?" if location else ""
        params = [location] if location else []

        # Total count
        total = conn.execute(
            f"SELECT COUNT(*) as count FROM events {where}", params
        ).fetchone()["count"]

        # By type
        by_type = conn.execute(f"""
            SELECT type, COUNT(*) as count FROM events {where}
            GROUP BY type ORDER BY count DESC
        """, params).fetchall()

        # By month (all months, not just last 12)
        by_month = conn.execute(f"""
            SELECT strftime('%Y-%m', datetime) as month, COUNT(*) as count
            FROM events {where}
            GROUP BY month ORDER BY month DESC
        """, params).fetchall()

        # Latest event
        latest = conn.execute(f"""
            SELECT datetime FROM events {where}
            ORDER BY datetime DESC LIMIT 1
        """, params).fetchone()

        # Oldest event
        oldest = conn.execute(f"""
            SELECT datetime FROM events {where}
            ORDER BY datetime ASC LIMIT 1
        """, params).fetchone()

        return {
            "total": total,
            "by_type": {row["type"]: row["count"] for row in by_type},
            "by_month": {row["month"]: row["count"] for row in by_month},
            "date_range": {
                "oldest": oldest["datetime"] if oldest else None,
                "latest": latest["datetime"] if latest else None
            }
        }

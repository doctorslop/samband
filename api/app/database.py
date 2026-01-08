import json
import sqlite3
from contextlib import contextmanager
from datetime import datetime
from pathlib import Path
from typing import Any

from app.config import get_settings


def get_db_path() -> Path:
    """Hämta databasväg och skapa mapp om den inte finns."""
    settings = get_settings()
    db_path = Path(settings.database_path)
    db_path.parent.mkdir(parents=True, exist_ok=True)
    return db_path


def init_database() -> None:
    """Initiera databasen med schema."""
    db_path = get_db_path()

    with sqlite3.connect(db_path) as conn:
        conn.executescript("""
            -- Händelsetabell - sparar rå-data från Polisen
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

            -- Index för snabba sökningar
            CREATE INDEX IF NOT EXISTS idx_events_datetime ON events(datetime DESC);
            CREATE INDEX IF NOT EXISTS idx_events_location ON events(location_name);
            CREATE INDEX IF NOT EXISTS idx_events_type ON events(type);
            CREATE INDEX IF NOT EXISTS idx_events_datetime_location ON events(datetime DESC, location_name);

            -- Logg för hämtningar
            CREATE TABLE IF NOT EXISTS fetch_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                fetched_at TEXT NOT NULL,
                events_fetched INTEGER NOT NULL,
                events_new INTEGER NOT NULL,
                success INTEGER NOT NULL,
                error_message TEXT
            );
        """)
        conn.commit()


@contextmanager
def get_connection():
    """Context manager för databasanslutning."""
    db_path = get_db_path()
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    try:
        yield conn
    finally:
        conn.close()


def insert_event(conn: sqlite3.Connection, event: dict[str, Any]) -> bool:
    """
    Infoga en händelse om den inte redan finns.
    Returnerar True om ny händelse infogades.
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
    """Logga en hämtning."""
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
    Hämta händelser med valfria filter.
    Returnerar data i exakt samma format som Polisens API.
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
        # Stödjer YYYY, YYYY-MM, YYYY-MM-DD
        query += " AND datetime LIKE ?"
        params.append(f"{date}%")

    if from_date:
        query += " AND datetime >= ?"
        params.append(from_date)

    if to_date:
        query += " AND datetime <= ?"
        params.append(f"{to_date}T23:59:59")

    # Sortering
    order = "DESC" if sort.lower() == "desc" else "ASC"
    query += f" ORDER BY datetime {order}"

    # Paginering
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
    """Räkna händelser med filter."""
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
    """Hämta alla unika platser med antal händelser."""
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
    """Hämta alla unika händelsetyper med antal."""
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
    """Hämta statistik, valfritt för en specifik plats."""
    with get_connection() as conn:
        # Basfilter
        where = "WHERE location_name = ?" if location else ""
        params = [location] if location else []

        # Total antal
        total = conn.execute(
            f"SELECT COUNT(*) as count FROM events {where}", params
        ).fetchone()["count"]

        # Per typ
        by_type = conn.execute(f"""
            SELECT type, COUNT(*) as count FROM events {where}
            GROUP BY type ORDER BY count DESC
        """, params).fetchall()

        # Per månad
        by_month = conn.execute(f"""
            SELECT strftime('%Y-%m', datetime) as month, COUNT(*) as count
            FROM events {where}
            GROUP BY month ORDER BY month DESC
            LIMIT 12
        """, params).fetchall()

        # Senaste händelse
        latest = conn.execute(f"""
            SELECT datetime FROM events {where}
            ORDER BY datetime DESC LIMIT 1
        """, params).fetchone()

        # Äldsta händelse
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

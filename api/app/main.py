"""
Samband API - Main application module.
FastAPI-based backend for collecting and serving police events.
"""

import logging
from contextlib import asynccontextmanager
from typing import Annotated

from apscheduler.schedulers.asyncio import AsyncIOScheduler
from apscheduler.triggers.interval import IntervalTrigger
from fastapi import Depends, FastAPI, Query, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from slowapi import Limiter
from slowapi.errors import RateLimitExceeded
from slowapi.util import get_remote_address

from app.config import get_settings
from app.database import (
    cleanup_old_logs,
    create_backup,
    get_database_stats,
    get_event_count,
    get_events,
    get_locations,
    get_stats,
    get_types,
    init_database,
)
from app.fetcher import fetch_and_store_events
from app.security import verify_api_key

# Logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s"
)
logger = logging.getLogger(__name__)

# Settings
settings = get_settings()

# Rate limiter
limiter = Limiter(key_func=get_remote_address)

# Scheduler
scheduler = AsyncIOScheduler()


async def scheduled_backup():
    """Run scheduled backup."""
    logger.info("Running scheduled backup...")
    result = create_backup()
    if result["success"]:
        logger.info(f"Backup created: {result['filename']} ({result['size_bytes']} bytes)")
    else:
        logger.error(f"Backup failed: {result.get('error')}")


async def scheduled_cleanup():
    """Run scheduled cleanup of old logs."""
    logger.info("Running scheduled cleanup...")
    deleted = cleanup_old_logs()
    logger.info(f"Cleaned up {deleted} old log entries")


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Application startup and shutdown."""
    # Startup
    logger.info("Starting Samband API...")
    init_database()

    # Schedule event fetching
    scheduler.add_job(
        fetch_and_store_events,
        trigger=IntervalTrigger(minutes=settings.fetch_interval_minutes),
        id="fetch_events",
        name="Fetch events from Police API",
        replace_existing=True
    )

    # Schedule daily backup
    scheduler.add_job(
        scheduled_backup,
        trigger=IntervalTrigger(hours=settings.backup_interval_hours),
        id="backup",
        name="Daily database backup",
        replace_existing=True
    )

    # Schedule daily cleanup
    scheduler.add_job(
        scheduled_cleanup,
        trigger=IntervalTrigger(hours=settings.cleanup_interval_hours),
        id="cleanup",
        name="Daily log cleanup",
        replace_existing=True
    )

    scheduler.start()
    logger.info(f"Scheduler started:")
    logger.info(f"  - Fetching every {settings.fetch_interval_minutes} minutes")
    logger.info(f"  - Backup every {settings.backup_interval_hours} hours")
    logger.info(f"  - Cleanup every {settings.cleanup_interval_hours} hours")

    # Initial fetch on startup
    logger.info("Running initial fetch...")
    await fetch_and_store_events()

    # Create initial backup if none exists
    db_stats = get_database_stats()
    if db_stats["last_backup"]["at"] is None:
        logger.info("No backup found, creating initial backup...")
        await scheduled_backup()

    yield

    # Shutdown
    scheduler.shutdown(wait=False)
    logger.info("Samband API stopped")


# App
app = FastAPI(
    title="Samband API",
    description="API for police events with historical data",
    version="1.0.0",
    lifespan=lifespan,
    docs_url="/docs" if settings.is_development else None,
    redoc_url="/redoc" if settings.is_development else None,
)

# Rate limit error handler
@app.exception_handler(RateLimitExceeded)
async def rate_limit_handler(request: Request, exc: RateLimitExceeded):
    return JSONResponse(
        status_code=429,
        content={"error": "Too many requests. Try again later."}
    )

app.state.limiter = limiter

# CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.allowed_origins_list if settings.allowed_origins_list else ["*"],
    allow_credentials=True,
    allow_methods=["GET", "POST"],
    allow_headers=["X-API-Key"],
)


# === PUBLIC ENDPOINTS ===

@app.get("/health")
async def health_check():
    """Health check for monitoring."""
    return {"status": "ok"}


# === PROTECTED ENDPOINTS ===

@app.get("/api/events")
@limiter.limit(f"{settings.rate_limit_per_minute}/minute")
async def list_events(
    request: Request,
    api_key: Annotated[str, Depends(verify_api_key)],
    location: Annotated[str | None, Query(description="Filter by location")] = None,
    type: Annotated[str | None, Query(description="Filter by event type")] = None,
    date: Annotated[str | None, Query(description="Filter by date (YYYY, YYYY-MM, or YYYY-MM-DD)")] = None,
    from_date: Annotated[str | None, Query(alias="from", description="From date (YYYY-MM-DD)")] = None,
    to_date: Annotated[str | None, Query(alias="to", description="To date (YYYY-MM-DD)")] = None,
    limit: Annotated[int, Query(ge=1, le=1000, description="Max results")] = 500,
    offset: Annotated[int, Query(ge=0, description="Skip N results")] = 0,
    sort: Annotated[str, Query(description="Sort: desc (newest first) or asc")] = "desc"
):
    """
    Get events with optional filters.
    Returns data in same format as Police API.
    """
    events = get_events(
        location=location,
        event_type=type,
        date=date,
        from_date=from_date,
        to_date=to_date,
        limit=limit,
        offset=offset,
        sort=sort
    )

    total = get_event_count(
        location=location,
        event_type=type,
        from_date=from_date,
        to_date=to_date
    )

    return {
        "events": events,
        "total": total,
        "limit": limit,
        "offset": offset,
        "has_more": offset + len(events) < total
    }


@app.get("/api/events/raw")
@limiter.limit(f"{settings.rate_limit_per_minute}/minute")
async def list_events_raw(
    request: Request,
    api_key: Annotated[str, Depends(verify_api_key)],
    location: Annotated[str | None, Query()] = None,
    type: Annotated[str | None, Query()] = None,
    date: Annotated[str | None, Query()] = None,
    from_date: Annotated[str | None, Query(alias="from")] = None,
    to_date: Annotated[str | None, Query(alias="to")] = None,
    limit: Annotated[int, Query(ge=1, le=1000)] = 500,
    offset: Annotated[int, Query(ge=0)] = 0,
    sort: Annotated[str, Query()] = "desc"
):
    """
    Get events in exact same format as Police API (array only).
    For compatibility with existing code.
    """
    return get_events(
        location=location,
        event_type=type,
        date=date,
        from_date=from_date,
        to_date=to_date,
        limit=limit,
        offset=offset,
        sort=sort
    )


@app.get("/api/locations")
@limiter.limit(f"{settings.rate_limit_per_minute}/minute")
async def list_locations(
    request: Request,
    api_key: Annotated[str, Depends(verify_api_key)]
):
    """
    Get all unique locations with event counts.
    Sorted by count (most first).
    """
    return get_locations()


@app.get("/api/types")
@limiter.limit(f"{settings.rate_limit_per_minute}/minute")
async def list_types(
    request: Request,
    api_key: Annotated[str, Depends(verify_api_key)]
):
    """Get all unique event types with counts."""
    return get_types()


@app.get("/api/stats")
@limiter.limit(f"{settings.rate_limit_per_minute}/minute")
async def get_statistics(
    request: Request,
    api_key: Annotated[str, Depends(verify_api_key)],
    location: Annotated[str | None, Query(description="Filter stats by location")] = None
):
    """
    Get statistics, optionally for a specific location.
    Includes breakdown by type and month.
    """
    return get_stats(location=location)


@app.get("/api/database")
@limiter.limit(f"{settings.rate_limit_per_minute}/minute")
async def database_info(
    request: Request,
    api_key: Annotated[str, Depends(verify_api_key)]
):
    """
    Get database statistics and health info.
    Includes event count, date range, last fetch, last backup.
    """
    return get_database_stats()


@app.post("/api/fetch")
@limiter.limit("6/minute")
async def trigger_fetch(
    request: Request,
    api_key: Annotated[str, Depends(verify_api_key)]
):
    """
    Trigger manual fetch from Police API.
    Use sparingly - automatic fetch runs every 5 minutes.
    """
    result = await fetch_and_store_events()
    return result


@app.post("/api/backup")
@limiter.limit("2/hour")
async def trigger_backup(
    request: Request,
    api_key: Annotated[str, Depends(verify_api_key)]
):
    """
    Trigger manual database backup.
    Use sparingly - automatic backup runs every 24 hours.
    """
    result = create_backup()
    return result

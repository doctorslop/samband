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
    get_event_count,
    get_events,
    get_locations,
    get_stats,
    get_types,
    init_database,
)
from app.fetcher import fetch_and_store_events
from app.security import get_client_ip, verify_api_key

# Logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s"
)
logger = logging.getLogger(__name__)

# Rate limiter
settings = get_settings()
limiter = Limiter(key_func=get_remote_address)

# Scheduler
scheduler = AsyncIOScheduler()


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Startup och shutdown logik."""
    # Startup
    logger.info("Starting Samband API...")
    init_database()

    # Schemalägg regelbunden hämtning
    scheduler.add_job(
        fetch_and_store_events,
        trigger=IntervalTrigger(minutes=settings.fetch_interval_minutes),
        id="fetch_events",
        name="Fetch events from Police API",
        replace_existing=True
    )
    scheduler.start()
    logger.info(f"Scheduler started, fetching every {settings.fetch_interval_minutes} minutes")

    # Initial hämtning vid uppstart
    logger.info("Running initial fetch...")
    await fetch_and_store_events()

    yield

    # Shutdown
    scheduler.shutdown(wait=False)
    logger.info("Samband API stopped")


# App
app = FastAPI(
    title="Samband API",
    description="API för polishändelser med historik",
    version="1.0.0",
    lifespan=lifespan
)

# Rate limit error handler
@app.exception_handler(RateLimitExceeded)
async def rate_limit_handler(request: Request, exc: RateLimitExceeded):
    return JSONResponse(
        status_code=429,
        content={"error": "För många förfrågningar. Försök igen senare."}
    )

app.state.limiter = limiter

# CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.allowed_origins_list if settings.allowed_origins_list else ["*"],
    allow_credentials=True,
    allow_methods=["GET"],
    allow_headers=["X-API-Key"],
)


# Health check (ingen auth krävs)
@app.get("/health")
async def health_check():
    """Hälsokontroll för monitoring."""
    return {"status": "ok"}


# === SKYDDADE ENDPOINTS ===

@app.get("/api/events")
@limiter.limit(f"{settings.rate_limit_per_minute}/minute")
async def list_events(
    request: Request,
    api_key: Annotated[str, Depends(verify_api_key)],
    location: Annotated[str | None, Query(description="Filtrera på plats")] = None,
    type: Annotated[str | None, Query(description="Filtrera på händelsetyp")] = None,
    date: Annotated[str | None, Query(description="Filtrera på datum (YYYY, YYYY-MM, eller YYYY-MM-DD)")] = None,
    from_date: Annotated[str | None, Query(alias="from", description="Från datum (YYYY-MM-DD)")] = None,
    to_date: Annotated[str | None, Query(alias="to", description="Till datum (YYYY-MM-DD)")] = None,
    limit: Annotated[int, Query(ge=1, le=1000, description="Max antal resultat")] = 500,
    offset: Annotated[int, Query(ge=0, description="Hoppa över N resultat")] = 0,
    sort: Annotated[str, Query(description="Sortering: desc (nyast först) eller asc")] = "desc"
):
    """
    Hämta händelser med valfria filter.
    Returnerar data i samma format som Polisens API.
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
    location: Annotated[str | None, Query(description="Filtrera på plats")] = None,
    type: Annotated[str | None, Query(description="Filtrera på händelsetyp")] = None,
    date: Annotated[str | None, Query(description="Filtrera på datum")] = None,
    from_date: Annotated[str | None, Query(alias="from")] = None,
    to_date: Annotated[str | None, Query(alias="to")] = None,
    limit: Annotated[int, Query(ge=1, le=1000)] = 500,
    offset: Annotated[int, Query(ge=0)] = 0,
    sort: Annotated[str, Query()] = "desc"
):
    """
    Hämta händelser i exakt samma format som Polisens API (endast array).
    För kompatibilitet med befintlig kod.
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
    Hämta alla unika platser med antal händelser.
    Sorterat på antal (flest först).
    """
    return get_locations()


@app.get("/api/types")
@limiter.limit(f"{settings.rate_limit_per_minute}/minute")
async def list_types(
    request: Request,
    api_key: Annotated[str, Depends(verify_api_key)]
):
    """
    Hämta alla unika händelsetyper med antal.
    """
    return get_types()


@app.get("/api/stats")
@limiter.limit(f"{settings.rate_limit_per_minute}/minute")
async def get_statistics(
    request: Request,
    api_key: Annotated[str, Depends(verify_api_key)],
    location: Annotated[str | None, Query(description="Filtrera statistik på plats")] = None
):
    """
    Hämta statistik, valfritt för en specifik plats.
    Inkluderar fördelning per typ och månad.
    """
    return get_stats(location=location)


@app.post("/api/fetch")
@limiter.limit("6/minute")  # Max 6 manuella hämtningar per minut
async def trigger_fetch(
    request: Request,
    api_key: Annotated[str, Depends(verify_api_key)]
):
    """
    Trigga manuell hämtning från Polisens API.
    Använd sparsamt - automatisk hämtning sker var 5:e minut.
    """
    result = await fetch_and_store_events()
    return result

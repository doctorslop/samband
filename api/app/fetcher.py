import logging
from datetime import datetime

import httpx

from app.config import get_settings
from app.database import get_connection, insert_event, log_fetch

logger = logging.getLogger(__name__)

# User-Agent som Polisens API accepterar
USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/120.0.0.0 Safari/537.36"
)


async def fetch_police_events() -> list[dict]:
    """
    Hämta händelser från Polisens API.
    Returnerar lista med händelser eller tom lista vid fel.
    """
    settings = get_settings()

    try:
        async with httpx.AsyncClient(timeout=settings.police_api_timeout) as client:
            response = await client.get(
                settings.police_api_url,
                headers={"User-Agent": USER_AGENT}
            )
            response.raise_for_status()
            events = response.json()

            if not isinstance(events, list):
                logger.error(f"Unexpected response format: {type(events)}")
                return []

            logger.info(f"Fetched {len(events)} events from Police API")
            return events

    except httpx.TimeoutException:
        logger.error("Timeout when fetching from Police API")
        return []
    except httpx.HTTPStatusError as e:
        logger.error(f"HTTP error from Police API: {e.response.status_code}")
        return []
    except Exception as e:
        logger.error(f"Error fetching from Police API: {e}")
        return []


async def fetch_and_store_events() -> dict:
    """
    Hämta händelser från Polisen och spara nya i databasen.
    Returnerar statistik om hämtningen.
    """
    start_time = datetime.utcnow()
    events = await fetch_police_events()

    if not events:
        # Logga misslyckad hämtning
        with get_connection() as conn:
            log_fetch(conn, 0, 0, False, "Failed to fetch events")
        return {
            "success": False,
            "events_fetched": 0,
            "events_new": 0,
            "error": "Failed to fetch events from Police API"
        }

    # Spara händelser
    new_count = 0
    with get_connection() as conn:
        for event in events:
            # Validera att händelsen har nödvändiga fält
            if not all(k in event for k in ("id", "datetime", "name", "type", "location")):
                logger.warning(f"Skipping event with missing fields: {event.get('id', 'unknown')}")
                continue

            if insert_event(conn, event):
                new_count += 1

        conn.commit()
        log_fetch(conn, len(events), new_count, True)

    elapsed = (datetime.utcnow() - start_time).total_seconds()
    logger.info(f"Stored {new_count} new events out of {len(events)} in {elapsed:.2f}s")

    return {
        "success": True,
        "events_fetched": len(events),
        "events_new": new_count,
        "elapsed_seconds": elapsed
    }

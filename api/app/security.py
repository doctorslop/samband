import hashlib
import hmac
import logging
from typing import Annotated

from fastapi import Depends, HTTPException, Request, status
from fastapi.security import APIKeyHeader

from app.config import get_settings

logger = logging.getLogger(__name__)

# API-nyckel header
api_key_header = APIKeyHeader(name="X-API-Key", auto_error=False)


def constant_time_compare(a: str, b: str) -> bool:
    """
    Jämför två strängar på konstant tid för att förhindra timing attacks.
    """
    return hmac.compare_digest(a.encode(), b.encode())


async def verify_api_key(
    request: Request,
    api_key: Annotated[str | None, Depends(api_key_header)]
) -> str:
    """
    Verifiera API-nyckel från header.
    Kastar 401 om nyckel saknas eller är ogiltig.
    """
    settings = get_settings()

    if api_key is None:
        logger.warning(f"Missing API key from {get_client_ip(request)}")
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="API-nyckel saknas. Skicka med X-API-Key header."
        )

    if not constant_time_compare(api_key, settings.api_key):
        logger.warning(f"Invalid API key from {get_client_ip(request)}")
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Ogiltig API-nyckel."
        )

    return api_key


def get_client_ip(request: Request) -> str:
    """
    Hämta klientens IP-adress, ta hänsyn till proxy-headers.
    """
    # Kolla vanliga proxy-headers
    forwarded_for = request.headers.get("X-Forwarded-For")
    if forwarded_for:
        # Ta första IP:n (original-klienten)
        return forwarded_for.split(",")[0].strip()

    real_ip = request.headers.get("X-Real-IP")
    if real_ip:
        return real_ip.strip()

    # Fallback till direkt anslutning
    if request.client:
        return request.client.host

    return "unknown"


def hash_for_logging(value: str) -> str:
    """Hasha ett värde för säker loggning."""
    return hashlib.sha256(value.encode()).hexdigest()[:16]

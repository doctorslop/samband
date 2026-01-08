import os
import secrets
from functools import lru_cache
from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    """Applikationsinställningar med säkra standardvärden."""

    # API-säkerhet
    api_key: str = secrets.token_urlsafe(32)  # Generera om ingen finns
    allowed_origins: str = ""

    # Databas
    database_path: str = "./data/events.db"

    # Polisens API
    police_api_url: str = "https://polisen.se/api/events"
    police_api_timeout: int = 30

    # Schemaläggning
    fetch_interval_minutes: int = 5

    # Rate limiting
    rate_limit_per_minute: int = 60

    # Miljö
    environment: str = "production"

    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"

    @property
    def allowed_origins_list(self) -> list[str]:
        """Returnera origins som lista."""
        if not self.allowed_origins:
            return []
        return [origin.strip() for origin in self.allowed_origins.split(",")]

    @property
    def is_development(self) -> bool:
        return self.environment.lower() == "development"


@lru_cache
def get_settings() -> Settings:
    """Cachad settings-instans."""
    return Settings()

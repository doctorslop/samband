"""
Configuration module for Samband API.
All settings can be overridden via environment variables or .env file.
"""

import secrets
from functools import lru_cache
from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    """Application settings with secure defaults."""

    # API security
    api_key: str = secrets.token_urlsafe(32)
    allowed_origins: str = ""

    # Database
    database_path: str = "./data/events.db"
    backup_path: str = "./data/backups"

    # Police API
    police_api_url: str = "https://polisen.se/api/events"
    police_api_timeout: int = 30

    # Scheduling
    fetch_interval_minutes: int = 5
    backup_interval_hours: int = 24
    cleanup_interval_hours: int = 24

    # Rate limiting
    rate_limit_per_minute: int = 60

    # Environment
    environment: str = "production"

    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"

    @property
    def allowed_origins_list(self) -> list[str]:
        """Return origins as list."""
        if not self.allowed_origins:
            return []
        return [origin.strip() for origin in self.allowed_origins.split(",")]

    @property
    def is_development(self) -> bool:
        return self.environment.lower() == "development"


@lru_cache
def get_settings() -> Settings:
    """Cached settings instance."""
    return Settings()

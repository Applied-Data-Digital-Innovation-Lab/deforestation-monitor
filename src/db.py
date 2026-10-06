"""Shared Neon connection."""

import os
from pathlib import Path

from dotenv import load_dotenv
from sqlalchemy import create_engine

ROOT = Path(__file__).resolve().parents[1]
load_dotenv(ROOT / ".env")

NEON_URL = os.getenv("NEON_URL")


def engine():
    if not NEON_URL:
        raise SystemExit("NEON_URL not found in .env")

    # Neon can drop idle connections while GFW queries are running.
    # Check connections before reusing them and recycle old ones.
    return create_engine(NEON_URL, pool_pre_ping=True, pool_recycle=300)
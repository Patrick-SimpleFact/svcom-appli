"""Configuration du POC : villes pilotes et chargement du .env (sans dépendance externe)."""

import os
from dataclasses import dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT_DIR = ROOT / "poc" / "out"


@dataclass(frozen=True)
class City:
    slug: str
    name: str
    lat: float
    lon: float
    radius_km: float


# Villes pilotes (décision D3). La zone rurale est un choix provisoire : à ajuster.
CITIES = [
    City("paris", "Paris", 48.8566, 2.3522, 8),
    City("bordeaux", "Bordeaux", 44.8378, -0.5792, 10),
    City("avignon", "Avignon", 43.9493, 4.8055, 10),
    City("figeac", "Figeac (zone rurale)", 44.6086, 2.0317, 30),
]


def load_env(path: Path = ROOT / ".env") -> None:
    """Charge un fichier KEY=VALUE dans os.environ, sans écraser l'existant."""
    if not path.exists():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def env(name: str) -> str:
    return os.environ.get(name, "").strip()

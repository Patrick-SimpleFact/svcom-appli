"""Modèle d'événement normalisé et utilitaires partagés par les connecteurs."""

import gzip
import json
import math
import os
import re
import ssl
import time
import unicodedata
import urllib.parse
import urllib.request
from dataclasses import asdict, dataclass, fields
from datetime import date, datetime

USER_AGENT = "svcom-poc/0.1 (+https://spectacles-vivants.com)"

# Le Python de python.org sur macOS n'a pas de magasin de certificats : on prend celui du système.
_SYSTEM_CA = "/etc/ssl/cert.pem"
SSL_CONTEXT = ssl.create_default_context(cafile=_SYSTEM_CA if os.path.exists(_SYSTEM_CA) else None)


@dataclass
class Event:
    """Une représentation (spectacle × date × lieu), toutes sources confondues."""

    source: str
    source_id: str
    city: str  # slug de la ville pilote interrogée
    title: str
    start: str = ""  # ISO 8601, heure locale si connue ("2026-10-03T20:30" ou "2026-10-03")
    end: str = ""
    venue: str = ""
    address: str = ""
    lat: float | None = None
    lon: float | None = None
    category: str = ""
    url: str = ""
    updated_at: str = ""
    is_live_show: bool | None = None

    @classmethod
    def columns(cls) -> list[str]:
        return [f.name for f in fields(cls)]

    def to_dict(self) -> dict:
        return asdict(self)


# --- HTTP -------------------------------------------------------------------

def http_get(url: str, params: dict | None = None, headers: dict | None = None,
             retries: int = 3, timeout: int = 60) -> bytes:
    if params:
        url += ("&" if "?" in url else "?") + urllib.parse.urlencode(params, doseq=True)
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, **(headers or {})})
    for attempt in range(retries):
        try:
            with urllib.request.urlopen(req, timeout=timeout, context=SSL_CONTEXT) as resp:
                data = resp.read()
                if resp.headers.get("Content-Encoding") == "gzip" or data[:2] == b"\x1f\x8b":
                    data = gzip.decompress(data)
                return data
        except urllib.error.HTTPError as e:
            if e.code == 429 and attempt < retries - 1:
                time.sleep(2 * (attempt + 1))
                continue
            body = e.read()[:300].decode("utf-8", "replace")
            raise RuntimeError(f"HTTP {e.code} sur {redact(url)} : {body}") from None
    raise RuntimeError(f"Échec après {retries} tentatives : {redact(url)}")


def http_json(url: str, params: dict | None = None, headers: dict | None = None):
    return json.loads(http_get(url, params, headers))


def redact(url: str) -> str:
    """Masque les clés d'API dans une URL avant de l'afficher."""
    url = re.sub(r"(apikey|key|api_key)=[^&]+", r"\1=***", url, flags=re.I)
    url = re.sub(r"/apikey/[^/]+", "/apikey/***", url)
    return re.sub(r"(/webservice/[^/]+/)[^/?]+", r"\1***", url)


# --- Géographie et texte ----------------------------------------------------

def haversine_km(lat1, lon1, lat2, lon2) -> float:
    r = 6371.0
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dp, dl = p2 - p1, math.radians(lon2 - lon1)
    a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * r * math.asin(math.sqrt(a))


def bbox(lat: float, lon: float, radius_km: float) -> tuple[float, float, float, float]:
    """(sud, ouest, nord, est) englobant le cercle."""
    dlat = radius_km / 111.0
    dlon = radius_km / (111.0 * math.cos(math.radians(lat)))
    return lat - dlat, lon - dlon, lat + dlat, lon + dlon


def normalize_text(s: str) -> str:
    s = unicodedata.normalize("NFKD", s or "").encode("ascii", "ignore").decode().lower()
    s = re.sub(r"[^a-z0-9 ]+", " ", s)
    return re.sub(r"\s+", " ", s).strip()


LIVE_KEYWORDS = [
    "theatre", "spectacle", "humour", "humoriste", "one man", "one woman", "stand up", "comedie",
    "cafe theatre", "concert", "musique", "opera", "operette", "danse", "ballet", "cirque",
    "marionnette", "conte", "cabaret", "magie", "magicien", "impro", "mime", "clown",
    "arts de la rue", "lecture", "recital", "chanson", "jazz", "festival", "piece",
    "arts & theatre", "music", "comedy", "theatre",
]
NOT_LIVE_KEYWORDS = [
    "exposition", "visite", "atelier", "conference", "marche", "brocante", "vide grenier",
    "salon", "randonnee", "sport", "match", "cinema", "projection", "stage",
]


def guess_live_show(*texts: str) -> bool:
    """Heuristique grossière : l'événement relève-t-il du spectacle vivant ?"""
    t = " " + normalize_text(" ".join(x for x in texts if x)) + " "
    live = any(f" {k} " in t or f" {k}s " in t for k in LIVE_KEYWORDS)
    not_live = any(f" {k} " in t or f" {k}s " in t for k in NOT_LIVE_KEYWORDS)
    return live and not not_live or (live and "spectacle" in t)


# --- Dates ------------------------------------------------------------------

def day_of(iso: str) -> date | None:
    try:
        return date.fromisoformat(iso[:10])
    except (ValueError, TypeError):
        return None


def has_time(iso: str) -> bool:
    return bool(iso) and len(iso) >= 16 and iso[11:16] != "00:00"


def parse_dt(value: str) -> datetime | None:
    if not value:
        return None
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None

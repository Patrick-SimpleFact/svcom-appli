"""« Que faire à Paris ? » — open data Ville de Paris (API OpenDataSoft, sans clé)."""

from datetime import date, datetime, timedelta
from zoneinfo import ZoneInfo

from ..common import Event, guess_live_show, http_json
from ..config import City

NAME = "paris_qfap"
REQUIRED_ENV: list[str] = []
BASE = "https://opendata.paris.fr/api/explore/v2.1/catalog/datasets/que-faire-a-paris-/exports/json"
PARIS_TZ = ZoneInfo("Europe/Paris")


def fetch(cities: list[City], start: date, end: date) -> list[Event]:
    city = next((c for c in cities if c.slug == "paris"), None)
    if not city:
        return []
    where = f"date_end >= date'{start.isoformat()}' and date_start <= date'{end.isoformat()}'"
    records = http_json(BASE, {"where": where, "timezone": "Europe/Paris"})
    events = []
    for r in records:
        events += _to_events(r, city, start, end)
    return events


def _dt(s: str) -> datetime | None:
    try:
        return datetime.fromisoformat(s).astimezone(PARIS_TZ)
    except (ValueError, TypeError):
        return None


def _to_events(r: dict, city: City, start: date, end: date) -> list[Event]:
    geo = r.get("lat_lon") or {}
    tags = r.get("qfap_tags") or r.get("tags") or ""
    if isinstance(tags, list):
        tags = ", ".join(tags)
    base = dict(
        source=NAME,
        source_id=str(r.get("id", "")),
        city=city.slug,
        title=r.get("title", ""),
        venue=r.get("address_name", "") or "",
        address=" ".join(filter(None, [r.get("address_street"), r.get("address_zipcode"),
                                       r.get("address_city")])),
        lat=geo.get("lat"),
        lon=geo.get("lon"),
        category=tags,
        url=r.get("url", ""),
        updated_at=r.get("updated_at", "") or "",
        is_live_show=guess_live_show(r.get("title", ""), tags, r.get("lead_text", "") or ""),
    )
    out = []
    # "occurrences" : "début_fin;début_fin;..." — une ligne par occurrence dans la fenêtre
    for occ in filter(None, (r.get("occurrences") or "").split(";")):
        b, _, e = occ.partition("_")
        bd, ed = _dt(b), _dt(e)
        if bd and start <= bd.date() <= end:
            out.append(Event(**base, start=bd.strftime("%Y-%m-%dT%H:%M"),
                             end=ed.strftime("%Y-%m-%dT%H:%M") if ed else ""))
    if out or r.get("occurrences"):
        return out
    # Pas d'occurrences détaillées : une ligne par jour couvert, sans horaire
    ds, de = _dt(r.get("date_start", "")), _dt(r.get("date_end", ""))
    if not ds or not de:
        return out
    d = max(ds.date(), start)
    while d <= min(de.date(), end):
        out.append(Event(**base, start=d.isoformat()))
        d += timedelta(days=1)
    return out

"""OpenAgenda API v2.

Il n'y a pas de recherche transverse d'événements : on cherche des agendas par nom de ville
(+ agendas imposés via OPENAGENDA_AGENDA_UIDS), puis on interroge chacun avec un filtre
géographique et temporel. Un des objectifs du POC est de mesurer si cette approche suffit.
"""

import time
from datetime import date, datetime, timedelta
from zoneinfo import ZoneInfo

from ..common import Event, bbox, guess_live_show, http_json
from ..config import City, env

NAME = "openagenda"
REQUIRED_ENV = ["OPENAGENDA_API_KEY"]
BASE = "https://api.openagenda.com/v2"
MAX_AGENDAS_PER_CITY = 60
PARIS_TZ = ZoneInfo("Europe/Paris")


def fetch(cities: list[City], start: date, end: date) -> list[Event]:
    key = env("OPENAGENDA_API_KEY")
    forced = [u.strip() for u in env("OPENAGENDA_AGENDA_UIDS").split(",") if u.strip()]
    events, seen = [], set()
    for city in cities:
        agendas = _search_agendas(key, city) + [{"uid": u, "slug": u} for u in forced]
        print(f"  {city.name} : {len(agendas)} agendas interrogés")
        for ag in agendas:
            try:
                for ev in _fetch_agenda(key, ag, city, start, end):
                    k = (ev.source_id, ev.start)
                    if k not in seen:  # un même événement peut être relayé par plusieurs agendas
                        seen.add(k)
                        events.append(ev)
            except RuntimeError as e:
                print(f"  ⚠ agenda {ag['uid']} : {e}")
            time.sleep(0.1)
    return events


def _search_agendas(key: str, city: City) -> list[dict]:
    name = city.name.split(" (")[0]
    data = http_json(f"{BASE}/agendas", {"key": key, "search": name, "size": 100})
    agendas = data.get("agendas", [])
    agendas.sort(key=lambda a: not a.get("official"))  # agendas officiels d'abord
    return [{"uid": a["uid"], "slug": a.get("slug", a["uid"])} for a in agendas[:MAX_AGENDAS_PER_CITY]]


def _fetch_agenda(key: str, agenda: dict, city: City, start: date, end: date) -> list[Event]:
    south, west, north, east = bbox(city.lat, city.lon, city.radius_km)
    params = {
        "key": key,
        "size": 300,
        "monolingual": "fr",
        "detailed": 1,
        "timings[gte]": f"{start.isoformat()}T00:00:00+02:00",
        "timings[lte]": f"{end.isoformat()}T23:59:59+02:00",
        "geo[northEast][lat]": north, "geo[northEast][lng]": east,
        "geo[southWest][lat]": south, "geo[southWest][lng]": west,
    }
    out, after = [], None
    while True:
        p = dict(params)
        if after:
            p["after[]"] = after
        data = http_json(f"{BASE}/agendas/{agenda['uid']}/events", p)
        for e in data.get("events", []):
            out += _to_events(e, agenda, city, start, end)
        after = data.get("after")
        if not after or not data.get("events"):
            return out


def _local(iso: str) -> datetime | None:
    try:
        return datetime.fromisoformat(iso.replace("Z", "+00:00")).astimezone(PARIS_TZ)
    except (ValueError, AttributeError):
        return None


def _to_events(e: dict, agenda: dict, city: City, start: date, end: date) -> list[Event]:
    """Une ligne par horaire (timing) tombant dans la fenêtre."""
    loc = e.get("location") or {}
    title = e.get("title") or ""
    keywords = e.get("keywords") or []
    if isinstance(keywords, dict):
        keywords = keywords.get("fr", [])
    desc = e.get("description") or ""
    out = []
    for t in e.get("timings", []):
        b, en = _local(t.get("begin", "")), _local(t.get("end", ""))
        if not b or not (start <= b.date() <= end):
            continue
        out.append(Event(
            source=NAME,
            source_id=str(e.get("uid", "")),
            city=city.slug,
            title=title,
            start=b.strftime("%Y-%m-%dT%H:%M"),
            end=en.strftime("%Y-%m-%dT%H:%M") if en else "",
            venue=loc.get("name", ""),
            address=loc.get("address", ""),
            lat=loc.get("latitude"),
            lon=loc.get("longitude"),
            category=", ".join(keywords[:5]),
            url=f"https://openagenda.com/fr/{agenda['slug']}/events/{e.get('slug', e.get('uid'))}",
            updated_at=e.get("updatedAt", ""),
            is_live_show=guess_live_show(title, " ".join(keywords), desc),
        ))
    return out

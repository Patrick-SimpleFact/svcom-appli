"""Ticketmaster Discovery API v2 (FR couverte via la recherche géographique)."""

import time
from datetime import date, timedelta

from ..common import Event, guess_live_show, http_json
from ..config import City, env

NAME = "ticketmaster"
REQUIRED_ENV = ["TICKETMASTER_CONSUMER_KEY"]
BASE = "https://app.ticketmaster.com/discovery/v2/events.json"
PAGE_SIZE = 200  # l'API refuse size * page > 1000


def fetch(cities: list[City], start: date, end: date) -> list[Event]:
    events = []
    for city in cities:
        events += _fetch_city(city, start, end)
    return events


def _fetch_city(city: City, start: date, end: date) -> list[Event]:
    params = {
        "apikey": env("TICKETMASTER_CONSUMER_KEY"),
        "latlong": f"{city.lat},{city.lon}",
        "radius": int(city.radius_km),
        "unit": "km",
        "countryCode": "FR",
        "startDateTime": f"{start.isoformat()}T00:00:00Z",
        "endDateTime": f"{(end + timedelta(days=1)).isoformat()}T00:00:00Z",
        "size": PAGE_SIZE,
        "sort": "date,asc",
        "locale": "*",
    }
    out, page = [], 0
    while True:
        data = http_json(BASE, {**params, "page": page})
        for e in data.get("_embedded", {}).get("events", []):
            out.append(_to_event(e, city))
        info = data.get("page", {})
        page += 1
        if page >= info.get("totalPages", 0) or (page + 1) * PAGE_SIZE > 1000:
            if info.get("totalElements", 0) > 1000:
                print(f"  ⚠ {city.name} : {info['totalElements']} résultats, seuls 1000 récupérables")
            break
        time.sleep(0.25)  # quota : 5 req/s
    return out


def _to_event(e: dict, city: City) -> Event:
    start = e.get("dates", {}).get("start", {})
    venue = (e.get("_embedded", {}).get("venues") or [{}])[0]
    loc = venue.get("location") or {}
    cls = (e.get("classifications") or [{}])[0]
    category = " / ".join(
        x.get("name", "") for x in (cls.get("segment"), cls.get("genre")) if x and x.get("name")
    )
    iso = start.get("localDate", "")
    if start.get("localTime"):
        iso += "T" + start["localTime"][:5]
    return Event(
        source=NAME,
        source_id=e.get("id", ""),
        city=city.slug,
        title=e.get("name", ""),
        start=iso,
        venue=venue.get("name", ""),
        address=" ".join(filter(None, [venue.get("address", {}).get("line1"),
                                       venue.get("city", {}).get("name")])),
        lat=float(loc["latitude"]) if loc.get("latitude") else None,
        lon=float(loc["longitude"]) if loc.get("longitude") else None,
        category=category,
        url=e.get("url", ""),
        is_live_show=cls.get("segment", {}).get("name") in ("Music", "Arts & Theatre")
        or guess_live_show(category, e.get("name", "")),
    )

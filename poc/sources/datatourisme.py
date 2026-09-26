"""DATAtourisme — flux créé sur la plateforme Diffuseur (catégorie Fête et manifestation).

Formats acceptés : archive ZIP du format « JSON » (index.json + un fichier par objet) ou
fichier JSON-LD unique (@graph). Les clés sont lues sans leur préfixe (schema:, rdfs:…)
pour résister aux variantes de format.
"""

import io
import json
import zipfile
from datetime import date, timedelta

from ..common import Event, guess_live_show, http_get
from ..config import City, env
from ._feeds import assign_city

NAME = "datatourisme"
REQUIRED_ENV = ["DATATOURISME_API_KEY", "DATATOURISME_FLUX_ID"]
FLUX_URL = "https://diffuseur.datatourisme.fr/webservice/{flux}/{key}"

# Classes de l'ontologie DATAtourisme relevant du spectacle vivant
LIVE_TYPES = {"ShowEvent", "TheaterEvent", "Concert", "DanceEvent", "CircusEvent", "Opera",
              "ComedyEvent", "MusicEvent", "Recital", "StreetArtShow", "PuppetShow", "Festival"}


def _objects() -> list[dict]:
    raw = http_get(FLUX_URL.format(flux=env("DATATOURISME_FLUX_ID"), key=env("DATATOURISME_API_KEY")),
                   timeout=600)
    if raw[:2] == b"PK":
        with zipfile.ZipFile(io.BytesIO(raw)) as z:
            return [json.loads(z.read(n)) for n in z.namelist()
                    if n.endswith(".json") and not n.endswith("index.json")]
    data = json.loads(raw)
    if isinstance(data, dict):
        return data.get("@graph", [data])
    return data


def get(obj, name: str):
    """obj[name] en ignorant les préfixes d'espace de noms."""
    if not isinstance(obj, dict):
        return None
    for k, v in obj.items():
        if k.split(":")[-1] == name:
            return v
    return None


def first(v):
    """Première valeur scalaire : gère listes, {"fr": [...]}, {"@value": ...}."""
    while isinstance(v, (list, dict)):
        if isinstance(v, list):
            if not v:
                return None
            v = v[0]
        else:
            v = v.get("fr", v.get("@value", next(iter(v.values()), None)))
    return v


def as_list(v) -> list:
    return v if isinstance(v, list) else ([] if v is None else [v])


def fetch(cities: list[City], start: date, end: date) -> list[Event]:
    objs = _objects()
    print(f"  {len(objs)} objets dans le flux")
    events = []
    for o in objs:
        events += _to_events(o, cities, start, end)
    return events


def _to_events(o: dict, cities: list[City], start: date, end: date) -> list[Event]:
    place = (as_list(get(o, "isLocatedAt")) or [{}])[0]
    geo = get(place, "geo") or {}
    geo = geo[0] if isinstance(geo, list) and geo else geo
    lat, lon = first(get(geo, "latitude")), first(get(geo, "longitude"))
    lat = float(lat) if lat not in (None, "") else None
    lon = float(lon) if lon not in (None, "") else None
    addr = (as_list(get(place, "address")) or [{}])[0]
    town = first(get(addr, "addressLocality")) or ""
    city = assign_city(lat, lon, town, cities)
    if not city:
        return []

    types = [t.split(":")[-1] for t in as_list(o.get("@type"))]
    title = first(get(o, "label")) or ""
    live = bool(LIVE_TYPES & set(types)) or guess_live_show(title, " ".join(types))
    homepage = ""
    for c in as_list(get(o, "hasBookingContact")) + as_list(get(o, "hasContact")):
        homepage = homepage or first(get(c, "homepage")) or ""
    base = dict(
        source=NAME,
        source_id=first(get(o, "identifier")) or o.get("@id", ""),
        city=city.slug,
        title=title,
        venue=first(get(place, "name")) or "",
        address=" ".join(filter(None, [first(get(addr, "streetAddress")),
                                       first(get(addr, "postalCode")), town])),
        lat=lat,
        lon=lon,
        category=", ".join(t for t in types if t not in ("Event", "EntertainmentAndEvent",
                                                          "PointOfInterest", "PlaceOfInterest")),
        url=homepage,
        updated_at=first(get(o, "lastUpdate")) or "",
        is_live_show=live,
    )

    out = []
    for period in as_list(get(o, "takesPlaceAt")):
        ds, de = first(get(period, "startDate")), first(get(period, "endDate")) or first(get(period, "startDate"))
        if not ds:
            continue
        t = (first(get(period, "startTime")) or "")[:5]
        d, last = max(date.fromisoformat(ds[:10]), start), min(date.fromisoformat(de[:10]), end)
        while d <= last:  # une ligne par jour de la période tombant dans la fenêtre
            out.append(Event(**base, start=f"{d.isoformat()}T{t}" if t else d.isoformat()))
            d += timedelta(days=1)
    return out


def inspect() -> str:
    objs = _objects()
    lines = [f"{len(objs)} objets.", ""]
    for o in objs[:2]:
        lines += [json.dumps(o, ensure_ascii=False, indent=1)[:3000], ""]
    return "\n".join(lines)

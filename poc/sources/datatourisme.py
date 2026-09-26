"""DATAtourisme — export quotidien national des événements (FMA) publié par ADN Tourisme
sur data.gouv.fr (Licence Ouverte 2.0). Aucun compte ni clé nécessaire.

L'URL du fichier change chaque jour : on la résout via l'API data.gouv.fr.
Limite connue : l'export ne contient que des dates (périodes), pas d'horaires.
"""

import csv
import io
import re
from datetime import date, timedelta

from ..common import Event, assign_city, guess_live_show, http_get, http_json
from ..config import City

NAME = "datatourisme"
REQUIRED_ENV: list[str] = []
DATASET_API = "https://www.data.gouv.fr/api/1/datasets/5b598be088ee387c0c353714/"
RESOURCE_TITLE = "datatourisme-fma.csv"

# Classes de l'ontologie DATAtourisme relevant du spectacle vivant
LIVE_TYPES = {"ShowEvent", "TheaterEvent", "Concert", "DanceEvent", "CircusEvent", "Opera",
              "ComedyEvent", "Recital", "StreetArtShow", "PuppetShow", "Festival"}
GENERIC_TYPES = {"Event", "EntertainmentAndEvent", "PointOfInterest", "CulturalEvent", "Product"}


def _rows() -> list[dict]:
    resources = http_json(DATASET_API)["resources"]
    url = next(r["url"] for r in resources if r["title"] == RESOURCE_TITLE)
    text = http_get(url, timeout=600).decode("utf-8-sig")
    return list(csv.DictReader(io.StringIO(text)))


def fetch(cities: list[City], start: date, end: date) -> list[Event]:
    rows = _rows()
    print(f"  {len(rows)} événements dans l'export national")
    events = []
    for r in rows:
        events += _to_events(r, cities, start, end)
    return events


def _float(s: str) -> float | None:
    try:
        return float(s)
    except ValueError:
        return None


def _to_events(r: dict, cities: list[City], start: date, end: date) -> list[Event]:
    lat, lon = _float(r["Latitude"]), _float(r["Longitude"])
    zip_code, _, town = r["Code_postal_et_commune"].partition("#")
    city = assign_city(lat, lon, town, cities)
    if not city:
        return []

    types = [t.split("#")[-1].split("/")[-1] for t in r["Categories_de_POI"].split("|")]
    specific = [t for t in dict.fromkeys(types) if t not in GENERIC_TYPES]
    title = r["Nom_du_POI"]
    link = re.search(r"https?://[^\s<>#]+", r["Contacts_du_POI"])
    base = dict(
        source=NAME,
        source_id=r["URI_ID_du_POI"].rsplit("/", 1)[-1],
        city=city.slug,
        title=title,
        address=" ".join(filter(None, [r["Adresse_postale"], zip_code, town])),
        lat=lat,
        lon=lon,
        category=", ".join(specific),
        url=link.group(0) if link else "",
        updated_at=r["Date_de_mise_a_jour"],
        is_live_show=bool(LIVE_TYPES & set(types)) or guess_live_show(title, " ".join(specific)),
    )

    out, days = [], set()
    for period in filter(None, r["Periodes_regroupees"].split("|")):
        a, _, b = period.partition("<->")
        try:
            d, last = max(date.fromisoformat(a), start), min(date.fromisoformat(b or a), end)
        except ValueError:
            continue
        while d <= last:  # une ligne par jour couvert (les périodes peuvent se chevaucher)
            if d not in days:
                days.add(d)
                out.append(Event(**base, start=d.isoformat()))
            d += timedelta(days=1)
    return out


def inspect() -> str:
    rows = _rows()
    lines = [f"{len(rows)} lignes. Colonnes : {', '.join(rows[0].keys())}", ""]
    for r in rows[:2]:
        lines += [f"  {k} = {v[:150]}" for k, v in r.items() if v] + [""]
    return "\n".join(lines)

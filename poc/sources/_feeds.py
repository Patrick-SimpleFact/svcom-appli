"""Outils communs aux sources par flux (fichier complet téléchargé puis filtré localement).

Le format exact des flux d'affiliation Awin n'est connu qu'une fois les
programmes validés : le mapping est donc heuristique (recherche de colonnes par nom).
`python -m poc.run --inspect <source>` affiche la structure réelle pour écrire un mapping exact.
"""

import re
from datetime import date

from ..common import Event, guess_live_show, haversine_km, normalize_text
from ..config import City

# Noms de champs candidats, comparés après normalisation (minuscules, sans accents)
FIELD_HINTS = {
    "id": ["aw_product_id", "product_id", "id", "identifiant", "code", "idmanif", "id_manifestation"],
    "title": ["product_name", "titre", "title", "nom", "libelle", "name", "intitule"],
    "start": ["date_debut", "datedebut", "start_date", "startdate", "date", "valid_from",
              "date_representation", "daterepresentation", "debut"],
    "time": ["heure", "horaire", "start_time", "starttime", "heure_debut"],
    "end": ["date_fin", "datefin", "end_date", "enddate", "valid_to", "fin"],
    "venue": ["lieu", "salle", "venue", "nom_lieu", "place", "location_name", "theatre"],
    "address": ["adresse", "address", "rue", "street"],
    "town": ["ville", "city", "commune", "town", "localite"],
    "zip": ["code_postal", "cp", "zipcode", "postal_code", "postcode"],
    "lat": ["latitude", "lat"],
    "lon": ["longitude", "lon", "lng", "long"],
    "category": ["merchant_category", "category_name", "categorie", "category", "genre", "rubrique",
                 "type"],
    "url": ["aw_deep_link", "deep_link", "url", "lien", "link", "merchant_deep_link"],
    "updated": ["last_updated", "updated_at", "date_maj", "modification", "lastupdate"],
}


def _norm_key(k: str) -> str:
    return re.sub(r"[^a-z0-9]", "_", normalize_text(k.split(":")[-1].split("/")[-1])).strip("_")


def pick(record: dict, field: str) -> str:
    keys = {_norm_key(k): v for k, v in record.items()}
    for hint in FIELD_HINTS[field]:
        v = keys.get(hint)
        if v not in (None, ""):
            return str(v).strip()
    return ""


def _to_float(s: str) -> float | None:
    try:
        return float(s.replace(",", "."))
    except (ValueError, AttributeError):
        return None


def _to_iso(day: str, hour: str = "") -> str:
    """Accepte AAAA-MM-JJ[ HH:MM] ou JJ/MM/AAAA ; renvoie AAAA-MM-JJ[THH:MM] ou ''."""
    day = day.strip()
    if m := re.match(r"(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?", day):
        y, mo, d, hh, mm = m.groups()
    elif m := re.match(r"(\d{2})/(\d{2})/(\d{4})(?:\s+(\d{2})[:h](\d{2}))?", day):
        d, mo, y, hh, mm = m.groups()
    else:
        return ""
    iso = f"{y}-{mo}-{d}"
    if not hh and hour:
        mh = re.match(r"(\d{1,2})[:h](\d{2})", hour.strip())
        if mh:
            hh, mm = mh.group(1).zfill(2), mh.group(2)
    return f"{iso}T{hh}:{mm}" if hh else iso


def assign_city(lat: float | None, lon: float | None, text: str, cities: list[City]) -> City | None:
    if lat is not None and lon is not None:
        for c in cities:
            if haversine_km(lat, lon, c.lat, c.lon) <= c.radius_km:
                return c
        return None
    t = f" {normalize_text(text)} "
    for c in cities:
        if f" {normalize_text(c.name.split(' (')[0])} " in t:
            return c
    return None


def record_to_event(record: dict, source: str, cities: list[City], start: date, end: date) -> Event | None:
    """Mapping heuristique d'un enregistrement de flux ; None si hors villes ou hors fenêtre."""
    iso = _to_iso(pick(record, "start"), pick(record, "time"))
    if iso:
        d = date.fromisoformat(iso[:10])
        if not (start <= d <= end):
            return None
    lat, lon = _to_float(pick(record, "lat")), _to_float(pick(record, "lon"))
    town_text = " ".join([pick(record, "town"), pick(record, "address"), pick(record, "venue")])
    city = assign_city(lat, lon, town_text, cities)
    if not city:
        return None
    title, category = pick(record, "title"), pick(record, "category")
    return Event(
        source=source,
        source_id=pick(record, "id"),
        city=city.slug,
        title=title,
        start=iso,
        end=_to_iso(pick(record, "end")),
        venue=pick(record, "venue"),
        address=" ".join(filter(None, [pick(record, "address"), pick(record, "zip"),
                                       pick(record, "town")])),
        lat=lat,
        lon=lon,
        category=category,
        url=pick(record, "url"),
        updated_at=pick(record, "updated"),
        is_live_show=guess_live_show(title, category) or None,
    )

"""Flux produits Awin (CSV gzip) : BilletRéduc et Fnac Spectacles.

On lit la liste des flux accessibles (URL « feedList » d'Awin, qui contient la clé datafeed),
puis on télécharge le flux de l'annonceur voulu via l'URL fournie dans cette liste.
Les deux annonceurs remplissent les colonnes différemment : un mapping par annonceur.
"""

import csv
import io
import json
import re
from datetime import date

from ..common import Event, assign_city, http_get, normalize_text
from ..config import City, env

# Catégories annonceur hors spectacle vivant (expos, parcs, cartes cadeaux…)
NOT_LIVE = re.compile(r"expo|musee|parc|aquarium|tourisme|carte cadeau|atelier|visite|salon|zoo|"
                      r"croisiere|sport|^none$|^$")


def _csv(raw: bytes) -> list[dict]:
    # Awin produit un CSV standard (virgule, guillemets doublés) : pas de détection automatique,
    # qui se trompait sur le flux Fnac et décalait ~15 % des lignes.
    return list(csv.DictReader(io.StringIO(raw.decode("utf-8-sig"))))


def _float(s: str | None) -> float | None:
    try:
        v = float(s or "")
    except ValueError:
        return None
    return v or None  # la Fnac met 0.0 quand les coordonnées sont inconnues


def _live(category: str) -> bool:
    return not NOT_LIVE.search(normalize_text(category))


class AwinFeed:
    """Un annonceur Awin = une source."""

    REQUIRED_ENV = ["AWIN_FEEDLIST_URL"]

    def __init__(self, name: str, advertiser_id: str, mapper):
        self.NAME = name
        self.advertiser_id = advertiser_id
        self._map = mapper

    def _feeds(self) -> list[dict]:
        rows = _csv(http_get(env("AWIN_FEEDLIST_URL")))
        feeds = [r for r in rows if r.get("Advertiser ID", "").strip() == self.advertiser_id]
        if not feeds:
            raise RuntimeError(f"aucun flux accessible pour l'annonceur {self.advertiser_id} "
                               f"({len(rows)} flux listés ; colonnes : "
                               f"{', '.join(rows[0].keys()) if rows else '—'})")
        return feeds

    def _rows(self) -> list[dict]:
        rows = []
        for f in self._feeds():
            rows += _csv(http_get(f["URL"], timeout=300))
        return rows

    def fetch(self, cities: list[City], start: date, end: date) -> list[Event]:
        rows = self._rows()
        print(f"  {len(rows)} produits dans le flux")
        return [ev for r in rows for ev in self._map(self.NAME, r, cities, start, end)]

    def inspect(self) -> str:
        feeds = self._feeds()
        rows = self._rows()
        lines = [f"Flux : " + ", ".join(f"{f['Feed ID']} {f.get('Feed Name', '')} "
                                        f"({f.get('No of products', '?')} produits)" for f in feeds),
                 f"{len(rows)} lignes. Colonnes : {', '.join(rows[0].keys()) if rows else '—'}", ""]
        for r in rows[:3]:
            lines += [f"  {k} = {str(v)[:120]}" for k, v in r.items() if v] + [""]
        return "\n".join(lines)


def map_billetreduc(source: str, r: dict, cities: list[City], start: date, end: date) -> list[Event]:
    """Un produit = un spectacle dans un lieu ; les séances sont en JSON dans custom_3."""
    lat, lon = _float(r.get("Tickets:latitude")), _float(r.get("Tickets:longitude"))
    city = assign_city(lat, lon, r.get("custom_2", ""), cities)
    if not city:
        return []
    try:
        sessions = json.loads(r.get("custom_3") or "[]")
    except json.JSONDecodeError:
        return []
    category = r.get("merchant_product_category_path", "")
    out = []
    for s in sessions:
        when = (s.get("SessionDate") or "")[:16]
        if not when or not (start <= date.fromisoformat(when[:10]) <= end):
            continue
        out.append(Event(
            source=source,
            source_id=r.get("merchant_product_id", ""),
            city=city.slug,
            title=r.get("product_name", ""),
            start=when,
            venue=r.get("Tickets:venue_name", ""),
            address=" ".join(filter(None, [r.get("Tickets:event_location_address", "").strip(),
                                           r.get("custom_1"), r.get("custom_2")])),
            lat=lat,
            lon=lon,
            category=category,
            url=r.get("aw_deep_link", ""),
            is_live_show=_live(category),
            sold_out=bool(s.get("SoldOut")),
        ))
    return out


def map_fnac(source: str, r: dict, cities: list[City], start: date, end: date) -> list[Event]:
    """Une ligne = une représentation (date dans Tickets:event_date, heure dans custom_7)."""
    day = r.get("Tickets:event_date", "")
    try:
        if not (start <= date.fromisoformat(day) <= end):
            return []
    except ValueError:
        return []
    lat, lon = _float(r.get("Tickets:latitude")), _float(r.get("Tickets:longitude"))
    town = r.get("Tickets:venue_address", "")  # contient en pratique la commune
    city = assign_city(lat, lon, town, cities)
    if not city:
        return []
    hour = r.get("custom_7", "")
    category = r.get("merchant_category", "")
    return [Event(
        source=source,
        source_id=r.get("merchant_product_id", ""),
        city=city.slug,
        title=r.get("product_name", ""),
        start=f"{day}T{hour}" if re.fullmatch(r"\d{2}:\d{2}", hour) and hour != "00:00" else day,
        venue=r.get("Tickets:venue_name", ""),
        address=" ".join(filter(None, [r.get("custom_4"), r.get("custom_3"), town])),
        lat=lat,
        lon=lon,
        category=category,
        url=r.get("aw_deep_link", ""),
        is_live_show=_live(category),
        sold_out=r.get("stock_quantity", "").startswith("6 -"),  # « 6 - NO_AMOUNT »
    )]


billetreduc = AwinFeed("billetreduc", "20796", map_billetreduc)
fnac = AwinFeed("fnac", "12494", map_fnac)

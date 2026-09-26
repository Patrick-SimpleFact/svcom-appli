"""Flux produits Awin (CSV gzip) : BilletRéduc et Fnac Spectacles.

Seule la clé datafeed est nécessaire : la liste des flux accessibles est lue sur
productdata.awin.com, puis on télécharge le flux de l'annonceur voulu.
"""

import csv
import io
from datetime import date

from ..common import Event, http_get
from ..config import City, env
from ._feeds import record_to_event

LIST_URL = "https://productdata.awin.com/datafeed/list/apikey/{key}"
# Colonnes demandées en plus du standard : les champs custom/valid_* portent souvent date et lieu
COLUMNS = ("aw_deep_link,product_name,aw_product_id,merchant_product_id,merchant_category,"
           "category_name,description,product_short_description,keywords,specifications,"
           "search_price,merchant_name,merchant_id,merchant_deep_link,last_updated,valid_from,"
           "valid_to,custom_1,custom_2,custom_3,custom_4,custom_5,custom_6,custom_7,custom_8,"
           "custom_9,data_feed_id")
DOWNLOAD_URL = ("https://productdata.awin.com/datafeed/download/apikey/{key}/language/fr/fid/{fid}"
                "/columns/{cols}/format/csv/delimiter/%2C/compression/gzip/")


def _csv(raw: bytes) -> list[dict]:
    try:
        text = raw.decode("utf-8-sig")
    except UnicodeDecodeError:
        text = raw.decode("latin-1")
    dialect = csv.Sniffer().sniff(text[:5000], delimiters=",;|\t")
    return list(csv.DictReader(io.StringIO(text), dialect=dialect))


class AwinFeed:
    """Un annonceur Awin = une source ; même format de flux pour tous."""

    REQUIRED_ENV = ["AWIN_DATAFEED_KEY"]

    def __init__(self, name: str, advertiser_id: str):
        self.NAME = name
        self.advertiser_id = advertiser_id

    def _feeds(self) -> list[dict]:
        rows = _csv(http_get(LIST_URL.format(key=env("AWIN_DATAFEED_KEY"))))
        feeds = [r for r in rows if str(r.get("Advertiser ID", "")).strip() == self.advertiser_id]
        if not feeds:
            raise RuntimeError(f"aucun flux accessible pour l'annonceur {self.advertiser_id} "
                               f"({len(rows)} flux listés)")
        return feeds

    def _rows(self) -> list[dict]:
        rows = []
        for f in self._feeds():
            key = env("AWIN_DATAFEED_KEY")
            try:
                raw = http_get(DOWNLOAD_URL.format(key=key, fid=f["Feed ID"], cols=COLUMNS), timeout=300)
            except RuntimeError:
                raw = http_get(f["URL"], timeout=300)  # colonnes standard proposées par Awin
            rows += _csv(raw)
        return rows

    def fetch(self, cities: list[City], start: date, end: date) -> list[Event]:
        rows = self._rows()
        print(f"  {len(rows)} lignes dans le flux")
        return [ev for r in rows if (ev := record_to_event(r, self.NAME, cities, start, end))]

    def inspect(self) -> str:
        feeds = self._feeds()
        rows = self._rows()
        lines = [f"Flux : " + ", ".join(f"{f['Feed ID']} {f.get('Feed Name', '')} "
                                        f"({f.get('No of products', '?')} produits)" for f in feeds),
                 f"{len(rows)} lignes. Colonnes : {', '.join(rows[0].keys()) if rows else '—'}", ""]
        for r in rows[:3]:
            lines += [f"  {k} = {str(v)[:120]}" for k, v in r.items() if v] + [""]
        return "\n".join(lines)


billetreduc = AwinFeed("billetreduc", "20796")
fnac = AwinFeed("fnac", "12494")

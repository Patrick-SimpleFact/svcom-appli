"""Mesures du POC : volume, qualité géoloc, fraîcheur, doublons inter-sources.

Produit dans le dossier du run :
  - rapport.md                      synthèse chiffrée
  - verification_<ville>.csv        échantillon du jour de référence à pointer à la main (étape 4)

Relançable seul sur un run existant : python -m poc.analyse poc/out/<run>
"""

import csv
import json
import statistics
import sys
from collections import Counter, defaultdict
from datetime import date, datetime, timezone
from difflib import SequenceMatcher
from pathlib import Path

from .common import Event, haversine_km, has_time, normalize_text, parse_dt
from .config import CITIES

CITY_BY_SLUG = {c.slug: c for c in CITIES}
TITLE_STOPWORDS = {"le", "la", "les", "l", "un", "une", "des", "de", "du", "d", "et", "a", "au"}


# --- Déduplication ------------------------------------------------------------

def _title_key(t: str) -> str:
    return " ".join(w for w in normalize_text(t).split() if w not in TITLE_STOPWORDS)


def _similar(a: str, b: str) -> float:
    if not a or not b:
        return 0.0
    if a in b or b in a:  # "Edmond" vs "Edmond de Alexis Michalik"
        return 0.9 if min(len(a), len(b)) >= 5 else 0.0
    return SequenceMatcher(None, a, b).ratio()


def _same_show(a: Event, b: Event) -> bool:
    if a.start[:10] != b.start[:10]:
        return False
    if has_time(a.start) and has_time(b.start):
        ta = int(a.start[11:13]) * 60 + int(a.start[14:16])
        tb = int(b.start[11:13]) * 60 + int(b.start[14:16])
        if abs(ta - tb) > 30:
            return False
    if _similar(_title_key(a.title), _title_key(b.title)) < 0.8:
        return False
    if a.lat is not None and b.lat is not None and a.lon is not None and b.lon is not None:
        return haversine_km(a.lat, a.lon, b.lat, b.lon) <= 0.5
    va, vb = normalize_text(a.venue), normalize_text(b.venue)
    return not va or not vb or _similar(va, vb) >= 0.6


def cluster(events: list[Event]) -> list[list[int]]:
    """Regroupe les représentations identiques (union-find), par ville et par jour."""
    parent = list(range(len(events)))

    def find(i):
        while parent[i] != i:
            parent[i] = parent[parent[i]]
            i = parent[i]
        return i

    buckets = defaultdict(list)
    for i, e in enumerate(events):
        buckets[(e.city, e.start[:10])].append(i)
    for idx in buckets.values():
        for x in range(len(idx)):
            for y in range(x + 1, len(idx)):
                i, j = idx[x], idx[y]
                if find(i) != find(j) and _same_show(events[i], events[j]):
                    parent[find(i)] = find(j)
    groups = defaultdict(list)
    for i in range(len(events)):
        groups[find(i)].append(i)
    return list(groups.values())


# --- Mesures ------------------------------------------------------------------

def _pct(n: int, total: int) -> str:
    return f"{100 * n / total:.0f} %" if total else "—"


def _age_days(updated: str, now: datetime) -> float | None:
    dt = parse_dt(updated)
    if not dt:
        return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return (now - dt).total_seconds() / 86400


def analyse(run_dir: Path) -> None:
    meta = json.loads((run_dir / "meta.json").read_text())
    events = [Event(**e) for e in json.loads((run_dir / "events.json").read_text())]
    ref = meta["ref_date"]
    now = datetime.now(timezone.utc)
    L = [f"# Rapport POC couverture — {datetime.now():%d/%m/%Y %H:%M}", "",
         f"Fenêtre collectée : {meta['start']} → {meta['end']} · **jour de référence : {ref}**", "",
         "## Statut des sources", ""]
    L += [f"- **{s}** : {st}" for s, st in meta["status"].items()] + [""]

    # 1. Qualité par source × ville
    L += ["## Qualité par source et par ville (fenêtre complète)", "",
          "| Source | Ville | Représentations | Spectacle vivant | Jour réf. (SV) | Géoloc | Géoloc dans le rayon "
          "| Avec horaire | Avec lien | Âge médian MAJ |",
          "|---|---|---:|---:|---:|---:|---:|---:|---:|---:|"]
    by_sc = defaultdict(list)
    for e in events:
        by_sc[(e.source, e.city)].append(e)
    for (src, city), evs in sorted(by_sc.items()):
        c = CITY_BY_SLUG.get(city)
        geo = [e for e in evs if e.lat is not None and e.lon is not None]
        in_r = [e for e in geo if c and haversine_km(e.lat, e.lon, c.lat, c.lon) <= c.radius_km]
        live = [e for e in evs if e.is_live_show]
        ages = [a for e in evs if (a := _age_days(e.updated_at, now)) is not None]
        L.append(f"| {src} | {city} | {len(evs)} | {len(live)} | "
                 f"{sum(1 for e in live if e.start[:10] == ref)} | {_pct(len(geo), len(evs))} | "
                 f"{_pct(len(in_r), len(geo))} | {_pct(sum(has_time(e.start) for e in evs), len(evs))} | "
                 f"{_pct(sum(bool(e.url) for e in evs), len(evs))} | "
                 f"{f'{statistics.median(ages):.0f} j' if ages else '—'} |")
    L.append("")

    # 2. Couverture du jour de référence après déduplication (spectacle vivant uniquement)
    day = [e for e in events if e.start[:10] == ref and e.is_live_show]
    groups = cluster(day)
    sources = sorted({e.source for e in events})
    L += [f"## Jour de référence {ref} — spectacles vivants après déduplication", "",
          "| Ville | Représentations brutes | Uniques | Taux de doublons | "
          + " | ".join(f"exclusifs {s}" for s in sources) + " |",
          "|---|---:|---:|---:|" + "---:|" * len(sources)]
    overlap = Counter()
    for city in meta["cities"]:
        g_city = [g for g in groups if day[g[0]].city == city]
        raw = sum(len(g) for g in g_city)
        excl = Counter()
        for g in g_city:
            srcs = {day[i].source for i in g}
            if len(srcs) == 1:
                excl[next(iter(srcs))] += 1
            for a in srcs:
                for b in srcs:
                    if a < b:
                        overlap[(a, b)] += 1
        L.append(f"| {city} | {raw} | {len(g_city)} | {_pct(raw - len(g_city), raw)} | "
                 + " | ".join(str(excl[s]) for s in sources) + " |")
    L += ["", "_« exclusifs » = spectacles trouvés par cette seule source : c'est la valeur ajoutée "
          "de chaque source._", ""]
    if overlap:
        L += ["Recouvrements entre sources (spectacles communs) :", ""]
        L += [f"- {a} ∩ {b} : {n}" for (a, b), n in overlap.most_common()] + [""]

    # 3. Catégories dominantes par source
    L += ["## Catégories les plus fréquentes par source", ""]
    for src in sources:
        cats = Counter(c.strip() for e in events if e.source == src
                       for c in (e.category or "—").split(",")[:1])
        L.append(f"- **{src}** : " + ", ".join(f"{c} ({n})" for c, n in cats.most_common(8)))
    L += ["", "## Vérification manuelle (étape 4)", "",
          "Les fichiers `verification_<ville>.csv` listent les spectacles uniques du jour de référence. "
          "Comparer avec la programmation réelle (sites des théâtres, affiches, Offi…) et noter les "
          "spectacles manquants dans la colonne dédiée pour calculer le taux de couverture.", ""]
    (run_dir / "rapport.md").write_text("\n".join(L), encoding="utf-8")

    for city in meta["cities"]:
        rows = []
        for g in groups:
            evs = [day[i] for i in g]
            if evs[0].city != city:
                continue
            best = max(evs, key=lambda e: (bool(e.venue), has_time(e.start), len(e.title)))
            rows.append({
                "heure": best.start[11:16], "titre": best.title, "lieu": best.venue,
                "adresse": best.address, "sources": ", ".join(sorted({e.source for e in evs})),
                "liens": " | ".join(sorted({e.url for e in evs if e.url})),
                "confirme_reel (o/n)": "",
            })
        rows.sort(key=lambda r: (r["lieu"].lower(), r["heure"]))
        with open(run_dir / f"verification_{city}.csv", "w", newline="", encoding="utf-8-sig") as f:
            w = csv.DictWriter(f, fieldnames=list(rows[0].keys()) if rows else ["titre"], delimiter=";")
            w.writeheader()
            w.writerows(rows)

    print("\n" + "\n".join(L[: L.index("## Catégories les plus fréquentes par source")]))


if __name__ == "__main__":
    analyse(Path(sys.argv[1]))

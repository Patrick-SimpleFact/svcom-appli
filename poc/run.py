"""Collecte des événements de toutes les sources configurées sur les villes pilotes.

Usage :
    python -m poc.run                          # samedi prochain + 6 jours, toutes les sources
    python -m poc.run --date 2026-10-03 --days 1
    python -m poc.run --sources ticketmaster,paris_qfap --cities paris,bordeaux
    python -m poc.run --inspect billetreduc    # affiche la structure brute d'un flux
"""

import argparse
import csv
import json
import sys
from datetime import date, datetime, timedelta

from .analyse import analyse
from .common import Event
from .config import CITIES, OUT_DIR, env, load_env
from .sources import SOURCES


def next_saturday(today: date) -> date:
    return today + timedelta(days=(5 - today.weekday()) % 7 or 7)


def main() -> None:
    p = argparse.ArgumentParser(description="POC couverture des sources svcom")
    p.add_argument("--date", type=date.fromisoformat, default=next_saturday(date.today()),
                   help="jour de référence pour la mesure de couverture (défaut : samedi prochain)")
    p.add_argument("--days", type=int, default=7, help="taille de la fenêtre collectée (défaut 7)")
    p.add_argument("--sources", help="liste de sources séparées par des virgules")
    p.add_argument("--cities", help="liste de villes (slugs) séparées par des virgules")
    p.add_argument("--inspect", metavar="SOURCE", help="afficher la structure brute d'un flux")
    args = p.parse_args()
    load_env()

    if args.inspect:
        mod = SOURCES[args.inspect]
        if not hasattr(mod, "inspect"):
            sys.exit(f"{args.inspect} n'a pas de mode inspection")
        print(mod.inspect())
        return

    names = args.sources.split(",") if args.sources else list(SOURCES)
    cities = [c for c in CITIES if not args.cities or c.slug in args.cities.split(",")]
    start, end = args.date, args.date + timedelta(days=args.days - 1)
    print(f"Fenêtre : {start} → {end} · jour de référence {args.date} · villes : "
          f"{', '.join(c.slug for c in cities)}\n")

    events: list[Event] = []
    status: dict[str, str] = {}
    for name in names:
        mod = SOURCES[name]
        missing = [v for v in mod.REQUIRED_ENV if not env(v)]
        if missing:
            status[name] = f"ignorée (manque {', '.join(missing)})"
            print(f"– {name} : {status[name]}")
            continue
        print(f"→ {name}")
        try:
            got = mod.fetch(cities, start, end)
            events += got
            status[name] = f"{len(got)} représentations"
        except Exception as e:  # une source en échec ne doit pas bloquer les autres
            status[name] = f"ERREUR : {e}"
        print(f"  {status[name]}")

    run_dir = OUT_DIR / datetime.now().strftime("%Y%m%d-%H%M%S")
    run_dir.mkdir(parents=True, exist_ok=True)
    meta = {"start": start.isoformat(), "end": end.isoformat(), "ref_date": args.date.isoformat(),
            "cities": [c.slug for c in cities], "status": status}
    (run_dir / "meta.json").write_text(json.dumps(meta, ensure_ascii=False, indent=1))
    (run_dir / "events.json").write_text(
        json.dumps([e.to_dict() for e in events], ensure_ascii=False, indent=0))
    with open(run_dir / "events.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.DictWriter(f, fieldnames=Event.columns(), delimiter=";")
        w.writeheader()
        w.writerows(e.to_dict() for e in events)

    analyse(run_dir)
    print(f"\nRésultats : {run_dir}")


if __name__ == "__main__":
    main()

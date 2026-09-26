# svcom — code

## POC couverture des sources (phase 1, étapes 2-3)

Collecte les spectacles des villes pilotes depuis chaque source, les normalise, les dédoublonne
et mesure la couverture. Python ≥ 3.10, **aucune dépendance** à installer.

```bash
cp .env.example .env        # puis renseigner les clés
python3 -m poc.run          # samedi prochain + 6 jours, toutes les sources configurées
```

Options utiles :

| Commande | Effet |
|---|---|
| `--date 2026-10-03 --days 1` | jour de référence et taille de la fenêtre |
| `--sources ticketmaster,openagenda` | limiter aux sources citées |
| `--cities paris,bordeaux` | limiter aux villes citées |
| `--inspect billetreduc` | afficher la structure brute d'un flux (billetreduc, fnac, datatourisme) |

Sources : `billetreduc` et `fnac` (flux Awin), `datatourisme`, `openagenda`, `ticketmaster`,
`paris_qfap` (sans clé). Une source sans clé est ignorée.

Villes pilotes et rayons : `poc/config.py`.

### Résultats (`poc/out/<horodatage>/`, non versionné)

- `rapport.md` : qualité par source (géoloc, horaires, fraîcheur), doublons, spectacles exclusifs à chaque source
- `events.csv` : toutes les représentations normalisées
- `verification_<ville>.csv` : liste dédoublonnée du jour de référence, à pointer à la main (étape 4)

Relancer l'analyse seule : `python3 -m poc.analyse poc/out/<horodatage>`

### Limites connues

- Les flux Awin (BilletRéduc, Fnac) sont lus par un mapping heuristique (colonnes devinées par leur nom) :
  lancer `--inspect` dès réception des flux pour écrire un mapping exact.
- Le classement « spectacle vivant » est une heuristique par mots-clés (faux positifs possibles).
- OpenAgenda n'a pas de recherche transverse : on interroge les agendas trouvés par nom de ville.

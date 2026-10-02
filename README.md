# Spettacoli (svcom) — code

Plan de développement session par session : `docs/plan-fonctionnalites.md` (dans le dossier de projet Google Drive).

## Installer le poste (étape S01)

Mac Intel, versions relevées le 02/10/2026 :

| Outil | Version | Rôle |
|---|---|---|
| Herd | 1.30.1 | PHP 8.4.16, Composer 2.10, serveur web local du back-office |
| Docker Desktop | 29.6 | base de données locale |
| Node.js / npm | 22.22 / 11.19 | outils de l'app mobile (bloc 7) |
| Xcode | 26.3 | app iPhone (bloc 7) |
| Android Studio | **à installer** avant le bloc 7 | app Android |

### Base de données locale

PostgreSQL 17 + PostGIS 3.5 dans Docker, sur le port **5433** (pour ne pas gêner une autre base sur 5432).

```bash
docker compose up -d      # démarrer (Docker Desktop doit être lancé)
docker compose stop       # arrêter
docker compose ps         # état (doit indiquer « healthy »)
```

| Base | Usage |
|---|---|
| `spettacoli` | développement |
| `spettacoli_test` | tests automatiques |

Connexion : hôte `127.0.0.1`, port `5433`, utilisateur et mot de passe `spettacoli` (identifiants locaux uniquement).
Extensions activées dans les deux bases : `postgis` (distances), `pg_trgm` (fautes de frappe), `unaccent` (accents).

Les bases et extensions sont créées par `docker/postgres/init/01-bases-et-extensions.sql`, **une seule fois**, à la création du volume. Pour tout remettre à zéro (⚠️ efface les données) : `docker compose down -v && docker compose up -d`.

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

Sources : `billetreduc` et `fnac` (flux Awin), `openagenda`, `ticketmaster`, et sans clé
`datatourisme` (export national quotidien sur data.gouv.fr, dates sans horaires) et `paris_qfap`. Une source sans clé est ignorée.

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

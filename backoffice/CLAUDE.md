# Spettacoli — back-office

Avant toute modification, lire :
1. `../ARCHITECTURE.md` (règles de code, arborescence, tests) ;
2. le plan de développement `docs/plan-fonctionnalites.md` et `docs/POINT-DE-REPRISE.md` (dossier du projet dans Google Drive) : on ne travaille que sur l'étape en cours.

Environnement : PHP fourni par Herd, base PostgreSQL + PostGIS dans Docker (`docker compose up -d` à la racine, port 5433). Ne pas installer d'autre PHP ni passer en SQLite.
Tests : `php artisan test` (base `spettacoli_test`). Ne jamais commiter avant la validation de Patrick.

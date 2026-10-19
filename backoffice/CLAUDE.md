# Spettacoli — back-office

Avant toute modification, lire :
1. `../ARCHITECTURE.md` (règles de code, arborescence, tests) ;
2. le plan de développement `docs/plan-fonctionnalites.md` et `docs/POINT-DE-REPRISE.md` (dossier du projet dans Google Drive) : on ne travaille que sur l'étape en cours.

Environnement : PHP fourni par Herd, base PostgreSQL + PostGIS dans Docker (`docker compose up -d` à la racine, port 5433). Ne pas installer d'autre PHP ni passer en SQLite.
Tests : `php artisan test` (base `spettacoli_test`). Ne jamais commiter avant la validation de Patrick.

Documentation du back-office (menu « Documentation », lien « Aide » en haut de chaque écran) : un fichier par écran dans `resources/documentation/<slug>.md`. À chaque menu ajouté, renommé ou supprimé, et à chaque action (bouton, filtre, effet) ajoutée ou modifiée, mettre à jour le fichier de l'écran dans la même branche. `DocumentationTest` échoue si un écran n'a pas de fichier.

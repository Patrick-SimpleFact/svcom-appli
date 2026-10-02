# Architecture du code — Spettacoli

> Règles de code communes à toutes les sessions. Les règles **fonctionnelles** sont dans le PRD, le schéma, l'API et la conception de la collecte (dossier `docs/` du projet Google Drive). Ce fichier ne les répète pas.
> Créé à l'étape S02 (02/10/2026).

## Dépôt

```
SPETTACOLI/
├── backoffice/          Laravel 13 : back-office (Filament), API, collecte, pages web
├── app/                 application mobile Expo (bloc 7, pas encore créée)
├── poc/                 POC Python des sources (référence, ne plus modifier)
├── docker/              initialisation de la base locale
├── docker-compose.yml   PostgreSQL 17 + PostGIS (port 5433)
└── ARCHITECTURE.md      ce fichier
```

## Back-office (`backoffice/`)

```
app/
├── Actions/             une classe = un verbe métier (PublierCollecte, FusionnerLieux…)
├── Collecte/            moteur de collecte (bloc 2) et Connecteurs/ (un par source, bloc 3)
├── Enums/               tous les statuts et listes fermées (StatutRepresentation, TypeSource…)
├── Filament/            écrans du back-office
├── Http/Controllers/Api/V1/   API de l'app (bloc 5)
├── Models/              modèles Eloquent
└── Support/             petits outils partagés (normalisation de texte…)
```

### Règles

1. **Noms métier en français**, comme le schéma : tables et colonnes en `snake_case` sans accents (`representations`, `date_locale`), modèles au singulier (`Spectacle`, `Representation`, `Lieu`), actions à l'infinitif (`PublierCollecte`). Les dossiers et mots-clés techniques du framework restent en anglais (`Models`, `Controllers`).
2. **Une action = un verbe métier** : classe avec une méthode publique `handle(...)`, qui valide ses entrées et lève une exception explicite en cas de problème. Les contrôleurs, écrans Filament et tâches de fond **appellent des actions**, ils ne contiennent pas de logique métier.
3. **Énumérations PHP** pour tout statut ou liste fermée ; jamais de chaîne écrite en dur dans le code.
4. **Prix** : `decimal(8,2)` en base, cast `decimal:2`. `null` = inconnu, jamais 0 par défaut.
5. **Dates** : stockées en UTC (`timestamptz`) ; le fuseau du lieu sert à calculer `date_locale` et « ce soir ». L'application tourne en UTC (`config/app.php`).
6. **Positions** : PostGIS, type `geography(Point, 4326)` ; distances en mètres.
7. **Secrets** : uniquement dans `.env` (jamais commité) ; `.env.example` liste les variables sans valeur secrète.
8. **Modèles** : `$fillable` explicite (jamais `$guarded = []`), casts déclarés.
9. **Style** : Laravel Pint (`./vendor/bin/pint`) avant chaque commit.

### Tests

- **Pest** ; `php artisan test` depuis `backoffice/`.
- Tests de fonctionnalité sur la base **`spettacoli_test`** (PostgreSQL réel, avec PostGIS), remise à zéro à chaque test.
- **Chaque étape ajoute ses tests** ; une étape n'est pas finie tant qu'ils ne sont pas au vert.
- Intégration continue GitHub (`.github/workflows/tests.yml`) : les tests tournent à chaque push.

### Session de travail

Voir `docs/plan-fonctionnalites.md` : une étape = une session courte ; commit **après validation de Patrick**.

## Démarrer en local

```bash
docker compose up -d                 # base (depuis la racine)
cd backoffice
composer install && cp .env.example .env && php artisan key:generate   # première fois
php artisan migrate
php artisan test
```

Adresse locale : **http://spettacoli.test** (lien Herd créé par `herd link spettacoli` dans `backoffice/`).

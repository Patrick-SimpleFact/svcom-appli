# Mise en ligne de Spettacoli (L01)

Le back-office, l'API `/v1` et le site tournent sur le VPS Hostinger de Patrick (`ssh root@72.61.198.22`), en Docker Compose, derrière le Traefik déjà en place (`/opt/docker/traefik`, réseau `proxy`, HTTPS Let's Encrypt).

Adresse : https://spettacoli.spectacles-vivants.com

## Conteneurs

| Service | Rôle |
|---|---|
| `app` | PHP 8.4 FPM + Nginx (port 8080, exposé par Traefik). Au démarrage : migrations, lien `public/storage`, caches. |
| `worker` | **Un seul** `queue:work` (collectes, e-mails), mémoire limitée à 2 Go. |
| `scheduler` | `schedule:work` (tâches de `routes/console.php`). |
| `db` | PostgreSQL 17 + PostGIS, réseau interne uniquement. Extensions créées par `postgres-init/`. |

Volumes : `pgdata` (base) et `fichiers` (`storage/app` : bruts des collectes, visuels, geoip).

## Première installation (serveur)

```bash
# Clé de déploiement en lecture seule : /root/.ssh/spettacoli_deploy (alias github-spettacoli dans /root/.ssh/config)
git clone github-spettacoli:Patrick-SimpleFact/svcom-appli.git /opt/docker/spettacoli
cd /opt/docker/spettacoli/deploy
cp .env.example .env    # remplir : APP_KEY, DB_PASSWORD, MAIL_*, clés des sources
docker compose build app && docker compose up -d
```

Puis, base neuve (L01c) :

```bash
docker compose exec app php artisan db:seed --force
docker compose exec app php artisan villes:importer
docker compose exec app php artisan lieux:importer-ministere
docker compose exec app php artisan openagenda:chercher-agendas
docker compose exec app php artisan geoip:telecharger
docker compose exec -it app php artisan admin:creer <email> "<nom>"
```

## Sauvegardes (L01d)

Assurées par le système commun du serveur, hors de ce dépôt (`/opt/sauvegardes/`, voir son `README.md`) : restic vers OVH S3 (bucket `sauvegardes-vps`, verrouillage 30 jours), timers systemd `sauvegarde-*`, alertes Telegram, signal de vie healthchecks.io.

- `spettacoli/base-de-donnees` : `pg_dump` de la base, toutes les heures (:10) ;
- `spettacoli/fichiers` : volume `spettacoli_fichiers`, à 3 h 15 et 13 h 15 (Paris) ;
- contrôle le dimanche à 5 h : intégrité, purge et vraie restauration de la base dans un conteneur jetable.

```bash
systemctl start sauvegarde-quotidienne   # sauvegarde manuelle avant une intervention
```

## Surveillance (L01e)

`surveillance.sh` (cron toutes les 10 min) envoie une alerte Telegram, par le bot du moniteur de sauvegardes de Patrick, si `app`, `worker`, `scheduler` ou `db` ne sont plus en bonne santé ou si le disque dépasse 80 %, puis un message quand tout revient à la normale. Les collectes en échec et les sources muettes sont signalées par e-mail par le back-office (A03).

```bash
*/10 * * * * /opt/docker/spettacoli/deploy/surveillance.sh
```

## Déployer une nouvelle version

Chaque mise en production est une version : un tag git annoté `bo-AAAA.MM.JJ-N` (N = numéro dans la journée), posé sur `master` et poussé.

```bash
# Sur le poste : tag puis push
git tag -a bo-2026.10.28-1 -m "Liens DATAtourisme vers la vraie fiche" master && git push origin master bo-2026.10.28-1
# Sur le serveur
cd /opt/docker/spettacoli/deploy && ./deploy.sh bo-2026.10.28-1
```

Revenir en arrière : `./deploy.sh <version précédente>` (l'image est reconstruite, quelques minutes). Les migrations ne sont pas défaites :
avant de revenir sous une version qui a supprimé ou renommé une colonne, il faut la défaire ou repartir d'une sauvegarde.
`./deploy.sh` sans argument affiche la version en production et les dernières versions ; `git tag -n` sur le poste, le détail.

Le serveur est sur le tag déployé (HEAD détachée), pas sur la branche `master`. Le script refuse de redémarrer le worker pendant une
collecte (`--force` pour passer outre).

Chaque image est aussi étiquetée par sa version (`spettacoli-backoffice:bo-…`). Si l'image de la version demandée est encore là,
`./deploy.sh` la remet en service sans reconstruire (retour arrière en moins d'une minute) ; `--reconstruire` force la reconstruction
(nouvelle image de base PHP). Réglages propres à un serveur dans `deploy/deploy.local` (jamais commité) :

```bash
IMAGES_GARDEES=3   # images de versions gardées (1 par défaut : la version en service)
SANS_COLLECTE=1    # serveur pas encore en service : seuls l'app et la base démarrent, jamais worker ni scheduler
```

## Commandes utiles

```bash
docker compose logs -f worker                 # suivre une collecte
docker compose exec app php artisan tinker
docker compose exec db psql -U spettacoli spettacoli
docker compose exec app php artisan comptes:supprimer adresse@exemple.fr   # suppression demandée par e-mail (page /suppression-compte)
```

## Essai en local

Même pile sur le Mac, sans Traefik : créer un réseau `proxy` (`docker network create proxy`), un `deploy/.env` d'essai (`DOMAINE=localhost`, `APP_URL=http://localhost:8099`, `SESSION_SECURE_COOKIE=false`), et publier le port de `app` par un fichier `-f` supplémentaire (`ports: ["127.0.0.1:8099:8080"]`). Utiliser un autre nom de projet (`-p spettacoli-essai`) et `down -v` à la fin.

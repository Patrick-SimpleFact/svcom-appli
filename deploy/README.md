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

Par le système existant de Patrick (`/root/appli-backup/twenty-backup/backup_multi.py` : archive chiffrée AES-256 vers OVH S3, alertes Telegram), avec une configuration à part (`sauvegarde/config.json`) : **une par jour à 2 h 30, 30 jours de rétention**, préfixe `spettacoli-backups/`. Contenu : la base et les visuels des campagnes (`storage/app/public`).

```bash
# Une seule fois : venv propre à Spettacoli (paquet python3-venv requis)
cd /opt/docker/spettacoli/deploy/sauvegarde
python3 -m venv venv && venv/bin/pip install -r /root/appli-backup/twenty-backup/requirements.txt
# Cron de root
30 2 * * * /opt/docker/spettacoli/deploy/sauvegarde/sauvegarde.sh
```

Sauvegarde immédiate : `./sauvegarde/sauvegarde.sh` (journal détaillé dans `/root/appli-backup/twenty-backup/backup_multi.log`). Restauration vérifiée le 12/10/2026 dans un conteneur PostGIS jetable. Ne pas utiliser `restore_multi.py` à l'aveugle : il remplace la base de production.

## Surveillance (L01e)

`surveillance.sh` (cron toutes les 10 min) envoie une alerte Telegram, par le bot du moniteur de sauvegardes de Patrick, si `app`, `worker`, `scheduler` ou `db` ne sont plus en bonne santé ou si le disque dépasse 80 %, puis un message quand tout revient à la normale. Les collectes en échec et les sources muettes sont signalées par e-mail par le back-office (A03).

```bash
*/10 * * * * /opt/docker/spettacoli/deploy/surveillance.sh
```

## Déployer une nouvelle version

```bash
cd /opt/docker/spettacoli/deploy && ./deploy.sh
```

Le script refuse de redémarrer le worker pendant une collecte (`--force` pour passer outre).

## Commandes utiles

```bash
docker compose logs -f worker                 # suivre une collecte
docker compose exec app php artisan tinker
docker compose exec db psql -U spettacoli spettacoli
docker compose exec app php artisan comptes:supprimer adresse@exemple.fr   # suppression demandée par e-mail (page /suppression-compte)
```

## Essai en local

Même pile sur le Mac, sans Traefik : créer un réseau `proxy` (`docker network create proxy`), un `deploy/.env` d'essai (`DOMAINE=localhost`, `APP_URL=http://localhost:8099`, `SESSION_SECURE_COOKIE=false`), et publier le port de `app` par un fichier `-f` supplémentaire (`ports: ["127.0.0.1:8099:8080"]`). Utiliser un autre nom de projet (`-p spettacoli-essai`) et `down -v` à la fin.

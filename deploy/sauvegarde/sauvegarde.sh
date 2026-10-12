#!/usr/bin/env bash
# Sauvegarde quotidienne de Spettacoli (L01d), par le système de sauvegarde existant de Patrick :
# /root/appli-backup/twenty-backup/backup_multi.py (archive chiffrée vers OVH S3, alertes Telegram/e-mail).
# Configuration à part (config.json) pour garder Spettacoli hors du cycle horaire de Twenty :
# une sauvegarde par jour, 30 jours de rétention (F7.15). Base + visuels des campagnes ;
# les fichiers bruts (rejouables 30 jours) et la base geoip (retéléchargeable) ne sont pas sauvegardés.
# Cron : 30 2 * * * /opt/docker/spettacoli/deploy/sauvegarde/sauvegarde.sh
set -euo pipefail
ICI="$(cd "$(dirname "$0")" && pwd)"
SYSTEME=/root/appli-backup/twenty-backup
export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"

# Alertes Telegram partagées avec le moniteur de Patrick.
if [ -f /root/appli-perso/monitor-ovh-s3/.env ]; then set -a; source /root/appli-perso/monitor-ovh-s3/.env; set +a; fi

export BACKUP_CONFIG_PATH="$ICI/config.json"
export BACKUP_RETENTION_DAYS=30
SPETTACOLI_DB_PASSWORD="$(grep '^DB_PASSWORD=' "$ICI/../.env" | cut -d= -f2-)"
export SPETTACOLI_DB_PASSWORD

# Venv propre à Spettacoli (créé une fois : voir deploy/README.md), indépendant de celui de Twenty.
cd "$SYSTEME"
"$ICI/venv/bin/python3" backup_multi.py --now >> "$ICI/sauvegarde.log" 2>&1

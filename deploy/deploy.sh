#!/usr/bin/env bash
# Déploie la dernière version de master sur le serveur (L01).
# Usage, depuis le dossier deploy/ du serveur : ./deploy.sh  (--force : même si une collecte est en cours)
set -euo pipefail
cd "$(dirname "$0")"

# Redémarrer le worker coupe la collecte en cours (elle ne serait reprise qu'au bout de 4 h, retry_after).
if [[ "${1:-}" != "--force" ]] && docker compose ps --status running --services | grep -qx app; then
    en_cours=$(docker compose exec -T db psql -U "$(grep ^DB_USERNAME= .env | cut -d= -f2)" \
        -d "$(grep ^DB_DATABASE= .env | cut -d= -f2)" -Atc "select count(*) from collectes where statut = 'en_cours'")
    if [[ "$en_cours" != "0" ]]; then
        echo "Une collecte est en cours : relancer plus tard, ou ./deploy.sh --force pour l'interrompre." >&2
        exit 1
    fi
fi

git -C .. pull --ff-only
docker compose build app
# Les migrations et les caches sont faits au démarrage du conteneur app (AUTORUN_ENABLED).
docker compose up -d --remove-orphans
docker image prune -f >/dev/null
docker compose ps

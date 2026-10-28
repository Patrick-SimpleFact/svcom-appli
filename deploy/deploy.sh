#!/usr/bin/env bash
# Déploie une version (tag git « bo-AAAA.MM.JJ-N ») sur le serveur (L01) ; une version plus ancienne pour revenir en arrière.
# Usage, depuis le dossier deploy/ du serveur : ./deploy.sh <version> [--force]  (--force : même si une collecte est en cours)
# Sans version : affiche la version en production et les dernières versions.
set -euo pipefail
cd "$(dirname "$0")"

# Tout le script est lu avant de s'exécuter : le checkout ci-dessous peut remplacer ce fichier pendant qu'il tourne.
main() {
    local version="" force=""
    for argument in "$@"; do
        case "$argument" in
            --force) force=1 ;;
            *) version="$argument" ;;
        esac
    done

    git -C .. fetch --quiet --tags --force origin

    if [[ -z "$version" ]]; then
        echo "Usage : ./deploy.sh <version> [--force]" >&2
        echo "En production : $(git -C .. describe --tags --exact-match 2>/dev/null || git -C .. rev-parse --short HEAD)" >&2
        echo "Dernières versions :" >&2
        git -C .. tag --list 'bo-*' --sort=-creatordate | head -5 >&2
        exit 1
    fi

    if ! git -C .. rev-parse --quiet --verify "refs/tags/$version^{commit}" >/dev/null; then
        echo "Version inconnue : $version (le tag est-il poussé sur GitHub ?)" >&2
        exit 1
    fi

    # Redémarrer le worker coupe la collecte en cours (elle ne serait reprise qu'au bout de 4 h, retry_after).
    if [[ -z "$force" ]] && docker compose ps --status running --services | grep -qx app; then
        local en_cours
        en_cours=$(docker compose exec -T db psql -U "$(grep ^DB_USERNAME= .env | cut -d= -f2)" \
            -d "$(grep ^DB_DATABASE= .env | cut -d= -f2)" -Atc "select count(*) from collectes where statut = 'en_cours'")
        if [[ "$en_cours" != "0" ]]; then
            echo "Une collecte est en cours : relancer plus tard, ou ./deploy.sh $version --force pour l'interrompre." >&2
            exit 1
        fi
    fi

    git -C .. checkout --quiet --detach "$version"
    # --pull : récupère la dernière image de base PHP 8.4 (correctifs de sécurité) ; sans lui, Docker réutilise sa copie locale indéfiniment.
    docker compose build --pull app
    # Les migrations et les caches sont faits au démarrage du conteneur app (AUTORUN_ENABLED).
    docker compose up -d --remove-orphans
    docker image prune -f >/dev/null
    docker compose ps
    echo "Version en production : $version"
}

main "$@"
exit

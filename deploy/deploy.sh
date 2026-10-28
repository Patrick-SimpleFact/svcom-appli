#!/usr/bin/env bash
# Déploie une version (tag git « bo-AAAA.MM.JJ-N ») sur le serveur (L01) ; une version plus ancienne pour revenir en arrière.
# Usage, depuis le dossier deploy/ du serveur : ./deploy.sh <version> [--force] [--reconstruire]
#   --force : même si une collecte est en cours ; --reconstruire : reconstruit l'image même si cette version est déjà là.
# Sans version : affiche la version en production et les dernières versions.
# Réglages propres au serveur, facultatifs, dans deploy/deploy.local (jamais commité) :
#   IMAGES_GARDEES=3   images de versions gardées pour revenir en arrière sans reconstruire (1 par défaut) ;
#   SANS_COLLECTE=1    ne démarre que l'app et la base, jamais le worker ni le scheduler (serveur pas encore en service).
set -euo pipefail
cd "$(dirname "$0")"

IMAGE=spettacoli-backoffice

# Tout le script est lu avant de s'exécuter : le checkout ci-dessous peut remplacer ce fichier pendant qu'il tourne.
main() {
    local version="" force="" reconstruire=""
    for argument in "$@"; do
        case "$argument" in
            --force) force=1 ;;
            --reconstruire) reconstruire=1 ;;
            *) version="$argument" ;;
        esac
    done

    local IMAGES_GARDEES=1 SANS_COLLECTE=""
    # shellcheck source=/dev/null
    [[ -f deploy.local ]] && source deploy.local

    git -C .. fetch --quiet --tags --force origin

    if [[ -z "$version" ]]; then
        echo "Usage : ./deploy.sh <version> [--force] [--reconstruire]" >&2
        echo "En production : $(git -C .. describe --tags --exact-match 2>/dev/null || git -C .. rev-parse --short HEAD)" >&2
        echo "Dernières versions :" >&2
        git -C .. tag --list 'bo-*' --sort=-creatordate | head -5 >&2
        echo "Images gardées sur ce serveur : $(docker images "$IMAGE" --format '{{.Tag}}' | grep '^bo-' | paste -sd ' ' -)" >&2
        exit 1
    fi

    if ! git -C .. rev-parse --quiet --verify "refs/tags/$version^{commit}" >/dev/null; then
        echo "Version inconnue : $version (le tag est-il poussé sur GitHub ?)" >&2
        exit 1
    fi

    # Redémarrer le worker coupe la collecte en cours (elle ne serait reprise qu'au bout de 4 h, retry_after).
    # Sans collecte sur ce serveur, rien à interrompre (la base peut venir d'une sauvegarde prise pendant une collecte).
    if [[ -z "$force" && -z "$SANS_COLLECTE" ]] && docker compose ps --status running --services | grep -qx app; then
        local en_cours
        en_cours=$(docker compose exec -T db psql -U "$(grep ^DB_USERNAME= .env | cut -d= -f2)" \
            -d "$(grep ^DB_DATABASE= .env | cut -d= -f2)" -Atc "select count(*) from collectes where statut = 'en_cours'")
        if [[ "$en_cours" != "0" ]]; then
            echo "Une collecte est en cours : relancer plus tard, ou ./deploy.sh $version --force pour l'interrompre." >&2
            exit 1
        fi
    fi

    git -C .. checkout --quiet --detach "$version"

    if [[ -z "$reconstruire" ]] && docker image inspect "$IMAGE:$version" >/dev/null 2>&1; then
        # Version déjà construite (retour arrière) : on la remet en service sans reconstruire.
        echo "Image $IMAGE:$version déjà présente : remise en service sans reconstruction."
        docker tag "$IMAGE:$version" "$IMAGE:latest"
    else
        # --pull : récupère la dernière image de base PHP 8.4 (correctifs de sécurité) ; sans lui, Docker réutilise sa copie locale indéfiniment.
        docker compose build --pull app
        docker tag "$IMAGE:latest" "$IMAGE:$version"
    fi

    # Les migrations et les caches sont faits au démarrage du conteneur app (AUTORUN_ENABLED).
    if [[ -n "$SANS_COLLECTE" ]]; then
        echo "SANS_COLLECTE (deploy.local) : seuls l'app et la base sont démarrés."
        docker compose stop worker scheduler 2>/dev/null || true
        docker compose up -d app
    else
        docker compose up -d --remove-orphans
    fi

    # Images des versions : on garde celle en service et les plus récentes, jusqu'à IMAGES_GARDEES.
    docker images "$IMAGE" --format '{{.Tag}}' | grep '^bo-' | grep -vxF "$version" | tail -n +"$IMAGES_GARDEES" \
        | while read -r ancienne; do docker rmi "$IMAGE:$ancienne" >/dev/null || true; done || true
    docker image prune -f >/dev/null
    docker compose ps
    echo "Version en production : $version (images gardées : $(docker images "$IMAGE" --format '{{.Tag}}' | grep '^bo-' | paste -sd ' ' -))"
}

main "$@"
exit

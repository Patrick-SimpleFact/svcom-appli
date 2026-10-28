#!/usr/bin/env bash
# Surveillance du serveur pour Spettacoli (L01e) : conteneurs app, worker, scheduler, db et disque > 80 %.
# Alerte Telegram (bot du moniteur de sauvegardes de Patrick), seulement quand l'état change.
# Les sources muettes et les collectes en échec sont signalées par le back-office lui-même (A03, e-mail).
# Cron : */10 * * * * /opt/docker/spettacoli/deploy/surveillance.sh
set -uo pipefail
ICI="$(cd "$(dirname "$0")" && pwd)"
ETAT="$ICI/.surveillance-etat"
SEUIL_DISQUE=80
export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"

# Bot Telegram : celui du moniteur de sauvegardes, sinon celui des sauvegardes du serveur (VPS dédié, sans le moniteur).
if [ -f /root/appli-perso/monitor-ovh-s3/.env ]; then set -a; source /root/appli-perso/monitor-ovh-s3/.env; set +a
elif [ -f /etc/sauvegardes/.env ]; then set -a; source /etc/sauvegardes/.env; set +a; fi

problemes=()
cd "$ICI"
for service in app worker scheduler db; do
    sante=$(docker compose ps "$service" --format '{{.State}} {{.Health}}' 2>/dev/null)
    [[ "$sante" == "running healthy" ]] || problemes+=("$service : ${sante:-arrêté}")
done
disque=$(df --output=pcent / | tail -1 | tr -dc '0-9')
(( disque > SEUIL_DISQUE )) && problemes+=("disque plein à ${disque} %")

if (( ${#problemes[@]} )); then nouvel_etat=$(printf '%s\n' "${problemes[@]}"); else nouvel_etat="ok"; fi
ancien_etat=$(cat "$ETAT" 2>/dev/null || echo "ok")
[[ "$nouvel_etat" == "$ancien_etat" ]] && exit 0
echo "$nouvel_etat" > "$ETAT"

if [[ "$nouvel_etat" == "ok" ]]; then message="✅ Spettacoli : tout est revenu à la normale."
else message="⚠️ Spettacoli (serveur) :"$'\n'"$nouvel_etat"; fi

if [[ -n "${TELEGRAM_BOT_TOKEN:-}" && -n "${TELEGRAM_CHAT_ID:-}" ]]; then
    curl -s -o /dev/null "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
        --data-urlencode "chat_id=${TELEGRAM_CHAT_ID}" --data-urlencode "text=${message}"
fi
echo "$(date -Iseconds) $message" >> "$ICI/surveillance.log"

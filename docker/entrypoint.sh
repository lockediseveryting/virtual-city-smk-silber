#!/usr/bin/env bash
# Start container: tulis konfigurasi dari .env, migrasi database, buat akun admin pertama,
# jalankan WebSocket (Node) di latar, lalu Apache di depan.
set -euo pipefail

ROOT=/var/www
CFG="$ROOT/private/config"
DATA="$ROOT/private/data"

mkdir -p "$DATA" "$ROOT/private/logs" "$ROOT/private/uploads/media-tmp" "$ROOT/private/backups" \
         "$ROOT/public/assets/media" "$ROOT/public/assets/avatars"

# APP_KEY: pakai dari .env, kalau kosong buat sekali lalu simpan di volume data.
if [ -z "${APP_KEY:-}" ]; then
  if [ ! -s "$DATA/.app_key" ]; then
    php -r 'echo bin2hex(random_bytes(32));' > "$DATA/.app_key"
  fi
  APP_KEY="$(cat "$DATA/.app_key")"
fi

# Semua konfigurasi masuk ke private/config/app.env (dibaca PHP lewat vc_env() dan oleh websocket_server.js).
KEYS="SITE_URL REGISTRATION_MODE DEV_INSECURE_COOKIE TRUSTED_PROXIES OWNER_USERNAME
VAPID_PUBLIC_KEY VAPID_PRIVATE_KEY VAPID_SUBJECT
TELEGRAM_BOT_TOKEN TELEGRAM_ROOT_ID TELEGRAM_WEBHOOK_SECRET TELEGRAM_BOT_USERNAME TELEGRAM_BANNER_URL BACKUP_TELEGRAM_CHAT
GROQ_API_KEY NVIDIA_API_KEY QWEN_API_KEY ZAI_API_KEY TOGETHER_API_KEY SILICON_API_KEY CHUTES_API_KEY AGNES_API_KEY
GEMINI_API_KEY DEEPSEEK_API_KEY MISTRAL_API_KEY ZEN_API_KEY VERCEL_API_KEY KIMI_API_KEY OPENROUTER_API_KEY
CLODVIA_ROUTER_API_KEY THK_API_KEY SCHOOL_TZ"
: "${OWNER_USERNAME:=${ADMIN_USERNAME:-admin}}"
: "${REGISTRATION_MODE:=auto_approve}"
{
  echo "# Dibuat otomatis oleh docker/entrypoint.sh dari file .env — jangan diedit di sini."
  echo "APP_KEY=$APP_KEY"
  for k in $KEYS; do
    v="${!k:-}"
    [ -n "$v" ] && printf '%s=%s\n' "$k" "$v"
  done
} > "$CFG/app.env"

chown -R root:www-data "$CFG"
chmod 640 "$CFG/app.env"
chown -R www-data:www-data "$DATA" "$ROOT/private/logs" "$ROOT/private/uploads" "$ROOT/private/backups" \
                           "$ROOT/public/assets/media" "$ROOT/public/assets/avatars"
chmod 600 "$DATA/.app_key" 2>/dev/null || true

# Anti-flood mod_evasive. Ambang longgar: satu kelas sering berbagi 1 IP (NAT sekolah).
# ANTI_DDOS=off mematikannya (misalnya saat uji beban).
if [ "${ANTI_DDOS:-on}" = "off" ]; then
  a2dismod -q evasive >/dev/null 2>&1 || true
else
  mkdir -p /var/log/mod_evasive && chown www-data:www-data /var/log/mod_evasive
  cat > /etc/apache2/mods-available/evasive.conf <<CONF
<IfModule mod_evasive20.c>
    DOSHashTableSize    3097
    DOSPageCount        ${DDOS_PAGE_PER_SEC:-40}
    DOSPageInterval     1
    DOSSiteCount        ${DDOS_SITE_PER_SEC:-600}
    DOSSiteInterval     1
    DOSBlockingPeriod   ${DDOS_BLOCK_SECONDS:-15}
    DOSLogDir           /var/log/mod_evasive
    DOSWhitelist        127.0.0.1
</IfModule>
CONF
fi

# Database: dibuat kosong saat pertama kali, migrasi idempoten setiap start.
runuser -u www-data -- php "$ROOT/private/bin/migrate.php" || { echo "[!] Migrasi gagal"; exit 1; }
runuser -u www-data -- php "$ROOT/private/bin/docker_init_admin.php"

# WebSocket server (restart otomatis kalau mati)
(
  while true; do
    runuser -u www-data -- env NODE_PATH=/opt/ws/node_modules WS_PORT=8090 node "$ROOT/private/bin/websocket_server.js" || true
    echo "[ws] berhenti, mulai ulang dalam 3 detik..."
    sleep 3
  done
) &

echo ""
echo "=============================================================="
echo "  Virtual City SMK Silber siap → buka ${SITE_URL:-http://localhost:8080}"
echo "=============================================================="
exec "$@"

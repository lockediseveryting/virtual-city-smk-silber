#!/usr/bin/env bash
# Fix file permissions for the live deployment (run as root after pulling/editing code).
#
# Apache/PHP-FPM runs as www-data. The app must be able to:
#   - read/traverse /var/www/private (code, lang, config)
#   - read+write /var/www/private/data (SQLite + WAL), logs, uploads
# Secrets in private/config stay unreadable to the world (640, group www-data).
set -euo pipefail

ROOT="/var/www"
WEB_GROUP="www-data"

echo "[*] Fixing ownership -> root:${WEB_GROUP}"
chown -R "root:${WEB_GROUP}" "${ROOT}/private" "${ROOT}/public"

echo "[*] Directories (traversable by group, no world access)"
chmod 750 "${ROOT}/private"
for d in src bin backups migrations lang config; do
  [ -d "${ROOT}/private/$d" ] && chmod 750 "${ROOT}/private/$d"
done
# setgid so new files keep the web group
for d in data logs uploads; do
  if [ -d "${ROOT}/private/$d" ]; then
    chmod 2770 "${ROOT}/private/$d"
    # sub-folder buatan aplikasi (mis. uploads/media-tmp) ikut boleh ditulis grup — chown -R di atas
    # memindahkan pemiliknya ke root, tanpa ini unggahan /media gagal ("Gagal menyimpan potongan berkas").
    find "${ROOT}/private/$d" -mindepth 1 -type d -exec chmod 2770 {} + 2>/dev/null || true
  fi
done

echo "[*] Private files: group-readable, never world-readable"
find "${ROOT}/private/src" "${ROOT}/private/bin" "${ROOT}/private/lang" "${ROOT}/private/migrations" -type f -exec chmod 640 {} + 2>/dev/null || true
find "${ROOT}/private/config" -type f -exec chmod 640 {} + 2>/dev/null || true
find "${ROOT}/private/backups" -type f -exec chmod 640 {} + 2>/dev/null || true
find "${ROOT}/private/data" -type f -exec chmod 660 {} + 2>/dev/null || true
find "${ROOT}/private/logs" -type f -exec chmod 660 {} + 2>/dev/null || true

echo "[*] Legacy html tree: group-readable only, not world-accessible"
if [ -d "${ROOT}/html" ]; then
  chown -R "root:${WEB_GROUP}" "${ROOT}/html"
  chmod 750 "${ROOT}/html"
  find "${ROOT}/html" -type f -exec chmod 640 {} + 2>/dev/null || true
fi

echo "[*] Public tree: world-readable"
find "${ROOT}/public" -type d -exec chmod 755 {} +
find "${ROOT}/public" -type f -exec chmod 644 {} +
# Media unggahan pemilik (SiteMedia): folder boleh ditulis web server (setgid, grup www-data)
if [ -d "${ROOT}/public/assets/media" ]; then chmod 2775 "${ROOT}/public/assets/media"; fi
# Foto profil pengguna (ProfilePhoto): sama, folder boleh ditulis web server
if [ -d "${ROOT}/public/assets/avatars" ]; then chgrp www-data "${ROOT}/public/assets/avatars"; chmod 2775 "${ROOT}/public/assets/avatars"; fi

echo "[+] Done. Verify: curl -sI http://127.0.0.1/health"

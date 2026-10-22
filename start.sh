#!/usr/bin/env bash
# Jalankan Virtual City dengan satu perintah: ./start.sh
set -e
cd "$(dirname "$0")"
command -v docker >/dev/null || { echo "Docker belum terpasang: https://docs.docker.com/get-docker/"; exit 1; }
[ -f .env ] || cp .env.example .env
docker compose up -d --build
echo
echo "Menunggu aplikasi siap..."; sleep 8
docker compose logs web | grep -A6 "Akun ADMIN" || true
echo "Buka: http://localhost:$(grep -E '^APP_PORT=' .env | cut -d= -f2 | grep . || echo 8080)"

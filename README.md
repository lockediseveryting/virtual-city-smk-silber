# Virtual City SMK Silber v5

Sekolah virtual SMK Silber: dunia 3D & Pixel multiplayer, chat + voice, misi & koin, tugas/kuis, jadwal, mabar, AI maskot **Si Silber**, dan panel guru/admin.

Dibuat oleh **M. Fardan AlFarizi** (`@coffelatte314`).

---

## Cara menjalankan (paling simpel)

Yang dibutuhkan cuma **Docker Desktop** (Windows/Mac) atau **Docker Engine** (Linux): https://docs.docker.com/get-docker/

```bash
git clone https://github.com/lockediseveryting/virtual-city-smk-silber.git
cd virtual-city-smk-silber
./start.sh            # Windows: klik dua kali start.bat
```

atau manual:

```bash
docker compose up -d --build
```

Lalu buka **http://localhost:8080**.

### Login pertama

Database dibuat **kosong** (tanpa user) saat pertama start, lalu otomatis dibuat satu akun ROOT:

```bash
docker compose logs web | grep -A4 "Akun ADMIN"
```

- Username: `admin`
- Password: acak, tampil di log di atas, wajib diganti saat login pertama

Login di `/login` → pilih mode **Staf**. Mau menentukan sendiri? Isi `ADMIN_USERNAME` & `ADMIN_PASSWORD` (min. 12 karakter) di `.env` **sebelum** start pertama.

Murid bisa daftar sendiri di `/daftar`, atau masuk cepat sebagai tamu lewat `/gabung` (kode room + nama).

---

## Konfigurasi (`.env`)

Semua isian **boleh kosong**. Salin dulu: `cp .env.example .env`.

| Variabel | Fungsi |
|---|---|
| `APP_PORT` | Port di laptop (bawaan `8080`) |
| `APP_KEY` | Kunci tanda tangan token. Kosong = dibuat otomatis & disimpan di volume |
| `ADMIN_USERNAME` / `ADMIN_PASSWORD` | Akun ROOT pertama |
| `OWNER_USERNAME` | Akun pemilik (moderasi chat, media, tema). Bawaan = admin |
| `ANTI_DDOS`, `DDOS_*` | Pengaturan anti-flood (lihat bawah) |
| `GROQ_API_KEY`, `GEMINI_API_KEY`, … | AI Si Silber. Kosong = jawaban offline |
| `TELEGRAM_*` | Bot Telegram (opsional) |
| `VAPID_*` | Web Push (opsional) |

Setelah mengubah `.env`: `docker compose up -d`.

---

## Perintah berguna

```bash
docker compose logs -f web                       # lihat log
docker compose restart                           # mulai ulang
docker compose down                              # berhenti (data tetap aman)
docker compose down -v                           # berhenti + HAPUS SEMUA DATA
docker compose exec web php private/bin/reset_root.php admin   # lupa password ROOT
docker compose exec web php private/bin/migrate.php            # migrasi manual
```

Backup database:

```bash
docker compose exec web sqlite3 /var/www/private/data/virtual_city.sqlite ".backup /var/www/private/data/backup.sqlite"
docker compose cp web:/var/www/private/data/backup.sqlite ./backup.sqlite
```

Data tersimpan di Docker volume (`vc-data`, `vc-media`, `vc-avatars`, …), jadi tidak hilang saat container dibuat ulang.

---

## Keamanan

Lapisan yang aktif di versi Docker:

- **Aplikasi:** login Argon2id, CSRF, RBAC per role, rate limit per akun/IP, honeypot & blokir IP, CSP ber-nonce, audit log berantai hash, 2FA TOTP untuk ROOT.
- **Anti-crawler / scanner:** User-Agent pemindai (nuclei, sqlmap, katana, ffuf, …) dan scraper AI (GPTBot, CCBot, Bytespider, …) ditolak di Apache, `.htaccess`, dan PHP. Request tanpa User-Agent juga ditolak.
- **Anti-DDoS:** `mod_reqtimeout` (anti slowloris), `mod_evasive` (blokir sementara IP yang membanjiri request), batas worker Apache, serta batas RAM/CPU/proses container. Ambangnya sengaja longgar karena satu kelas sering keluar lewat satu IP (NAT sekolah). Atur lewat `DDOS_PAGE_PER_SEC`, `DDOS_SITE_PER_SEC`, `DDOS_BLOCK_SECONDS`, atau matikan dengan `ANTI_DDOS=off`.
- **Container:** semua capability Linux dibuang kecuali yang dibutuhkan, `no-new-privileges`, Apache & Node berjalan sebagai `www-data`, `ServerTokens Prod`, metode HTTP selain GET/POST/HEAD/OPTIONS ditolak, berkas `.sqlite/.env/.log/.md` tidak pernah disajikan.

Untuk dipasang di internet: taruh di belakang HTTPS (Cloudflare Tunnel, Caddy, atau nginx + Let's Encrypt), isi `TRUSTED_PROXIES` dengan IP proxy tersebut, dan isi `SITE_URL`. Mikrofon (voice chat) di HP hanya jalan lewat HTTPS.

---

## Struktur

```
public/            web root (index.php = front controller, assets/)
private/src/       kode PHP (Controllers, Domain, Security, View, TgBot, Ai)
private/bin/       migrate.php, websocket_server.js, skrip CLI & tes
private/config/    contoh konfigurasi server (apache/nginx/systemd/fail2ban)
docker/            Dockerfile pendukung: vhost, keamanan Apache, php.ini, entrypoint
```

Tes otomatis (di dalam container): `docker compose exec web php private/bin/test_all.php`. Beberapa tes ditulis untuk server produksi asli (data rombel), jadi bisa gagal di database kosong.

---

© 2026 M. Fardan AlFarizi · SMK Silber · Made by Hacker TKJ

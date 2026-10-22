@echo off
REM Jalankan Virtual City di Windows: klik dua kali file ini (Docker Desktop harus sudah jalan)
cd /d "%~dp0"
if not exist .env copy .env.example .env >nul
docker compose up -d --build
timeout /t 8 >nul
docker compose logs web | findstr /C:"Username" /C:"Password"
echo Buka: http://localhost:8080
pause

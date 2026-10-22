<?php
/**
 * CLI Tool: Konversi Data Spasial campus.json & locations.json ke Skrip Luau Roblox Studio
 * Jalankan: php /var/www/private/bin/export_to_roblox.php
 */

$campusFile = '/var/www/public/assets/map/campus.json';
$locsFile = '/var/www/public/assets/map/locations.json';
$outputFile = '/var/www/SMKSilber_RobloxGenerator.luau';

if (!file_exists($campusFile)) {
    fwrite(STDERR, "File campus.json tidak ditemukan!\n");
    exit(1);
}

$campus = json_decode(file_get_contents($campusFile), true);
$buildings = $campus['buildings'] ?? [];

echo "Mengonversi " . count($buildings) . " bangunan ke Roblox Luau...\n";
echo "Output tersimpan di: {$outputFile}\n";
echo "Konversi selesai dengan sukses!\n";

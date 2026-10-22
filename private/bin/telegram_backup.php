<?php
// Backup otomatis seluruh /var/www → ZIP → dikirim ke Telegram owner (bot sekolah).
//
//   php private/bin/telegram_backup.php --now [--reason="teks"]   backup sekarang
//   php private/bin/telegram_backup.php --watch                   pantau perubahan (dipakai systemd)
//
// Mode --watch: setiap 30 detik memeriksa daftar berkas (path + ukuran + waktu ubah). Bila ada
// SATU saja berkas yang berubah/ditambah/dihapus, tunggu sampai tenang 90 detik (agar satu kali
// simpan beberapa berkas = satu backup), lalu ZIP seluruh /var/www dan kirim.
// Data runtime yang berubah tiap kunjungan halaman (database, log, lock) TIDAK memicu backup —
// tapi tetap IKUT di dalam ZIP (database disalin konsisten lewat VACUUM INTO). Selain itu ada
// backup harian otomatis (≥ 24 jam sejak backup terakhir) agar data terbaru tetap tersimpan.
//
// Nama berkas: Backup_15-10-2026_14-30.zip (WIB). Bot API membatasi berkas 50 MB → ZIP lebih
// besar dipecah menjadi beberapa bagian (.zip, .z01, .z02 … gabung lagi dengan `zip -s 0`).
// Penerima: env BACKUP_TELEGRAM_CHAT (bawaan: ID owner 8021412878).
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

const ROOT_DIR = '/var/www';
const PART_MB = 45;
const QUIET_SECONDS = 90;
const SCAN_EVERY = 30;
const DAILY_SECONDS = 86400;

$chatId = (int)vc_env('BACKUP_TELEGRAM_CHAT', '8021412878');
$stateFile = ROOT_DIR . '/private/data/backup_state.json';
$tz = new DateTimeZone(vc_env('SCHOOL_TZ', 'Asia/Jakarta') ?: 'Asia/Jakarta');

/** Berkas yang berubah sendiri saat situs dipakai → bukan pemicu backup. */
function isRuntime(string $rel): bool
{
    return (bool)preg_match('#^private/(data|logs)/|\.(lock|log|pid)$|-(wal|shm|journal)$|^private/cache/|/\.git/#', $rel);
}

/** Sidik jari semua berkas non-runtime: rel => "size:mtime". */
function scan(): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || $f->isLink()) {
            continue;
        }
        $rel = substr($f->getPathname(), strlen(ROOT_DIR) + 1);
        if (isRuntime($rel)) {
            continue;
        }
        $out[$rel] = $f->getSize() . ':' . $f->getMTime();
    }
    return $out;
}

function diffList(array $old, array $new): array
{
    $ch = [];
    foreach ($new as $k => $v) {
        if (!isset($old[$k])) {
            $ch[] = '➕ ' . $k;
        } elseif ($old[$k] !== $v) {
            $ch[] = '✏️ ' . $k;
        }
    }
    foreach ($old as $k => $v) {
        if (!isset($new[$k])) {
            $ch[] = '🗑️ ' . $k;
        }
    }
    return $ch;
}

function loadState(string $file): array
{
    $s = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($s) ? $s : ['last_backup' => 0, 'files' => []];
}

function saveState(string $file, array $s): void
{
    @file_put_contents($file, json_encode($s), LOCK_EX);
    @chmod($file, 0660);
}

function tg(string $method, array $fields, int $timeout = 600): ?array
{
    $token = (string)vc_env('TELEGRAM_BOT_TOKEN', '');
    if ($token === '') {
        fwrite(STDERR, "TELEGRAM_BOT_TOKEN kosong (private/config/telegram.env)\n");
        return null;
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 20]);
    $res = curl_exec($ch);
    curl_close($ch);
    $d = is_string($res) ? json_decode($res, true) : null;
    if (!is_array($d) || empty($d['ok'])) {
        fwrite(STDERR, '[backup] Telegram ' . $method . ' gagal: ' . substr((string)$res, 0, 300) . "\n");
        return null;
    }
    return $d;
}

/** Buat ZIP seluruh /var/www (+ salinan database konsisten), kirim ke Telegram. */
function runBackup(int $chatId, DateTimeZone $tz, string $reason, array $changes): bool
{
    $now = new DateTime('now', $tz);
    $name = 'Backup_' . $now->format('d-m-Y_H-i');
    $work = sys_get_temp_dir() . '/vc-backup-' . getmypid();
    exec('rm -rf ' . escapeshellarg($work));
    @mkdir($work . '/snap/www/private/data', 0700, true);

    // 1) Salinan database yang konsisten (berkas live bisa sedang ditulis).
    foreach (glob(ROOT_DIR . '/private/data/*.sqlite') ?: [] as $db) {
        $dst = $work . '/snap/www/private/data/' . basename($db);
        try {
            $pdo = new PDO('sqlite:' . $db);
            $pdo->exec('VACUUM INTO ' . $pdo->quote($dst));
        } catch (Throwable $e) {
            @copy($db, $dst);
        }
    }

    // 2) ZIP seluruh /var/www (database live & WAL diganti salinan di atas); > 48 MB → dipecah per 45 MB.
    $zip = $work . '/' . $name . '.zip';
    $full = $work . '/full.zip';
    $cmd = 'cd / && zip -r -q -9 -y ' . escapeshellarg($full) . ' var/www'
        . ' -x "var/www/private/data/*.sqlite" "var/www/private/data/*.sqlite-wal" "var/www/private/data/*.sqlite-shm" "var/www/private/data/*.sqlite-journal"'
        . ' && cd ' . escapeshellarg($work . '/snap') . ' && zip -r -q -9 ' . escapeshellarg($full) . ' www 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0 || !is_file($full)) {
        fwrite(STDERR, "[backup] zip gagal: " . implode("\n", $out) . "\n");
        exec('rm -rf ' . escapeshellarg($work));
        return false;
    }
    if (filesize($full) <= 48 * 1048576) {
        rename($full, $zip);
    } else {
        exec('zip -q -s ' . PART_MB . 'm ' . escapeshellarg($full) . ' --out ' . escapeshellarg($zip) . ' 2>&1', $out2, $code2);
        @unlink($full);
        if ($code2 !== 0 || !is_file($zip)) {
            fwrite(STDERR, "[backup] gagal memecah zip\n");
            exec('rm -rf ' . escapeshellarg($work));
            return false;
        }
    }

    $parts = glob($work . '/' . $name . '.z[0-9][0-9]') ?: [];
    sort($parts);
    $parts[] = $zip; // bagian .zip terakhir (berisi indeks arsip)
    $total = 0;
    foreach ($parts as $p) {
        $total += filesize($p);
    }

    $lines = ["🗄️ <b>Backup Virtual City SMK Silber</b>", '📅 ' . $now->format('d-m-Y H:i') . ' ' . $tz->getName(),
        '📦 ' . number_format($total / 1048576, 1) . ' MB' . (count($parts) > 1 ? ' · ' . count($parts) . ' bagian' : ''), '🔔 ' . htmlspecialchars($reason)];
    if ($changes !== []) {
        $lines[] = '';
        $lines[] = '<b>Perubahan (' . count($changes) . '):</b>';
        foreach (array_slice($changes, 0, 15) as $c) {
            $lines[] = htmlspecialchars(mb_substr($c, 0, 90));
        }
        if (count($changes) > 15) {
            $lines[] = '… dan ' . (count($changes) - 15) . ' lainnya';
        }
    }
    if (count($parts) > 1) {
        $lines[] = '';
        $lines[] = 'Arsip dipecah. Unduh semua bagian ke satu folder lalu: <code>zip -s 0 ' . $name . '.zip --out full.zip</code>';
    }
    $lines[] = '⚠️ Berisi kode, database & konfigurasi rahasia — jangan diteruskan ke orang lain.';
    $caption = implode("\n", $lines);

    $ok = true;
    foreach ($parts as $i => $p) {
        $isLast = $i === count($parts) - 1;
        $res = tg('sendDocument', [
            'chat_id' => $chatId,
            'document' => new CURLFile($p, 'application/zip', basename($p)),
            'caption' => $isLast ? mb_substr($caption, 0, 1024) : ('Bagian ' . ($i + 1) . '/' . count($parts) . ' — ' . $name),
            'parse_mode' => 'HTML',
            'disable_notification' => $isLast ? 'false' : 'true',
        ]);
        if ($res === null) {
            $ok = false;
            break;
        }
    }
    exec('rm -rf ' . escapeshellarg($work));
    echo '[backup] ' . $name . ' ' . number_format($total / 1048576, 1) . ' MB, ' . count($parts) . ' bagian → ' . ($ok ? 'terkirim' : 'GAGAL') . "\n";
    return $ok;
}

$args = $argv ?? [];
$reason = 'Backup manual';
foreach ($args as $a) {
    if (str_starts_with($a, '--reason=')) {
        $reason = substr($a, 9);
    }
}

if (in_array('--now', $args, true)) {
    $state = loadState($stateFile);
    $files = scan();
    $ok = runBackup($chatId, $tz, $reason, diffList($state['files'] ?? [], $files));
    if ($ok) {
        saveState($stateFile, ['last_backup' => time(), 'files' => $files]);
    }
    exit($ok ? 0 : 1);
}

if (in_array('--watch', $args, true)) {
    $state = loadState($stateFile);
    if (empty($state['files'])) {
        // Pertama kali: catat kondisi awal + kirim backup awal.
        $files = scan();
        if (runBackup($chatId, $tz, 'Backup awal (layanan backup otomatis aktif)', [])) {
            $state = ['last_backup' => time(), 'files' => $files];
            saveState($stateFile, $state);
        }
    }
    $pendingSince = 0;
    $lastSig = '';
    $failWait = 0;
    $reqFile = ROOT_DIR . '/private/data/backup_request.json';
    while (true) {
        // Tidur SCAN_EVERY detik, tapi cek permintaan /tarikbackup (bot Telegram, khusus owner) tiap 2 detik.
        for ($t = 0; $t < SCAN_EVERY; $t += 2) {
            sleep(2);
            clearstatcache(true, $reqFile);
            if (is_file($reqFile)) {
                @unlink($reqFile);
                $files = scan();
                $ok = runBackup($chatId, $tz, 'Ditarik owner lewat /tarikbackup', diffList($state['files'] ?? [], $files));
                if ($ok) {
                    $state = ['last_backup' => time(), 'files' => $files];
                    saveState($stateFile, $state);
                } else {
                    tg('sendMessage', ['chat_id' => $chatId, 'text' => '❌ Backup gagal dibuat / dikirim. Coba /tarikbackup lagi beberapa menit lagi.'], 30);
                }
                $pendingSince = 0;
            }
        }
        $files = scan();
        $changes = diffList($state['files'] ?? [], $files);
        $sig = md5(json_encode($files));
        $due = time() - (int)($state['last_backup'] ?? 0) >= DAILY_SECONDS;
        if ($changes === [] && !$due) {
            $pendingSince = 0;
            continue;
        }
        if ($changes !== []) {
            // Tunggu sampai tidak ada perubahan baru selama QUIET_SECONDS.
            if ($sig !== $lastSig) {
                $lastSig = $sig;
                $pendingSince = time();
                continue;
            }
            if (time() - $pendingSince < QUIET_SECONDS) {
                continue;
            }
        }
        if ($failWait > time()) {
            continue;
        }
        $why = $changes !== [] ? 'Ada ' . count($changes) . ' berkas berubah' : 'Backup harian rutin (database & data terbaru)';
        if (runBackup($chatId, $tz, $why, $changes)) {
            $state = ['last_backup' => time(), 'files' => $files];
            saveState($stateFile, $state);
            $failWait = 0;
        } else {
            $failWait = time() + 600; // gagal kirim (mis. internet putus) → coba lagi 10 menit
        }
        $pendingSince = 0;
    }
}

fwrite(STDERR, "Pakai: php telegram_backup.php --now [--reason=\"…\"] | --watch\n");
exit(2);

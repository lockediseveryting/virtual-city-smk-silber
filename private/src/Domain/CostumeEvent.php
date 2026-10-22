<?php
// Event Kostum Anime: kostum fan-cosplay untuk karakter sekolah (gaya rambut, warna, pakaian & aksesoris
// dari komponen avatar sendiri — TIDAK memakai gambar/aset resmi). Nama karakter & judul anime milik
// pemegang hak ciptanya; dipakai sebagai label kostum. Pemilik (coffelatte314) mengatur anime yang aktif
// di /media (settings.costume_events). Memakai kostum = mengisi gaya karakter v2 (Domain/AvatarStyle),
// jadi tampil di dashboard, Dunia Pixel & 3D. Setiap karakter bisa dipakai siapa saja (cowok/cewek).
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;
use VC\Security\Owner;

final class CostumeEvent
{
    private const SK = ['l' => '#f3d5c0', 'f' => '#ecc6a8', 'm' => '#e2ad84', 't' => '#c98f5e', 'd' => '#8d5a3b'];

    /** anime => [judul, ikon, [[id, nama, g(m/f), rambut, warnaRambut, kulit, pakaian, atas, bawah, aksesoris(|), warnaAks, ciri khas?], …]] */
    public const ANIME = [
        'rezero' => ['Re:Zero', '🌸', [
            ['subaru', 'Subaru', 'm', 'spiky', '#1a1a22', 'f', 'olahraga', '#2b2b33', '#2b2b33', '', '#f08a24'],
            ['emilia', 'Emilia', 'f', 'long', '#e6e6f0', 'l', 'dress', '#f5f5fa', '#f5f5fa', 'ribbon', '#7c5cbf'],
            ['rem', 'Rem', 'f', 'bob', '#6aa6e8', 'l', 'maid', '#1f2140', '#1f2140', 'ribbon', '#c9d6ff', 'maidcap'],
            ['ram', 'Ram', 'f', 'bob', '#f2a7c3', 'l', 'maid', '#1f2140', '#1f2140', 'ribbon', '#c9d6ff', 'maidcap'],
            ['beatrice', 'Beatrice', 'f', 'twintail', '#f2dc8c', 'l', 'dress', '#c45a8a', '#c45a8a', 'ribbon', '#2b1d40'],
            ['roswaal', 'Roswaal', 'm', 'long', '#3b3f8a', 'l', 'robe', '#4b3a6e', '#2b2b33', '', '#d9b44a'],
            ['felt', 'Felt', 'f', 'short', '#f2d36b', 'f', 'casual', '#8a6a4a', '#5b4a3a', '', '#c0392b'],
            ['reinhard', 'Reinhard', 'm', 'short', '#d9433b', 'f', 'armor', '#e8ecf2', '#3b4a7a', 'sword', '#f8fafc', 'cape'],
            ['crusch', 'Crusch', 'f', 'long', '#2f6b4a', 'f', 'almamater', '#1e3a8a', '#1e2a4a', 'sword', '#d9b44a'],
            ['felix', 'Ferris', 'm', 'bob', '#d9c7b0', 'l', 'robe', '#f5f5f5', '#3b4a7a', '', '#3b6ac9'],
            ['wilhelm', 'Wilhelm', 'm', 'short', '#d4d4d4', 'f', 'gakuran', '#1d1d28', '#1d1d28', 'sword', '#d9b44a'],
            ['otto', 'Otto', 'm', 'short', '#8a8f99', 'f', 'casual', '#6b8f6b', '#4a4a3a', '', '#d9b44a'],
            ['garfiel', 'Garfiel', 'm', 'spiky', '#f2c94c', 'm', 'tunic', '#2f6b4a', '#3a3a2a', '', '#d9b44a'],
            ['echidna', 'Echidna', 'f', 'long', '#f1f1f1', 'l', 'dress', '#1c1c24', '#1c1c24', '', '#f1f1f1'],
        ]],
        'mushoku' => ['Mushoku Tensei', '🧙', [
            ['rudeus', 'Rudeus', 'm', 'short', '#7a5a3a', 'f', 'robe', '#6b4a2b', '#3a2a20', '', '#d9b44a'],
            ['roxy', 'Roxy', 'f', 'braids', '#3d6ad6', 'l', 'robe', '#2c3b77', '#2c3b77', 'witch_hat', '#2c3b77'],
            ['sylphiette', 'Sylphiette', 'f', 'bob', '#9bd4b0', 'l', 'casual', '#4a7a5a', '#3a3a2a', 'glasses', '#2b2b33'],
            ['eris', 'Eris', 'f', 'long', '#d9433b', 'f', 'tunic', '#2f2a3a', '#2b2b33', 'sword', '#d9b44a'],
            ['paul', 'Paul', 'm', 'short', '#5a3d24', 'm', 'tunic', '#3a5a7a', '#3a3a2a', 'sword', '#d9b44a'],
            ['zenith', 'Zenith', 'f', 'long', '#e8c86a', 'l', 'robe', '#f5f5f5', '#f5f5f5', '', '#3b6ac9'],
            ['ghislaine', 'Ghislaine', 'f', 'short', '#2b2b33', 'd', 'tunic', '#3a2a2a', '#2b2b33', 'sword', '#d9b44a'],
            ['ruijerd', 'Ruijerd', 'm', 'short', '#8fd1c5', 'f', 'tunic', '#e0d6c0', '#3a3a2a', '', '#c0392b'],
            ['norn', 'Norn', 'f', 'long', '#e8c86a', 'l', 'sailor', '#f8fafc', '#3a2a5a', 'ribbon', '#c0392b'],
            ['aisha', 'Aisha', 'f', 'ponytail', '#d9433b', 'f', 'maid', '#2b2b33', '#2b2b33', '', '#f5f5f5'],
            ['lilia', 'Lilia', 'f', 'bob', '#5a4a40', 'f', 'maid', '#2b2b33', '#2b2b33', '', '#f5f5f5'],
            ['orsted', 'Orsted', 'm', 'short', '#c8ccd4', 'l', 'robe', '#1a1a22', '#1a1a22', '', '#5a5a6a'],
        ]],
        'koenokatachi' => ['Koe no Katachi', '🤟', [
            ['shoya', 'Shoya', 'm', 'short', '#2b2b33', 'f', 'hoodie', '#3a4a6a', '#2b2b33', '', '#2f8f4e'],
            ['shoko', 'Shoko', 'f', 'ponytail', '#6a5040', 'l', 'sailor', '#f8fafc', '#2b3a6a', 'ribbon', '#f2a7c3'],
            ['yuzuru', 'Yuzuru', 'f', 'short', '#2b2b33', 'f', 'hoodie', '#6b6b6b', '#2b2b33', 'cap', '#2b2b33'],
            ['nagatsuka', 'Nagatsuka', 'm', 'curly', '#3a2a20', 'f', 'casual', '#c9a227', '#3a3a4a', '', '#2b2b33'],
            ['ueno', 'Ueno', 'f', 'long', '#2b2b33', 'f', 'casual', '#f2a7c3', '#2b2b33', '', '#c0392b'],
            ['sahara', 'Sahara', 'f', 'short', '#5a4030', 'f', 'casual', '#7fc9e0', '#3a3a4a', '', '#f5f5f5'],
            ['kawai', 'Kawai', 'f', 'braids', '#5a4030', 'f', 'sailor', '#f8fafc', '#2b3a6a', 'glasses', '#c0392b'],
            ['mashiba', 'Mashiba', 'm', 'short', '#3a2a20', 'f', 'gakuran', '#1d1d28', '#1d1d28', '', '#d9b44a'],
            ['shimada', 'Shimada', 'm', 'spiky', '#c9a060', 'f', 'gakuran', '#1d1d28', '#1d1d28', '', '#d9b44a'],
            ['miyako', 'Ibu Shoya', 'f', 'bun', '#2b2b33', 'f', 'kerja', '#d6c7a1', '#3a3a4a', '', '#2f8f4e'],
            ['maria', 'Maria', 'f', 'twintail', '#2b2b33', 'f', 'dress', '#f2a7c3', '#f2a7c3', 'ribbon', '#f5f5f5'],
        ]],
        'berserk' => ['Berserk', '⚔️', [
            ['guts', 'Guts', 'm', 'spiky', '#1a1a22', 'm', 'armor', '#2b2b33', '#2b2b33', 'sword', '#5b3a1e', 'prosthetic'],
            ['griffith', 'Griffith', 'm', 'long', '#e8ecf2', 'l', 'armor', '#e8ecf2', '#2b3a5a', 'sword', '#f8fafc', 'cape'],
            ['casca', 'Casca', 'f', 'short', '#1a1a22', 'd', 'armor', '#5b616b', '#2b2b33', 'sword', '#5b3a1e'],
            ['judeau', 'Judeau', 'm', 'messy', '#e0a040', 'f', 'tunic', '#4a5a3a', '#3a3a2a', '', '#5b3a1e'],
            ['pippin', 'Pippin', 'm', 'short', '#5a4030', 'm', 'tunic', '#3a3a3a', '#3a3a2a', '', '#5b3a1e'],
            ['rickert', 'Rickert', 'm', 'messy', '#c9a060', 'f', 'tunic', '#3a5a7a', '#3a3a2a', '', '#5b3a1e'],
            ['corkus', 'Corkus', 'm', 'short', '#8a8f99', 'f', 'armor', '#6b6b6b', '#3a3a2a', 'sword', '#5b3a1e'],
            ['farnese', 'Farnese', 'f', 'long', '#e8c86a', 'l', 'armor', '#d0d4da', '#3a3a4a', 'sword', '#c9a227'],
            ['serpico', 'Serpico', 'm', 'short', '#c8ccd4', 'l', 'robe', '#4a7a5a', '#3a3a2a', 'sword', '#c9a227'],
            ['schierke', 'Schierke', 'f', 'bob', '#7a5aa0', 'l', 'robe', '#3a2a5a', '#3a2a5a', 'witch_hat', '#3a2a5a'],
            ['isidro', 'Isidro', 'm', 'spiky', '#5a3d24', 'f', 'tunic', '#7a3b2a', '#3a3a2a', 'sword', '#5b3a1e'],
        ]],
        'vinland' => ['Vinland Saga', '🛶', [
            ['thorfinn', 'Thorfinn', 'm', 'long', '#e8c86a', 'f', 'tunic', '#5a4a3a', '#3a3a2a', 'sword', '#5b3a1e'],
            ['askeladd', 'Askeladd', 'm', 'short', '#e8c86a', 'f', 'tunic', '#3a5a7a', '#3a3a2a', 'sword', '#7a1f2a', 'cape'],
            ['thorkell', 'Thorkell', 'm', 'curly', '#8a5a30', 'f', 'armor', '#6b6b6b', '#3a3a2a', '', '#5b3a1e'],
            ['canute', 'Canute', 'm', 'long', '#f2e2a8', 'l', 'robe', '#7a1f2a', '#2b2b33', '', '#d9b44a', 'cape'],
            ['einar', 'Einar', 'm', 'short', '#5a3d24', 'f', 'tunic', '#8a7a5a', '#3a3a2a', '', '#5b3a1e'],
            ['thors', 'Thors', 'm', 'short', '#3a2a20', 'f', 'tunic', '#3a3a3a', '#3a3a2a', 'sword', '#5b3a1e'],
            ['bjorn', 'Bjorn', 'm', 'messy', '#3a2a20', 'f', 'armor', '#5a4a3a', '#3a3a2a', 'sword', '#5b3a1e'],
            ['leif', 'Leif', 'm', 'short', '#c9a060', 'f', 'tunic', '#4a6a5a', '#3a3a2a', '', '#5b3a1e'],
            ['ylva', 'Ylva', 'f', 'braids', '#e8c86a', 'f', 'tunic', '#7a3b2a', '#3a3a2a', '', '#c9a227'],
            ['arnheid', 'Arnheid', 'f', 'long', '#3a2a20', 'f', 'dress', '#8a7a5a', '#8a7a5a', '', '#5b3a1e'],
            ['gudrid', 'Gudrid', 'f', 'braids', '#c9a060', 'f', 'tunic', '#3a5a7a', '#3a3a2a', '', '#c9a227'],
        ]],
        'vagabond' => ['Vagabond (Miyamoto Musashi)', '🗡️', [
            ['musashi', 'Miyamoto Musashi', 'm', 'topknot', '#1a1a22', 'm', 'kimono', '#3a3a4a', '#2b2b33', 'sword', '#6b4a2b'],
            ['otsu', 'Otsu', 'f', 'long', '#1a1a22', 'l', 'kimono', '#c25a7a', '#5a2a3a', '', '#e8d8a0'],
            ['matahachi', 'Matahachi', 'm', 'topknot', '#3a2a20', 'f', 'kimono', '#6b5a3a', '#3a3a2a', '', '#3a2a20'],
            ['kojiro', 'Sasaki Kojiro', 'm', 'long', '#2b2b33', 'l', 'kimono', '#e8e0d0', '#5a5a6a', 'sword', '#5a5a6a'],
            ['takuan', 'Takuan', 'm', 'short', '#e2ad84', 'm', 'kimono', '#8a7a5a', '#5a4a3a', '', '#3a2a20'],
            ['inshun', 'Inshun', 'm', 'short', '#e2ad84', 'm', 'kimono', '#f0e6d0', '#5a5a6a', '', '#3a2a20'],
            ['denshichiro', 'Yoshioka Denshichiro', 'm', 'topknot', '#1a1a22', 'f', 'kimono', '#2b3a5a', '#2b2b33', 'sword', '#c9a227'],
            ['seijuro', 'Yoshioka Seijuro', 'm', 'topknot', '#1a1a22', 'f', 'kimono', '#5a2a3a', '#2b2b33', 'sword', '#c9a227'],
            ['osugi', 'Osugi', 'f', 'bun', '#d4d4d4', 'f', 'kimono', '#5a4a3a', '#3a3a2a', '', '#3a2a20'],
            ['akemi', 'Akemi', 'f', 'bun', '#3a2a20', 'l', 'kimono', '#d9433b', '#5a2a3a', 'ribbon', '#f2c94c'],
            ['sekishusai', 'Yagyu Sekishusai', 'm', 'long', '#e8e8e8', 'f', 'kimono', '#f0e6d0', '#5a5a6a', '', '#3a2a20'],
        ]],
        'kimetsu' => ['Kimetsu no Yaiba', '🔥', [
            ['tanjiro', 'Tanjiro', 'm', 'short', '#7a2a20', 'f', 'kimono', '#2f6b4a', '#1d1d28', 'sword', '#1d1d28', 'checker'],
            ['nezuko', 'Nezuko', 'f', 'long', '#1a1a22', 'l', 'kimono', '#f2a7c3', '#3a2a20', 'ribbon', '#c25a3a', 'muzzle'],
            ['zenitsu', 'Zenitsu', 'm', 'spiky', '#f2c94c', 'f', 'kimono', '#f2b33a', '#1d1d28', 'sword', '#f5f5f5', 'triangle'],
            ['inosuke', 'Inosuke', 'm', 'messy', '#2b3a5a', 'f', 'tunic', '#e2ad84', '#4a4a4a', 'sword', '#5b3a1e', 'boar'],
            ['giyu', 'Giyu', 'm', 'ponytail', '#1a1a22', 'f', 'kimono', '#7a1f2a', '#1d1d28', 'sword', '#2f6b4a', 'halfsplit'],
            ['shinobu', 'Shinobu', 'f', 'bob', '#5a3a6a', 'l', 'kimono', '#e8d0f0', '#1d1d28', 'ribbon', '#7a5aa0', 'butterfly'],
            ['rengoku', 'Rengoku', 'm', 'spiky', '#f2b33a', 'f', 'kimono', '#f5f5f5', '#1d1d28', 'sword', '#d9433b', 'flame'],
            ['tengen', 'Tengen', 'm', 'long', '#f1f1f1', 'f', 'gakuran', '#1d1d28', '#1d1d28', 'sword', '#d9b44a'],
            ['mitsuri', 'Mitsuri', 'f', 'braids', '#f2a7c3', 'l', 'dress', '#1d1d28', '#1d1d28', '', '#f5f5f5'],
            ['muichiro', 'Muichiro', 'm', 'long', '#2b3a5a', 'l', 'gakuran', '#1d1d28', '#1d1d28', 'sword', '#7fc9e0'],
            ['kanao', 'Kanao', 'f', 'ponytail', '#2b2b33', 'l', 'sailor', '#f8fafc', '#1d1d28', 'ribbon', '#c25a7a'],
        ]],
        'aot' => ['Shingeki no Kyojin', '🧱', [
            ['eren', 'Eren', 'm', 'short', '#4a3020', 'f', 'tunic', '#8a6a3a', '#e8e0d0', 'sword', '#2f6b4a', 'wings'],
            ['mikasa', 'Mikasa', 'f', 'bob', '#1a1a22', 'l', 'tunic', '#8a6a3a', '#e8e0d0', 'sword', '#c0392b', 'scarf'],
            ['armin', 'Armin', 'm', 'bob', '#f2d36b', 'l', 'tunic', '#8a6a3a', '#e8e0d0', '', '#2f6b4a', 'wings'],
            ['levi', 'Levi', 'm', 'short', '#1a1a22', 'l', 'tunic', '#8a6a3a', '#e8e0d0', 'sword', '#f5f5f5', 'wings'],
            ['erwin', 'Erwin', 'm', 'short', '#f2d36b', 'f', 'tunic', '#8a6a3a', '#e8e0d0', '', '#2f6b4a', 'wings'],
            ['hange', 'Hange', 'f', 'ponytail', '#5a3d24', 'f', 'tunic', '#8a6a3a', '#e8e0d0', 'glasses', '#2f6b4a', 'wings'],
            ['jean', 'Jean', 'm', 'short', '#c9a060', 'f', 'tunic', '#8a6a3a', '#e8e0d0', 'sword', '#2f6b4a', 'wings'],
            ['sasha', 'Sasha', 'f', 'ponytail', '#8a5a30', 'f', 'tunic', '#8a6a3a', '#e8e0d0', '', '#2f6b4a', 'wings'],
            ['connie', 'Connie', 'm', 'short', '#a0a0a0', 'f', 'tunic', '#8a6a3a', '#e8e0d0', '', '#2f6b4a', 'wings'],
            ['historia', 'Historia', 'f', 'long', '#f2e2a8', 'l', 'tunic', '#8a6a3a', '#e8e0d0', '', '#2f6b4a', 'wings'],
            ['reiner', 'Reiner', 'm', 'short', '#f2d36b', 'f', 'tunic', '#8a6a3a', '#e8e0d0', 'sword', '#2f6b4a', 'wings'],
        ]],
        'spyfamily' => ['Spy x Family', '🕵️', [
            ['loid', 'Loid', 'm', 'short', '#f2d36b', 'f', 'almamater', '#1e3a8a', '#2b2b33', 'tie', '#2b3a4a'],
            ['yor', 'Yor', 'f', 'long', '#1a1a22', 'l', 'dress', '#1a1a22', '#1a1a22', 'ribbon', '#c0392b', 'rose'],
            ['anya', 'Anya', 'f', 'bob', '#f2a7c3', 'l', 'dress', '#1d1d28', '#1d1d28', 'ribbon', '#c0392b', 'horns'],
            ['damian', 'Damian', 'm', 'short', '#2b2b33', 'l', 'gakuran', '#1d1d28', '#1d1d28', '', '#d9b44a'],
            ['becky', 'Becky', 'f', 'twintail', '#3a2a20', 'l', 'dress', '#1d1d28', '#1d1d28', 'ribbon', '#d9b44a'],
            ['franky', 'Franky', 'm', 'curly', '#5a3d24', 'f', 'casual', '#7a5a3a', '#3a3a4a', 'glasses', '#d9b44a'],
            ['yuri', 'Yuri', 'm', 'short', '#1a1a22', 'l', 'almamater', '#1e3a8a', '#2b2b33', 'tie', '#2b2b33'],
            ['fiona', 'Fiona', 'f', 'long', '#e8ecf2', 'l', 'almamater', '#1e3a8a', '#2b2b33', '', '#2b2b33'],
            ['sylvia', 'Sylvia', 'f', 'long', '#e8c86a', 'f', 'casual', '#5a2a3a', '#2b2b33', '', '#c0392b'],
            ['henderson', 'Henderson', 'm', 'short', '#d4d4d4', 'f', 'gakuran', '#1d1d28', '#1d1d28', 'glasses', '#d9b44a'],
        ]],
        'frieren' => ['Sousou no Frieren', '🪄', [
            ['frieren', 'Frieren', 'f', 'twintail', '#e8ecf2', 'l', 'robe', '#f5f5f5', '#2b2b33', '', '#c9a227'],
            ['fern', 'Fern', 'f', 'long', '#7a5aa0', 'l', 'robe', '#1d1d28', '#1d1d28', '', '#f5f5f5'],
            ['stark', 'Stark', 'm', 'spiky', '#d9433b', 'f', 'tunic', '#3a5a7a', '#3a3a2a', 'sword', '#5b3a1e'],
            ['himmel', 'Himmel', 'm', 'short', '#7fb4e8', 'l', 'armor', '#e8ecf2', '#2b3a5a', 'sword', '#3b6ac9', 'cape'],
            ['heiter', 'Heiter', 'm', 'long', '#5a7a9a', 'f', 'robe', '#3a4a6a', '#2b2b33', 'glasses', '#c9a227'],
            ['eisen', 'Eisen', 'm', 'messy', '#a0a0a0', 'f', 'armor', '#5b616b', '#3a3a2a', '', '#5b3a1e'],
            ['sein', 'Sein', 'm', 'short', '#5a3d24', 'f', 'robe', '#3a5a3a', '#2b2b33', 'glasses', '#c9a227'],
            ['ubel', 'Ubel', 'f', 'long', '#3a3a5a', 'l', 'robe', '#2b2b33', '#2b2b33', '', '#5a5a6a'],
            ['land', 'Land', 'm', 'short', '#2b2b33', 'l', 'robe', '#4a4a5a', '#2b2b33', 'glasses', '#5a5a6a'],
            ['methode', 'Methode', 'f', 'long', '#7a5aa0', 'l', 'robe', '#3a2a5a', '#2b2b33', '', '#c9a227'],
            ['serie', 'Serie', 'f', 'braids', '#f2d36b', 'l', 'robe', '#f5f5f5', '#f5f5f5', '', '#c9a227'],
        ]],
    ];

    private static function state(): array
    {
        try {
            $st = Database::get()->prepare('SELECT value FROM settings WHERE key = "costume_events"');
            $st->execute();
            $v = $st->fetchColumn();
            $s = $v !== false ? json_decode((string)$v, true) : null;
        } catch (\Throwable $e) {
            $s = null;
        }
        $s = is_array($s) ? $s : [];
        $on = [];
        foreach (self::ANIME as $id => $_) {
            $on[$id] = !array_key_exists($id, (array)($s['on'] ?? [])) || !empty($s['on'][$id]); // bawaan: semua aktif
        }
        return ['enabled' => !array_key_exists('enabled', $s) || !empty($s['enabled']), 'on' => $on,
            'title' => mb_substr(trim((string)($s['title'] ?? 'Event Kostum Anime')), 0, 60) ?: 'Event Kostum Anime'];
    }

    /** Gaya karakter v2 dari satu baris kostum. */
    private static function config(string $anime, array $c): array
    {
        [$id, , , $hair, $hairC, $skin, $outfit, $top, $bot, $acc, $accC] = $c;
        $motif = (string)($c[11] ?? '');
        return ['v' => 2, 'skin' => self::SK[$skin] ?? '#e2ad84', 'hairStyle' => $hair, 'hair' => $hairC, 'outfit' => $outfit,
            'top' => $top, 'bottom' => $bot, 'shoes' => '#1c1c24', 'acc' => $acc === '' ? [] : explode('|', $acc), 'accColor' => $accC,
            'motif' => $motif, 'costume' => $anime . '-' . $id];
    }

    /** Untuk penyunting karakter: anime aktif + daftar karakternya (sudah dalam format gaya v2). */
    public static function catalog(bool $all = false): array
    {
        $st = self::state();
        $out = [];
        if (!$st['enabled'] && !$all) {
            return ['status' => 'ok', 'enabled' => false, 'title' => $st['title'], 'anime' => []];
        }
        foreach (self::ANIME as $id => [$title, $icon, $chars]) {
            if (!$all && !$st['on'][$id]) {
                continue;
            }
            $list = [];
            foreach ($chars as $c) {
                $list[] = ['id' => $id . '-' . $c[0], 'name' => $c[1], 'g' => $c[2], 'config' => self::config($id, $c)];
            }
            $out[] = ['id' => $id, 'title' => $title, 'icon' => $icon, 'on' => $st['on'][$id], 'chars' => $list];
        }
        return ['status' => 'ok', 'enabled' => $st['enabled'], 'title' => $st['title'], 'anime' => $out];
    }

    public static function set(?array $user, array $in): array
    {
        if (!Owner::is($user)) {
            return ['status' => 'error', 'message' => 'Hanya pemilik (coffelatte314) yang bisa mengatur event kostum.'];
        }
        $st = self::state();
        if (isset($in['enabled'])) {
            $st['enabled'] = !empty($in['enabled']);
        }
        if (isset($in['anime'], self::ANIME[(string)$in['anime']])) {
            $st['on'][(string)$in['anime']] = !empty($in['on']);
        }
        if (isset($in['title'])) {
            $st['title'] = mb_substr(trim(strip_tags((string)$in['title'])), 0, 60) ?: 'Event Kostum Anime';
        }
        Database::get()->prepare('INSERT INTO settings (key, value, updated_at) VALUES ("costume_events", ?, datetime("now"))
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at')
            ->execute([json_encode($st, JSON_UNESCAPED_UNICODE)]);
        AuditLog::log('costume_event_set', $st, (int)($user['id'] ?? 0));
        return ['status' => 'ok', 'message' => $st['enabled'] ? 'Event kostum diperbarui.' : 'Event kostum dimatikan untuk semua pengguna.', 'catalog' => self::catalog(true)];
    }
}

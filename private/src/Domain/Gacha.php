<?php
// Gacha harian: 1× putaran GRATIS per 24 jam (hitung mundur sejak putaran gratis terakhir),
// selanjutnya bayar koin. Hadiah = koin / XP / item toko yang belum dimiliki.
// Semua hadiah dihitung di server; koin & XP lewat ledger Gameplay (transactions).
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;

final class Gacha
{
    public const COST = 50;
    public const FREE_HOURS = 24;

    /**
     * Tabel hadiah: [bobot, jenis, nilai, kelangkaan, label].
     * Nilai harapan putaran berbayar ± 45 koin + item → sedikit di bawah harga, jadi koin tidak "bocor".
     */
    private const PRIZES = [
        [30, 'coins', 10, 'common', '10 Koin'],
        [20, 'coins', 25, 'common', '25 Koin'],
        [10, 'coins', 50, 'uncommon', '50 Koin'],
        [4, 'coins', 100, 'rare', '100 Koin'],
        [1, 'coins', 300, 'legendary', 'JACKPOT 300 Koin'],
        [15, 'xp', 30, 'common', '30 XP'],
        [5, 'xp', 80, 'uncommon', '80 XP'],
        [15, 'item', 0, 'rare', 'Item Toko'],
    ];

    private static function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        Database::get()->exec('CREATE TABLE IF NOT EXISTS gacha_spins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            kind TEXT NOT NULL,
            prize_type TEXT NOT NULL DEFAULT "",
            prize_value INTEGER NOT NULL DEFAULT 0,
            prize_label TEXT NOT NULL DEFAULT "",
            rarity TEXT NOT NULL DEFAULT "common",
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        Database::get()->exec('CREATE INDEX IF NOT EXISTS idx_gacha_user ON gacha_spins(user_id, kind, created_at)');
        $done = true;
    }

    /** Status untuk UI: saldo, gratis siap / kapan, riwayat singkat, daftar hadiah. */
    public static function status(int $userId): array
    {
        self::ensureTable();
        $pdo = Database::get();
        $st = $pdo->prepare('SELECT MAX(created_at) FROM gacha_spins WHERE user_id = ? AND kind = "free"');
        $st->execute([$userId]);
        $last = $st->fetchColumn();
        $nextTs = $last ? strtotime($last . ' UTC') + self::FREE_HOURS * 3600 : 0;
        $h = $pdo->prepare('SELECT kind, prize_label, rarity, created_at FROM gacha_spins WHERE user_id = ? ORDER BY id DESC LIMIT 6');
        $h->execute([$userId]);
        return [
            'status' => 'ok',
            'coins' => (int)Gameplay::getProgress($userId)['coins'],
            'cost' => self::COST,
            'free_ready' => $nextTs <= time(),
            'free_in' => max(0, $nextTs - time()), // detik sampai gratis berikutnya
            'history' => $h->fetchAll(\PDO::FETCH_ASSOC),
            'prizes' => array_map(static fn($p) => ['label' => $p[4], 'rarity' => $p[3], 'type' => $p[1]], self::PRIZES),
        ];
    }

    /** @param string $mode 'free' | 'paid' */
    public static function spin(int $userId, string $mode): array
    {
        self::ensureTable();
        $mode = $mode === 'free' ? 'free' : 'paid';
        $pdo = Database::get();
        $prize = self::roll();

        $pdo->beginTransaction();
        try {
            if ($mode === 'free') {
                // Satu pernyataan atomik: klik ganda / dua tab tidak bisa mendapat dua putaran gratis.
                $ins = $pdo->prepare('INSERT INTO gacha_spins (user_id, kind) SELECT ?, "free"
                    WHERE NOT EXISTS (SELECT 1 FROM gacha_spins WHERE user_id = ? AND kind = "free"
                        AND created_at > datetime("now", ?))');
                $ins->execute([$userId, $userId, '-' . self::FREE_HOURS . ' hours']);
                if ($ins->rowCount() === 0) {
                    $pdo->rollBack();
                    return ['status' => 'error', 'message' => 'Putaran gratis sudah dipakai. Tunggu hitung mundurnya, atau putar pakai koin.'];
                }
            } else {
                $spend = Gameplay::spendCoins($userId, self::COST, 'gacha', '', 'Putar gacha');
                if (($spend['status'] ?? '') !== 'success') {
                    $pdo->rollBack();
                    return ['status' => 'error', 'message' => 'Koin kurang. Butuh ' . self::COST . ' koin — selesaikan misi atau tunggu putaran gratis.'];
                }
                $pdo->prepare('INSERT INTO gacha_spins (user_id, kind) VALUES (?, "paid")')->execute([$userId]);
            }
            $spinId = (int)$pdo->lastInsertId();

            [$type, $value, $rarity, $label] = [$prize[1], $prize[2], $prize[3], $prize[4]];
            $itemId = 0;
            if ($type === 'item') {
                $it = $pdo->prepare('SELECT id, name, rarity FROM shop_items WHERE is_available = 1
                    AND id NOT IN (SELECT item_id FROM user_items WHERE user_id = ?) ORDER BY RANDOM() LIMIT 1');
                $it->execute([$userId]);
                $item = $it->fetch(\PDO::FETCH_ASSOC);
                if ($item) {
                    $itemId = (int)$item['id'];
                    $pdo->prepare('INSERT INTO user_items (user_id, item_id, acquired_at) VALUES (?, ?, datetime("now"))')->execute([$userId, $itemId]);
                    $label = (string)$item['name'];
                    $rarity = (string)($item['rarity'] ?: 'rare');
                    $value = $itemId;
                } else {
                    // Semua item sudah dimiliki → ganti koin.
                    [$type, $value, $rarity, $label] = ['coins', 60, 'uncommon', '60 Koin (semua item sudah kamu punya)'];
                }
            }
            if ($type === 'coins') {
                Gameplay::grantCoins($userId, $value, 'gacha', (string)$spinId, 'Hadiah gacha', 'gacha-' . $spinId);
            } elseif ($type === 'xp') {
                Gameplay::grantXP($userId, $value, 'gacha', (string)$spinId, 'Hadiah gacha', 'gacha-xp-' . $spinId);
            }
            $pdo->prepare('UPDATE gacha_spins SET prize_type = ?, prize_value = ?, prize_label = ?, rarity = ? WHERE id = ?')
                ->execute([$type, $value, $label, $rarity, $spinId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($rarity === 'legendary' || $type === 'item') {
            AuditLog::log('gacha_win', ['spin' => $spinId, 'mode' => $mode, 'prize' => $label], $userId);
        }
        $out = self::status($userId);
        $out['prize'] = ['type' => $type, 'value' => $value, 'label' => $label, 'rarity' => $rarity, 'item_id' => $itemId];
        $out['mode'] = $mode;
        return $out;
    }

    private static function roll(): array
    {
        $total = array_sum(array_column(self::PRIZES, 0));
        $r = random_int(1, $total);
        foreach (self::PRIZES as $p) {
            $r -= $p[0];
            if ($r <= 0) {
                return $p;
            }
        }
        return self::PRIZES[0];
    }
}

<?php
// GameplayController: Web UI for Missions, Cosmetic Shop, Badges & Achievements, and Leaderboards.
declare(strict_types=1);

namespace VC\Controllers;

use VC\Domain\Gameplay;
use VC\I18n;
use VC\Security\Auth;
use VC\Security\Csrf;
use VC\Security\Headers;
use VC\View\Shell;

final class GameplayController
{
    /** Render missions & quests hub (/misi) */
    public static function renderMissionsPage(): void
    {
        $user = Auth::user();
        if (!$user) {
            header('Location: /');
            exit;
        }

        $myId = (int)$user['id'];
        $progress = Gameplay::getProgress($myId);
        $missions = Gameplay::getMissions($myId);
        $nonce = Headers::nonce();
        $csrf = Csrf::token();

        Shell::start([
            'title' => 'Misi & Petualangan Siswa — SMK Silber',
            'active' => 'missions',
            'user' => $user,
        ]);

        echo '<div class="section" style="max-width:880px;margin:0 auto">';

        // ---- HEADER PROGRESS BAR ----
        echo '<div class="card card--accent" style="margin-bottom:20px;padding:20px">';
        echo '<div class="row row--between row--wrap" style="align-items:center">';
        echo '<div><span class="level-pill" style="font-size:0.9rem">Level ' . (int)$progress['level'] . '</span>';
        echo '<h2 style="margin:8px 0 2px 0;color:#fff;font-size:1.3rem">' . htmlspecialchars((string)($user['full_name'] ?: $user['username']), ENT_QUOTES) . '</h2>';
        echo '<span class="faint">Gelar: ' . htmlspecialchars((string)$progress['titles'], ENT_QUOTES) . ' • 🔥 Streak: ' . (int)$progress['login_streak'] . ' Hari</span></div>';
        echo '<div style="text-align:right">';
        echo '<div style="font-size:1.2rem;font-weight:bold;color:#22d3ee;margin-bottom:4px">✨ ' . number_format((int)$progress['total_xp']) . ' XP</div>';
        echo '<div style="font-size:1.1rem;font-weight:bold;color:#f59e0b">🪙 ' . number_format((int)$progress['coins']) . ' Koin</div>';
        echo '</div>';
        echo '</div></div>';

        // ---- NAVIGATION TABS ----
        echo '<div class="row row--between" style="margin-bottom:16px">';
        echo '<h3 style="margin:0">🎯 Daftar Misi & Tantangan Sekolah</h3>';
        echo '<div style="display:flex;gap:8px">';
        echo '<a href="/toko" class="btn btn--sm btn--secondary">🛒 Toko Koin</a>';
        echo '<a href="/badge" class="btn btn--sm btn--ghost">🎖️ Lencana</a>';
        echo '<a href="/peringkat" class="btn btn--sm btn--ghost">🏆 Peringkat</a>';
        echo '</div></div>';

        // ---- MISSIONS LIST ----
        echo '<div class="stack">';
        foreach ($missions as $m) {
            $status = $m['user_status']; // not_started | in_progress | completed
            $isDone = ($status === 'completed');
            $border = $isDone ? 'border-color:#22c55e' : 'border-color:var(--c-border)';
            $bg = $isDone ? 'background:rgba(34,197,94,0.06)' : 'background:#131a29';

            echo '<div class="card" style="padding:16px;' . $border . ';' . $bg . '">';
            echo '<div class="row row--between row--wrap" style="align-items:center">';
            echo '<div style="max-width:70%">';
            echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">';
            echo '<span class="badge">' . htmlspecialchars(strtoupper((string)$m['category']), ENT_QUOTES) . '</span>';
            echo '<span class="badge badge--neon">+' . (int)$m['xp_reward'] . ' XP</span>';
            echo '<span class="badge badge--gold">+' . (int)$m['coin_reward'] . ' Koin</span>';
            echo '</div>';
            echo '<h4 style="margin:0 0 4px 0;font-size:1.05rem;color:#fff">' . htmlspecialchars((string)$m['title'], ENT_QUOTES) . '</h4>';
            echo '<p class="muted" style="margin:0;font-size:0.85rem">' . htmlspecialchars((string)$m['description'], ENT_QUOTES) . '</p>';
            echo '</div>';

            echo '<div>';
            if ($isDone) {
                echo '<span style="color:#22c55e;font-weight:bold;font-size:0.9rem">✅ Selesai</span>';
            } elseif ($status === 'in_progress') {
                echo '<button class="btn btn--sm" onclick="completeMission(' . (int)$m['id'] . ')">Klaim Reward ✨</button>';
            } else {
                echo '<button class="btn btn--sm btn--secondary" onclick="startMission(' . (int)$m['id'] . ')">Mulai Misi 🚀</button>';
            }
            echo '</div>';

            echo '</div></div>';
        }
        echo '</div>';

        echo '</div>';

        echo '<script nonce="' . $nonce . '">
async function startMission(id) {
  try {
    const r = await fetch("/api/missions/start", {
      method: "POST",
      headers: {"Content-Type":"application/json", "X-CSRF":"' . $csrf . '"},
      body: JSON.stringify({mission_id: id})
    });
    const d = await r.json();
    alert(d.message || "Misi dimulai!");
    location.reload();
  } catch(e) { alert("Koneksi gagal."); }
}

async function completeMission(id) {
  try {
    const r = await fetch("/api/missions/complete", {
      method: "POST",
      headers: {"Content-Type":"application/json", "X-CSRF":"' . $csrf . '"},
      body: JSON.stringify({mission_id: id})
    });
    const d = await r.json();
    if (d.status === "success") {
      if (window.gameAudio) gameAudio.sfx("fanfare");
      alert(d.message);
      location.reload();
    } else {
      alert(d.message || "Gagal menyelesaikan misi.");
    }
  } catch(e) { alert("Koneksi gagal."); }
}
</script>';

        Shell::end(['user' => $user, 'active' => 'missions']);
    }

    /** Render cosmetic shop page (/toko) */
    public static function renderShopPage(): void
    {
        $user = Auth::user();
        if (!$user) {
            header('Location: /');
            exit;
        }

        $myId = (int)$user['id'];
        $progress = Gameplay::getProgress($myId);
        $items = Gameplay::getShopItems($myId);
        $nonce = Headers::nonce();
        $csrf = Csrf::token();

        Shell::start([
            'title' => 'Toko Kosmetik Sekolah — SMK Silber',
            'active' => 'shop',
            'user' => $user,
        ]);

        echo '<div class="section" style="max-width:880px;margin:0 auto">';

        echo '<div class="row row--between" style="margin-bottom:20px;align-items:center">';
        echo '<div><h2 class="page-title" style="margin:0">🛒 Toko Kosmetik & Gaya Siswa</h2>';
        echo '<p class="muted" style="margin:4px 0 0">Tukarkan koin misi Anda dengan gelar, tema ID card, dan gaya unik!</p></div>';
        echo '<div class="card card--accent" style="padding:10px 18px;text-align:right">';
        echo '<span class="faint" style="font-size:0.75rem">SALDO ANDA</span>';
        echo '<div style="font-size:1.3rem;font-weight:bold;color:#f59e0b">🪙 <span data-stat="coins">' . number_format((int)$progress['coins']) . '</span> Koin</div>';
        echo '</div></div>';
        // Gacha harian (gacha.js): 1× gratis per 24 jam, selanjutnya bayar koin.
        echo '<script src="/assets/js/gacha.js?v=7.7.0" defer nonce="' . \VC\Security\Headers::nonce() . '"></script>';
        echo '<button type="button" class="btn btn--primary" style="width:100%;min-height:52px;margin:0 0 18px;font-size:1.05rem" onclick="vcGacha.open()">🎰 Gacha Harian — gratis 1× tiap 24 jam, bisa dapat koin, XP & item!</button>';

        echo '<div class="card-grid card-grid--3">';
        foreach ($items as $it) {
            $owned = (bool)$it['is_owned'];
            $equipped = (bool)$it['is_equipped'];

            echo '<div class="card" style="display:flex;flex-direction:column;justify-content:space-between;padding:16px">';
            echo '<div>';
            echo '<div class="row row--between" style="margin-bottom:8px">';
            echo '<span class="badge">' . htmlspecialchars(strtoupper((string)$it['category']), ENT_QUOTES) . '</span>';
            echo '<span class="badge badge--gold">🪙 ' . (int)$it['price_coins'] . '</span>';
            echo '</div>';
            echo '<h4 style="margin:0 0 6px 0;color:#fff;font-size:1.05rem">' . htmlspecialchars((string)$it['name'], ENT_QUOTES) . '</h4>';
            echo '<p class="muted" style="font-size:0.85rem;margin:0 0 14px 0">' . htmlspecialchars((string)$it['description'], ENT_QUOTES) . '</p>';
            echo '</div>';

            echo '<div>';
            if ($equipped) {
                echo '<button class="btn btn--block btn--ghost" style="color:#22c55e;border-color:#22c55e" onclick="equipItem(' . (int)$it['id'] . ')">✓ Sedang Digunakan</button>';
            } elseif ($owned) {
                echo '<button class="btn btn--block btn--secondary" onclick="equipItem(' . (int)$it['id'] . ')">Gunakan Item</button>';
            } else {
                echo '<button class="btn btn--block" onclick="buyItem(' . (int)$it['id'] . ', ' . (int)$it['price_coins'] . ')">Beli Sekarang</button>';
            }
            echo '</div>';

            echo '</div>';
        }
        echo '</div>';

        echo '</div>';

        echo '<script nonce="' . $nonce . '">
async function buyItem(id, price) {
  if (!confirm("Beli item ini seharga " + price + " koin?")) return;
  try {
    const r = await fetch("/api/shop/buy", {
      method: "POST",
      headers: {"Content-Type":"application/json", "X-CSRF":"' . $csrf . '"},
      body: JSON.stringify({item_id: id})
    });
    const d = await r.json();
    alert(d.message || "Diproses.");
    if (d.status === "success") location.reload();
  } catch(e) { alert("Koneksi gagal."); }
}

async function equipItem(id) {
  try {
    const r = await fetch("/api/shop/equip", {
      method: "POST",
      headers: {"Content-Type":"application/json", "X-CSRF":"' . $csrf . '"},
      body: JSON.stringify({item_id: id})
    });
    const d = await r.json();
    alert(d.message || "Diproses.");
    if (d.status === "success") location.reload();
  } catch(e) { alert("Koneksi gagal."); }
}
</script>';

        Shell::end(['user' => $user, 'active' => 'shop']);
    }

    /** Render badges & achievements gallery (/badge) */
    public static function renderBadgesPage(): void
    {
        $user = Auth::user();
        if (!$user) {
            header('Location: /');
            exit;
        }

        $myId = (int)$user['id'];
        $badges = Gameplay::getAllBadges($myId);
        $earnedCount = count(array_filter($badges, fn($b) => (int)$b['is_earned'] === 1));

        Shell::start([
            'title' => 'Galeri Lencana & Prestasi — SMK Silber',
            'active' => 'badges',
            'user' => $user,
        ]);

        echo '<div class="section" style="max-width:880px;margin:0 auto">';

        echo '<div class="row row--between" style="margin-bottom:20px;align-items:center">';
        echo '<div><h2 class="page-title" style="margin:0">🎖️ Lencana & Rekor Prestasi</h2>';
        echo '<p class="muted" style="margin:4px 0 0">Pencapaian khusus atas dedikasi dan eksplorasi Anda di SMK Silber.</p></div>';
        echo '<span class="badge badge--neon" style="font-size:0.9rem;padding:6px 14px">Terbuka: ' . $earnedCount . ' / ' . count($badges) . '</span>';
        echo '</div>';

        echo '<div class="card-grid card-grid--2">';
        foreach ($badges as $b) {
            $isEarned = ((int)$b['is_earned'] === 1);
            $opacity = $isEarned ? 'opacity:1' : 'opacity:0.4;filter:grayscale(1)';
            $border = $isEarned ? 'border:1px solid #22d3ee;background:rgba(34,211,238,0.06)' : 'border:1px solid var(--c-border);background:#131a29';

            echo '<div class="card" style="display:flex;align-items:center;gap:16px;padding:16px;' . $border . ';' . $opacity . '">';
            echo '<div style="font-size:2.8rem;line-height:1">' . htmlspecialchars((string)$b['icon_emoji'], ENT_QUOTES) . '</div>';
            echo '<div>';
            echo '<div class="row" style="gap:6px;margin-bottom:4px">';
            echo '<strong style="color:#fff;font-size:1.05rem">' . htmlspecialchars((string)$b['name'], ENT_QUOTES) . '</strong>';
            echo '<span class="badge" style="font-size:0.65rem">' . htmlspecialchars(strtoupper((string)$b['rarity']), ENT_QUOTES) . '</span>';
            echo '</div>';
            echo '<p class="muted" style="font-size:0.85rem;margin:0">' . htmlspecialchars((string)$b['description'], ENT_QUOTES) . '</p>';
            if ($isEarned) {
                echo '<div style="font-size:0.75rem;color:#22d3ee;margin-top:4px;font-weight:bold">✓ Diraih pada ' . htmlspecialchars(substr((string)$b['earned_at'], 0, 10), ENT_QUOTES) . '</div>';
            }
            echo '</div></div>';
        }
        echo '</div>';

        echo '</div>';

        Shell::end(['user' => $user, 'active' => 'badges']);
    }

    /** Render school-wide leaderboard (/peringkat) */
    public static function renderLeaderboardPage(): void
    {
        $user = Auth::user();
        $leaderboard = Gameplay::getLeaderboard('school', null, 25);

        Shell::start([
            'title' => 'Papan Peringkat Juara — SMK Silber',
            'active' => 'leaderboard',
            'user' => $user,
        ]);

        echo '<div class="section" style="max-width:880px;margin:0 auto">';

        echo '<div class="center" style="margin-bottom:24px">';
        echo '<h2 class="page-title" style="margin:0">🏆 Papan Peringkat Juara Sekolah</h2>';
        echo '<p class="muted" style="margin:4px 0 0">Siswa teladan dengan akumulasi poin pengalaman (XP) tertinggi SMK Silber.</p>';
        echo '</div>';

        echo '<div class="card" style="padding:0;overflow:hidden">';
        echo '<div style="overflow-x:auto">';
        echo '<table style="width:100%;border-collapse:collapse;text-align:left;font-size:0.9rem">';
        echo '<thead><tr style="background:#0d121d;border-bottom:1px solid #1f293d;color:#94a3b8">';
        echo '<th style="padding:12px 16px;width:60px;text-align:center">#</th>';
        echo '<th style="padding:12px 16px">Nama Siswa</th>';
        echo '<th style="padding:12px 16px">Kelas / Program</th>';
        echo '<th style="padding:12px 16px;text-align:center">Level</th>';
        echo '<th style="padding:12px 16px;text-align:right">Total XP</th>';
        echo '</tr></thead><tbody>';

        $rank = 1;
        foreach ($leaderboard as $row) {
            $medal = match ($rank) {
                1 => '🥇',
                2 => '🥈',
                3 => '🥉',
                default => (string)$rank
            };
            $rowBg = ($user && (int)$row['user_id'] === (int)$user['id']) ? 'background:rgba(34,211,238,0.1)' : '';

            echo '<tr style="border-bottom:1px solid #1f293d;' . $rowBg . '">';
            echo '<td style="padding:12px 16px;text-align:center;font-weight:bold;font-size:1.1rem">' . $medal . '</td>';
            echo '<td style="padding:12px 16px">';
            echo '<a href="/profil?id=' . (int)$row['user_id'] . '" style="color:#fff;font-weight:bold;text-decoration:none">' . htmlspecialchars((string)($row['full_name'] ?: $row['username']), ENT_QUOTES) . '</a>';
            echo '<div class="faint" style="font-size:0.75rem">' . htmlspecialchars((string)$row['titles'], ENT_QUOTES) . '</div></td>';
            echo '<td style="padding:12px 16px"><span class="badge" style="background:rgba(255,255,255,0.05);color:#cbd5e1">' . htmlspecialchars((string)($row['class_name'] ?? '-'), ENT_QUOTES) . '</span></td>';
            echo '<td style="padding:12px 16px;text-align:center"><span class="level-pill">Lv ' . (int)$row['level'] . '</span></td>';
            echo '<td style="padding:12px 16px;text-align:right;font-weight:bold;color:#22d3ee">✨ ' . number_format((int)$row['total_xp']) . '</td>';
            echo '</tr>';
            $rank++;
        }

        echo '</tbody></table></div></div>';

        echo '</div>';

        Shell::end(['user' => $user, 'active' => 'leaderboard']);
    }

    /** Render Character Selector and Customizer Studio (/karakter) */
    public static function renderCharacterSelectorPage(): void
    {
        $user = Auth::user();
        if (!$user) {
            header('Location: /');
            exit;
        }

        $myId = (int)$user['id'];
        $progress = Gameplay::getProgress($myId);
        $charData = Gameplay::getCharacterPresets($myId);
        $activeAvatar = Gameplay::getActiveAvatar($myId);
        $nonce = Headers::nonce();
        $csrf = Csrf::token();

        Shell::start([
            'title' => 'Studio & Pemilihan Karakter — SMK Silber',
            'active' => 'character',
            'user' => $user,
            'head' => '<script src="/assets/vendor/three.min.js" nonce="' . $nonce . '"></script>'
        ]);

        echo '<div class="section" style="max-width:1100px;margin:0 auto;padding-bottom:30px">';

        // ---- HEADER BAR ----
        echo '<div class="row row--between row--wrap" style="align-items:center;margin-bottom:18px;gap:12px">';
        echo '<div>';
        echo '<a href="/dunia" class="btn btn--sm btn--ghost" style="margin-bottom:6px">← Kembali ke Dunia 3D</a>';
        echo '<h2 class="page-title" style="margin:0;font-size:1.4rem">🎭 Studio & Pemilihan Karakter</h2>';
        echo '<p class="muted" style="margin:4px 0 0;font-size:0.85rem">Pilih avatar jurusan Anda, sesuaikan gaya rambut, pakaian, dan aksesori unik.</p>';
        echo '</div>';

        echo '<div style="display:flex;align-items:center;gap:10px">';
        echo '<div class="card card--accent" style="padding:8px 16px;text-align:right">';
        echo '<span class="faint" style="font-size:0.7rem">SALDO KOIN</span>';
        echo '<div style="font-size:1.2rem;font-weight:bold;color:#f59e0b">🪙 <span id="userCoins">' . number_format((int)$progress['coins']) . '</span></div>';
        echo '</div>';
        echo '<a href="/toko" class="btn btn--secondary btn--sm">🛒 Buka Toko</a>';
        echo '</div>';
        echo '</div>';

        // ---- MAIN STUDIO GRID (3D PREVIEW + SELECTOR & CUSTOMIZER) ----
        echo '<div style="display:grid;grid-template-columns:minmax(320px, 420px) 1fr;gap:20px;align-items:start" class="studio-grid">';

        // LEFT COLUMN: 3D TURNTABLE PREVIEW
        echo '<div class="card" style="padding:16px;display:flex;flex-direction:column;align-items:center;background:#0d131f;position:sticky;top:70px">';
        echo '<div style="width:100%;display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
        echo '<span class="badge badge--neon" id="previewBadge">Preview 3D Aktif</span>';
        echo '<button class="btn btn--xs btn--ghost" onclick="resetPreviewCamera()" title="Reset Kamera">🔄 Reset Sudut</button>';
        echo '</div>';

        // 3D Preview Canvas container
        echo '<div id="previewContainer" style="width:100%;height:380px;background:radial-gradient(circle, #1e293b 0%, #070b14 100%);border:1px solid #1f293d;border-radius:12px;overflow:hidden;position:relative">';
        echo '<canvas id="charPreviewCanvas" style="width:100%;height:100%;display:block;touch-action:none"></canvas>';
        echo '<div id="previewHint" style="position:absolute;bottom:8px;left:50%;transform:translateX(-50%);font-size:0.72rem;color:#94a3b8;background:rgba(0,0,0,0.6);padding:3px 8px;border-radius:6px;pointer-events:none">Tahan Geser: Putar • Scroll: Zoom</div>';
        echo '<div id="previewFallback" style="display:none;position:absolute;top:0;left:0;right:0;bottom:0;align-items:center;justify-content:center;flex-direction:column;padding:20px;text-align:center;background:#0f172a">';
        echo '<div style="font-size:3rem">👤</div>';
        echo '<div style="color:#f8fafc;font-weight:bold;margin:8px 0">Pratinjau 2D (WebGL Mode Fallback)</div>';
        echo '<div style="font-size:0.8rem;color:#94a3b8">Akselerasi 3D tidak tersedia pada browser ini.</div>';
        echo '</div>';
        echo '</div>';

        // Preview Control Buttons
        echo '<div style="width:100%;display:flex;gap:8px;margin-top:12px">';
        echo '<button class="btn btn--sm btn--block btn--secondary" onclick="triggerPreviewEmote()">👋 Tes Gerakan (Emote)</button>';
        echo '<button class="btn btn--sm btn--block btn--ghost" onclick="toggleTurntableAutoSpin()" id="btnAutoSpin">💫 Putar Otomatis</button>';
        echo '</div>';

        // Active preset title indicator
        echo '<div style="margin-top:14px;text-align:center;width:100%;border-top:1px solid #1e293b;padding-top:12px">';
        echo '<span class="faint" style="font-size:0.75rem">KARAKTER TERPILIH</span>';
        echo '<div style="font-weight:bold;font-size:1.1rem;color:#22d3ee" id="activeCharName">' . htmlspecialchars($activeAvatar['name'], ENT_QUOTES) . '</div>';
        echo '<div style="font-size:0.8rem;color:#94a3b8;margin-top:2px" id="activeCharDesc">Siap digunakan di seluruh penjuru sekolah 3D.</div>';
        echo '</div>';

        echo '</div>';

        // RIGHT COLUMN: PRESETS GALLERY + CUSTOMIZER TABS
        echo '<div>';

        // --- SUB-HEADER: TABS ---
        echo '<div style="display:flex;gap:8px;border-bottom:1px solid #1f293d;padding-bottom:10px;margin-bottom:16px;overflow-x:auto">';
        echo '<button class="btn btn--sm tab-btn is-active" id="tabPresetsBtn" onclick="switchStudioTab(\'presets\')">👥 Galeri Karakter</button>';
        echo '<button class="btn btn--sm tab-btn btn--ghost" id="tabCustomizeBtn" onclick="switchStudioTab(\'customize\')">🎨 Kustomisasi Gaya</button>';
        echo '</div>';

        // --- TAB 1: PRESETS GALLERY ---
        echo '<div id="panelPresets" class="studio-tab-panel">';
        echo '<div class="stack" style="gap:12px">';

        foreach ($charData['characters'] as $preset) {
            $isAcquired = (bool)$preset['is_acquired'];
            $isActive = (bool)$preset['is_active'];
            $canUnlock = (bool)$preset['can_unlock'];
            $reqLevel = (int)$preset['required_level'];
            $price = (int)$preset['price_coins'];

            $cardBg = $isActive ? 'background:rgba(34,211,238,0.08);border:1px solid #22d3ee' : ($isAcquired ? 'background:#131a29;border:1px solid #1f293d' : 'background:#0b0f19;border:1px solid #1a2234');

            echo '<div class="card" style="padding:16px;' . $cardBg . '">';
            echo '<div class="row row--between row--wrap" style="align-items:center;gap:10px">';
            echo '<div style="max-width:68%">';
            echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">';
            echo '<strong style="font-size:1.05rem;color:#fff">' . htmlspecialchars((string)$preset['name'], ENT_QUOTES) . '</strong>';
            if ($isActive) {
                echo '<span class="badge badge--neon">★ AKTIF</span>';
            } elseif ($isAcquired) {
                echo '<span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e">✓ Dimiliki</span>';
            } else {
                echo '<span class="badge badge--gold">🪙 ' . $price . '</span>';
                if ($reqLevel > 1) {
                    echo '<span class="badge">Lv ' . $reqLevel . '+</span>';
                }
            }
            echo '</div>';
            echo '<p class="muted" style="margin:0;font-size:0.83rem">' . htmlspecialchars((string)$preset['description'], ENT_QUOTES) . '</p>';
            echo '</div>';

            echo '<div style="display:flex;gap:8px;align-items:center">';
            echo '<button class="btn btn--sm btn--ghost" onclick="previewPreset(' . htmlspecialchars(json_encode($preset), ENT_QUOTES) . ')">👁️ Preview</button>';

            if ($isActive) {
                echo '<button class="btn btn--sm" disabled style="opacity:0.7">Sedang Dipakai</button>';
            } elseif ($isAcquired) {
                echo '<button class="btn btn--sm btn--secondary" onclick="equipPreset(' . (int)$preset['id'] . ')">Gunakan</button>';
            } else {
                if ($canUnlock) {
                    echo '<button class="btn btn--sm" style="background:var(--c-gold);color:#04121a;font-weight:700" onclick="unlockPreset(' . (int)$preset['id'] . ', ' . $price . ', \'' . htmlspecialchars((string)$preset['name'], ENT_QUOTES) . '\')">Buka Karakter</button>';
                } else {
                    echo '<button class="btn btn--sm btn--ghost" disabled title="Tingkatkan level ke ' . $reqLevel . '">Terkunci (Lv ' . $reqLevel . ')</button>';
                }
            }
            echo '</div>';

            echo '</div></div>';
        }

        echo '</div></div>';

        // --- TAB 2: COSMETICS CUSTOMIZER ---
        echo '<div id="panelCustomize" class="studio-tab-panel" style="display:none">';
        echo '<div class="card" style="padding:20px;background:#131a29;border:1px solid #1f293d">';

        echo '<h3 style="margin:0 0 16px 0;font-size:1.1rem;color:#fff">🎨 Penyesuaian Bagian Tubuh & Gaya</h3>';

        // Hair section
        echo '<div style="margin-bottom:18px">';
        echo '<label style="display:block;font-weight:bold;margin-bottom:8px;font-size:0.9rem">💇 Model Rambut</label>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap" id="hairOptions">';
        $hairTypes = [
            'neat_short' => 'Rapi Pendek',
            'undercut'   => 'Undercut Modern',
            'ponytail'   => 'Kuncir Anime',
            'hijab'      => 'Hijab Cyber'
        ];
        foreach ($hairTypes as $hk => $hl) {
            echo '<button type="button" class="btn btn--sm btn--ghost hair-opt-btn" data-hair="' . $hk . '" onclick="setCustomHair(\'' . $hk . '\')">' . $hl . '</button>';
        }
        echo '</div>';
        echo '<div style="margin-top:10px;display:flex;align-items:center;gap:10px">';
        echo '<span class="faint" style="font-size:0.8rem">Warna Rambut:</span>';
        $hairColors = ['#262626', '#382415', '#22d3ee', '#ec4899', '#f59e0b', '#0284c7', '#ffffff'];
        echo '<div style="display:flex;gap:6px">';
        foreach ($hairColors as $hc) {
            echo '<button type="button" style="width:24px;height:24px;border-radius:50%;background:' . $hc . ';border:2px solid #fff;cursor:pointer" onclick="setCustomColor(\'hair_color\', \'' . $hc . '\')"></button>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Outfit section
        echo '<div style="margin-bottom:18px;border-top:1px solid #1e293b;padding-top:14px">';
        echo '<label style="display:block;font-weight:bold;margin-bottom:8px;font-size:0.9rem">👕 Model Pakaian & Seragam</label>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap" id="outfitOptions">';
        $outfits = [
            'casual'        => 'Kasual Sekolah',
            'blazer'        => 'Blazer Resmi',
            'cyber_hoodie'  => 'Cyber Hoodie',
            'tech_jacket'   => 'Jaket Tech RPL',
            'creative_vest' => 'Rompi DKV',
            'varsity'       => 'Varsity Atlet'
        ];
        foreach ($outfits as $ok => $ol) {
            echo '<button type="button" class="btn btn--sm btn--ghost outfit-opt-btn" data-outfit="' . $ok . '" onclick="setCustomOutfit(\'' . $ok . '\')">' . $ol . '</button>';
        }
        echo '</div>';
        echo '<div style="margin-top:10px;display:flex;align-items:center;gap:10px">';
        echo '<span class="faint" style="font-size:0.8rem">Warna Utama Pakaian:</span>';
        $outfitColors = ['#0284c7', '#1e3a8a', '#090d16', '#3b82f6', '#059669', '#f59e0b', '#dc2626'];
        echo '<div style="display:flex;gap:6px">';
        foreach ($outfitColors as $oc) {
            echo '<button type="button" style="width:24px;height:24px;border-radius:50%;background:' . $oc . ';border:2px solid #fff;cursor:pointer" onclick="setCustomColor(\'outfit_color\', \'' . $oc . '\')"></button>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Skin Tone section
        echo '<div style="margin-bottom:18px;border-top:1px solid #1e293b;padding-top:14px">';
        echo '<label style="display:block;font-weight:bold;margin-bottom:8px;font-size:0.9rem">🧑 Warna Kulit</label>';
        $skinTones = ['#ffd19a', '#fcd5b5', '#ffdfba', '#ffe0bd', '#c68642', '#8d5524'];
        echo '<div style="display:flex;gap:8px">';
        foreach ($skinTones as $st) {
            echo '<button type="button" style="width:32px;height:32px;border-radius:8px;background:' . $st . ';border:2px solid #fff;cursor:pointer" onclick="setCustomColor(\'skin_tone\', \'' . $st . '\')"></button>';
        }
        echo '</div>';
        echo '</div>';

        // Shoes section
        echo '<div style="margin-bottom:18px;border-top:1px solid #1e293b;padding-top:14px">';
        echo '<label style="display:block;font-weight:bold;margin-bottom:8px;font-size:0.9rem">👟 Warna Sepatu</label>';
        $shoeColors = ['#1e293b', '#0f172a', '#ffffff', '#06b6d4', '#ec4899', '#f59e0b', '#ef4444'];
        echo '<div style="display:flex;gap:8px">';
        foreach ($shoeColors as $sc) {
            echo '<button type="button" style="width:28px;height:28px;border-radius:6px;background:' . $sc . ';border:2px solid #fff;cursor:pointer" onclick="setCustomColor(\'shoes_color\', \'' . $sc . '\')"></button>';
        }
        echo '</div>';
        echo '</div>';

        // Accessory section
        echo '<div style="margin-bottom:20px;border-top:1px solid #1e293b;padding-top:14px">';
        echo '<label style="display:block;font-weight:bold;margin-bottom:8px;font-size:0.9rem">👓 Aksesori Tambahan</label>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap" id="accOptions">';
        $accs = [
            'none'         => 'Tanpa Aksesori',
            'tie'          => 'Dasi Seragam',
            'cyber_visor'  => 'Cyber Visor (Neon)',
            'headphones'   => 'Headphone Gaming',
            'glasses'      => 'Kacamata Stylish',
            'backpack'     => 'Ransel Sekolah'
        ];
        foreach ($accs as $ak => $al) {
            echo '<button type="button" class="btn btn--sm btn--ghost acc-opt-btn" data-acc="' . $ak . '" onclick="setCustomAccessory(\'' . $ak . '\')">' . $al . '</button>';
        }
        echo '</div>';
        echo '</div>';

        // Save Customization Button Bar
        echo '<div style="display:flex;gap:12px;border-top:1px solid #1e293b;padding-top:16px">';
        echo '<button class="btn btn--primary" id="btnSaveStyle" onclick="saveCurrentCustomization()">💾 Simpan Kustomisasi Gaya</button>';
        echo '<button class="btn btn--ghost" onclick="resetToPresetDefaults()">🔄 Kembalikan ke Standar</button>';
        echo '</div>';

        echo '</div></div>';

        echo '</div>'; // End right column

        echo '</div>'; // End studio grid

        echo '</div>'; // End section

        // Client JavaScript for 3D Turntable Preview and Character Selection
        echo '<script nonce="' . $nonce . '">
const CSRF = "' . $csrf . '";
let currentConfig = ' . json_encode($activeAvatar['config']) . ';
let currentSlug = "' . htmlspecialchars($activeAvatar['slug'], ENT_QUOTES) . '";
let previewRenderer = null, previewScene = null, previewCamera = null;
let previewAvatarGroup = null;
let isDraggingPreview = false, prevPointerX = 0, prevPointerY = 0;
let previewRotY = 0, previewCamPitch = 0.2, previewCamDist = 18;
let autoSpin = false;
let emoteTimer = 0;

function switchStudioTab(tab) {
  const isPresets = (tab === "presets");
  document.getElementById("panelPresets").style.display = isPresets ? "block" : "none";
  document.getElementById("panelCustomize").style.display = isPresets ? "none" : "block";
  document.getElementById("tabPresetsBtn").className = "btn btn--sm tab-btn " + (isPresets ? "is-active" : "btn--ghost");
  document.getElementById("tabCustomizeBtn").className = "btn btn--sm tab-btn " + (isPresets ? "btn--ghost" : "is-active");
}

function initPreview3D() {
  const canvas = document.getElementById("charPreviewCanvas");
  if (!canvas || typeof THREE === "undefined") {
    document.getElementById("previewFallback").style.display = "flex";
    return;
  }

  try {
    previewRenderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
    previewRenderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    previewRenderer.setSize(canvas.clientWidth, canvas.clientHeight);
    previewRenderer.shadowMap.enabled = true;
    previewRenderer.shadowMap.type = THREE.PCFSoftShadowMap;

    previewScene = new THREE.Scene();
    previewCamera = new THREE.PerspectiveCamera(40, canvas.clientWidth / canvas.clientHeight, 0.5, 100);

    // Studio Lighting
    const hemiLight = new THREE.HemisphereLight(0xffffff, 0x1e293b, 0.9);
    previewScene.add(hemiLight);

    const keyLight = new THREE.DirectionalLight(0xfffbeb, 1.4);
    keyLight.position.set(12, 20, 16);
    keyLight.castShadow = true;
    previewScene.add(keyLight);

    const rimLight = new THREE.DirectionalLight(0x22d3ee, 0.8);
    rimLight.position.set(-12, 12, -14);
    previewScene.add(rimLight);

    // Studio Pedestal Floor
    const pedGeo = new THREE.CylinderGeometry(5.5, 6.0, 0.6, 32);
    const pedMat = new THREE.MeshLambertMaterial({ color: 0x1e293b });
    const ped = new THREE.Mesh(pedGeo, pedMat);
    ped.position.y = -0.3;
    ped.receiveShadow = true;
    previewScene.add(ped);

    const ringGeo = new THREE.RingGeometry(5.2, 5.5, 32);
    const ringMat = new THREE.MeshBasicMaterial({ color: 0x22d3ee, side: THREE.DoubleSide });
    const ring = new THREE.Mesh(ringGeo, ringMat);
    ring.rotation.x = -Math.PI / 2;
    ring.position.y = 0.02;
    previewScene.add(ring);

    buildPreviewAvatar();
    setupPreviewControls(canvas);
    animatePreview();
  } catch(e) {
    console.warn("WebGL preview error:", e);
    document.getElementById("previewFallback").style.display = "flex";
  }
}

function buildPreviewAvatar() {
  if (previewAvatarGroup) {
    previewScene.remove(previewAvatarGroup);
  }
  previewAvatarGroup = createStylizedCharacterMesh(currentConfig);
  previewScene.add(previewAvatarGroup);
}

function createStylizedCharacterMesh(cfg) {
  const group = new THREE.Group();

  const skinColor = parseInt((cfg.skin_tone || "#ffd19a").replace("#", "0x")) || 0xffd19a;
  const hairColor = parseInt((cfg.hair_color || "#262626").replace("#", "0x")) || 0x262626;
  const outfitColor = parseInt((cfg.outfit_color || "#0284c7").replace("#", "0x")) || 0x0284c7;
  const shoesColor = parseInt((cfg.shoes_color || "#1e293b").replace("#", "0x")) || 0x1e293b;

  const skinMat = new THREE.MeshLambertMaterial({ color: skinColor });
  const hairMat = new THREE.MeshLambertMaterial({ color: hairColor });
  const outfitMat = new THREE.MeshLambertMaterial({ color: outfitColor });
  const shoesMat = new THREE.MeshLambertMaterial({ color: shoesColor });

  // 1. Head (Chibi / Stylized Proportioned)
  const head = new THREE.Mesh(new THREE.BoxGeometry(2.4, 2.4, 2.4), skinMat);
  head.position.y = 6.2;
  head.castShadow = true;
  group.add(head);

  // Stylized Face Eyes & Smile (Canvas Texture on front face)
  const faceCanvas = document.createElement("canvas");
  faceCanvas.width = 128; faceCanvas.height = 128;
  const fctx = faceCanvas.getContext("2d");
  fctx.fillStyle = cfg.skin_tone || "#ffd19a";
  fctx.fillRect(0, 0, 128, 128);
  // Eyes
  fctx.fillStyle = "#0f172a";
  fctx.beginPath(); fctx.ellipse(38, 52, 7, 10, 0, 0, Math.PI * 2); fctx.fill();
  fctx.beginPath(); fctx.ellipse(90, 52, 7, 10, 0, 0, Math.PI * 2); fctx.fill();
  // Eye highlights
  fctx.fillStyle = "#ffffff";
  fctx.beginPath(); fctx.arc(40, 48, 3, 0, Math.PI * 2); fctx.fill();
  fctx.beginPath(); fctx.arc(92, 48, 3, 0, Math.PI * 2); fctx.fill();
  // Cheerful Smile
  fctx.strokeStyle = "#be123c"; fctx.lineWidth = 3;
  fctx.beginPath(); fctx.arc(64, 76, 14, 0.2, Math.PI - 0.2); fctx.stroke();

  const faceTex = new THREE.CanvasTexture(faceCanvas);
  const faceMat = new THREE.MeshBasicMaterial({ map: faceTex });
  const facePlane = new THREE.Mesh(new THREE.PlaneGeometry(2.35, 2.35), faceMat);
  facePlane.position.set(0, 6.2, 1.22);
  group.add(facePlane);

  // 2. Hair based on style
  const hairStyle = cfg.hair || "neat_short";
  if (hairStyle === "undercut") {
    const topHair = new THREE.Mesh(new THREE.BoxGeometry(2.6, 0.9, 2.6), hairMat);
    topHair.position.set(0, 7.5, 0.1);
    group.add(topHair);
    const fringe = new THREE.Mesh(new THREE.BoxGeometry(2.4, 0.6, 0.5), hairMat);
    fringe.position.set(0, 7.1, 1.3);
    group.add(fringe);
  } else if (hairStyle === "ponytail") {
    const topHair = new THREE.Mesh(new THREE.BoxGeometry(2.6, 1.0, 2.6), hairMat);
    topHair.position.set(0, 7.5, 0);
    group.add(topHair);
    const tail = new THREE.Mesh(new THREE.ConeGeometry(0.7, 2.4, 8), hairMat);
    tail.rotation.x = -Math.PI / 3;
    tail.position.set(0, 6.8, -1.8);
    group.add(tail);
  } else if (hairStyle === "hijab") {
    const hijabHood = new THREE.Mesh(new THREE.BoxGeometry(2.8, 2.8, 2.8), hairMat);
    hijabHood.position.set(0, 6.2, -0.2);
    group.add(hijabHood);
    const shawl = new THREE.Mesh(new THREE.BoxGeometry(3.8, 2.0, 2.4), hairMat);
    shawl.position.set(0, 4.4, 0);
    group.add(shawl);
  } else {
    // neat_short
    const capHair = new THREE.Mesh(new THREE.BoxGeometry(2.6, 0.8, 2.6), hairMat);
    capHair.position.set(0, 7.4, 0);
    group.add(capHair);
  }

  // 3. Torso / Outfit
  const torso = new THREE.Mesh(new THREE.BoxGeometry(3.6, 4.0, 1.8), outfitMat);
  torso.position.y = 3.8;
  torso.castShadow = true;
  group.add(torso);

  // 4. Arms
  const leftArm = new THREE.Mesh(new THREE.BoxGeometry(1.2, 3.8, 1.4), outfitMat);
  leftArm.position.set(-2.5, 3.7, 0);
  leftArm.castShadow = true;
  group.add(leftArm);

  const rightArm = new THREE.Mesh(new THREE.BoxGeometry(1.2, 3.8, 1.4), outfitMat);
  rightArm.position.set(2.5, 3.7, 0);
  rightArm.castShadow = true;
  group.add(rightArm);
  group.userData.rightArm = rightArm;

  // Hands (skin tone)
  const leftHand = new THREE.Mesh(new THREE.BoxGeometry(1.0, 0.7, 1.2), skinMat);
  leftHand.position.set(-2.5, 1.6, 0);
  group.add(leftHand);

  const rightHand = new THREE.Mesh(new THREE.BoxGeometry(1.0, 0.7, 1.2), skinMat);
  rightHand.position.set(2.5, 1.6, 0);
  group.add(rightHand);
  group.userData.rightHand = rightHand;

  // 5. Legs & Shoes
  const legMat = new THREE.MeshLambertMaterial({ color: (cfg.outfit === "blazer" || cfg.outfit === "cyber_hoodie") ? 0x0f172a : 0x1e293b });
  const leftLeg = new THREE.Mesh(new THREE.BoxGeometry(1.5, 3.4, 1.5), legMat);
  leftLeg.position.set(-1.0, 2.0, 0);
  leftLeg.castShadow = true;
  group.add(leftLeg);

  const rightLeg = new THREE.Mesh(new THREE.BoxGeometry(1.5, 3.4, 1.5), legMat);
  rightLeg.position.set(1.0, 2.0, 0);
  rightLeg.castShadow = true;
  group.add(rightLeg);

  // Shoes
  const leftShoe = new THREE.Mesh(new THREE.BoxGeometry(1.6, 0.8, 2.2), shoesMat);
  leftShoe.position.set(-1.0, 0.4, 0.3);
  leftShoe.castShadow = true;
  group.add(leftShoe);

  const rightShoe = new THREE.Mesh(new THREE.BoxGeometry(1.6, 0.8, 2.2), shoesMat);
  rightShoe.position.set(1.0, 0.4, 0.3);
  rightShoe.castShadow = true;
  group.add(rightShoe);

  // 6. Accessories
  const acc = cfg.accessory || "none";
  if (acc === "tie") {
    const tie = new THREE.Mesh(new THREE.BoxGeometry(0.6, 2.2, 0.2), new THREE.MeshLambertMaterial({ color: 0xef4444 }));
    tie.position.set(0, 4.2, 0.95);
    group.add(tie);
  } else if (acc === "cyber_visor") {
    const visorGeo = new THREE.BoxGeometry(2.5, 0.6, 0.4);
    const visorMat = new THREE.MeshLambertMaterial({ color: 0x22d3ee, emissive: 0x0891b2 });
    const visor = new THREE.Mesh(visorGeo, visorMat);
    visor.position.set(0, 6.2, 1.25);
    group.add(visor);
  } else if (acc === "headphones") {
    const band = new THREE.Mesh(new THREE.TorusGeometry(1.4, 0.2, 8, 16, Math.PI), new THREE.MeshLambertMaterial({ color: 0x0f172a }));
    band.position.set(0, 6.8, 0);
    group.add(band);
    const cupL = new THREE.Mesh(new THREE.CylinderGeometry(0.6, 0.6, 0.4, 12), new THREE.MeshLambertMaterial({ color: 0x3b82f6 }));
    cupL.rotation.z = Math.PI / 2;
    cupL.position.set(-1.4, 6.2, 0);
    const cupR = cupL.clone();
    cupR.position.x = 1.4;
    group.add(cupL, cupR);
  } else if (acc === "glasses") {
    const gFrame = new THREE.Mesh(new THREE.BoxGeometry(2.4, 0.4, 0.2), new THREE.MeshLambertMaterial({ color: 0x334155 }));
    gFrame.position.set(0, 6.2, 1.24);
    group.add(gFrame);
  } else if (acc === "backpack") {
    const bp = new THREE.Mesh(new THREE.BoxGeometry(2.8, 3.2, 1.4), new THREE.MeshLambertMaterial({ color: 0x059669 }));
    bp.position.set(0, 3.6, -1.2);
    group.add(bp);
  }

  return group;
}

function setupPreviewControls(canvas) {
  canvas.addEventListener("mousedown", (e) => {
    isDraggingPreview = true;
    prevPointerX = e.clientX;
    prevPointerY = e.clientY;
  });

  window.addEventListener("mousemove", (e) => {
    if (!isDraggingPreview) return;
    const dx = e.clientX - prevPointerX;
    const dy = e.clientY - prevPointerY;
    prevPointerX = e.clientX;
    prevPointerY = e.clientY;

    previewRotY += dx * 0.012;
    previewCamPitch = Math.max(-0.2, Math.min(0.8, previewCamPitch + dy * 0.008));
  });

  window.addEventListener("mouseup", () => { isDraggingPreview = false; });

  canvas.addEventListener("wheel", (e) => {
    e.preventDefault();
    previewCamDist = Math.max(10, Math.min(30, previewCamDist + e.deltaY * 0.03));
  }, { passive: false });

  // Touch orbit
  canvas.addEventListener("touchstart", (e) => {
    if (e.touches.length === 1) {
      isDraggingPreview = true;
      prevPointerX = e.touches[0].clientX;
      prevPointerY = e.touches[0].clientY;
    }
  }, { passive: true });

  canvas.addEventListener("touchmove", (e) => {
    if (!isDraggingPreview || e.touches.length !== 1) return;
    const dx = e.touches[0].clientX - prevPointerX;
    const dy = e.touches[0].clientY - prevPointerY;
    prevPointerX = e.touches[0].clientX;
    prevPointerY = e.touches[0].clientY;

    previewRotY += dx * 0.014;
    previewCamPitch = Math.max(-0.2, Math.min(0.8, previewCamPitch + dy * 0.01));
  }, { passive: true });

  canvas.addEventListener("touchend", () => { isDraggingPreview = false; });
}

function resetPreviewCamera() {
  previewRotY = 0;
  previewCamPitch = 0.2;
  previewCamDist = 18;
}

function toggleTurntableAutoSpin() {
  autoSpin = !autoSpin;
  const btn = document.getElementById("btnAutoSpin");
  if (btn) btn.style.background = autoSpin ? "var(--c-primary)" : "";
}

function triggerPreviewEmote() {
  emoteTimer = 2.0;
  if (window.UI) UI.toast("👋 Halo Sekolah SMK Silber!", "info", 1500);
}

function animatePreview() {
  requestAnimationFrame(animatePreview);

  if (autoSpin && !isDraggingPreview) {
    previewRotY += 0.015;
  }

  if (previewAvatarGroup) {
    previewAvatarGroup.rotation.y = previewRotY;

    // Gentle idle breathing
    const time = Date.now() * 0.003;
    previewAvatarGroup.position.y = Math.sin(time) * 0.08;

    // Emote waving arm
    if (emoteTimer > 0) {
      emoteTimer -= 0.016;
      if (previewAvatarGroup.userData.rightArm) {
        previewAvatarGroup.userData.rightArm.rotation.z = -2.4 + Math.sin(Date.now() * 0.015) * 0.5;
      }
    } else if (previewAvatarGroup.userData.rightArm && previewAvatarGroup.userData.rightArm.rotation.z !== 0) {
      previewAvatarGroup.userData.rightArm.rotation.z = 0;
    }
  }

  if (previewCamera && previewRenderer && previewScene) {
    const hDist = previewCamDist * Math.cos(previewCamPitch);
    const vDist = previewCamDist * Math.sin(previewCamPitch) + 4.5;
    previewCamera.position.set(0, vDist, hDist);
    previewCamera.lookAt(0, 4.2, 0);

    previewRenderer.render(previewScene, previewCamera);
  }
}

// Customizer hooks
function setCustomHair(hair) {
  currentConfig.hair = hair;
  buildPreviewAvatar();
  highlightActiveButtons();
}

function setCustomOutfit(outfit) {
  currentConfig.outfit = outfit;
  buildPreviewAvatar();
  highlightActiveButtons();
}

function setCustomAccessory(acc) {
  currentConfig.accessory = acc;
  buildPreviewAvatar();
  highlightActiveButtons();
}

function setCustomColor(field, hex) {
  currentConfig[field] = hex;
  buildPreviewAvatar();
}

function highlightActiveButtons() {
  document.querySelectorAll(".hair-opt-btn").forEach(b => {
    b.classList.toggle("btn--primary", b.getAttribute("data-hair") === currentConfig.hair);
    b.classList.toggle("btn--ghost", b.getAttribute("data-hair") !== currentConfig.hair);
  });
  document.querySelectorAll(".outfit-opt-btn").forEach(b => {
    b.classList.toggle("btn--primary", b.getAttribute("data-outfit") === currentConfig.outfit);
    b.classList.toggle("btn--ghost", b.getAttribute("data-outfit") !== currentConfig.outfit);
  });
  document.querySelectorAll(".acc-opt-btn").forEach(b => {
    b.classList.toggle("btn--primary", b.getAttribute("data-acc") === currentConfig.accessory);
    b.classList.toggle("btn--ghost", b.getAttribute("data-acc") !== currentConfig.accessory);
  });
}

function previewPreset(preset) {
  currentSlug = preset.slug;
  currentConfig = Object.assign({}, preset.config);
  document.getElementById("activeCharName").textContent = preset.name;
  document.getElementById("activeCharDesc").textContent = preset.description;
  buildPreviewAvatar();
  highlightActiveButtons();
  if (window.UI) UI.toast("Melihat preview: " + preset.name, "info", 1500);
}

function resetToPresetDefaults() {
  buildPreviewAvatar();
  highlightActiveButtons();
}

async function equipPreset(presetId) {
  try {
    const r = await fetch("/api/character/select", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": CSRF },
      body: JSON.stringify({ preset_id: presetId, _csrf: CSRF })
    });
    const d = await r.json();
    if (d.status === "success") {
      if (window.UI) UI.toast(d.message || "Karakter berhasil digunakan!", "success", 2500);
      setTimeout(() => location.reload(), 800);
    } else {
      if (window.UI) UI.toast(d.message || "Gagal memilih karakter.", "danger");
    }
  } catch(e) {
    if (window.UI) UI.toast("Koneksi gagal ke server.", "danger");
  }
}

async function unlockPreset(presetId, price, name) {
  if (!confirm("Buka karakter \"" + name + "\" seharga " + price + " koin?")) return;
  try {
    const r = await fetch("/api/character/select", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": CSRF },
      body: JSON.stringify({ preset_id: presetId, _csrf: CSRF })
    });
    const d = await r.json();
    if (d.status === "success") {
      if (window.UI) {
        UI.confetti(25);
        UI.toast("🎉 Selamat! Karakter \"" + name + "\" berhasil dibuka!", "gold", 3000);
      }
      setTimeout(() => location.reload(), 1000);
    } else {
      if (window.UI) UI.toast(d.message || "Gagal membuka karakter.", "danger");
    }
  } catch(e) {
    if (window.UI) UI.toast("Koneksi gagal ke server.", "danger");
  }
}

async function saveCurrentCustomization() {
  const btn = document.getElementById("btnSaveStyle");
  if (btn) btn.disabled = true;

  try {
    const r = await fetch("/api/character/customize", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": CSRF },
      body: JSON.stringify({ config: currentConfig, _csrf: CSRF })
    });
    const d = await r.json();
    if (d.status === "success") {
      if (window.UI) UI.toast("✨ Gaya karakter berhasil disimpan!", "success", 2500);
    } else {
      if (window.UI) UI.toast(d.message || "Gagal menyimpan gaya.", "danger");
    }
  } catch(e) {
    if (window.UI) UI.toast("Koneksi gagal ke server.", "danger");
  } finally {
    if (btn) btn.disabled = false;
  }
}

function startStudio() {
  initPreview3D();
  highlightActiveButtons();
}
if (document.readyState === "loading") {
  window.addEventListener("DOMContentLoaded", startStudio);
} else {
  startStudio();
}
</script>';

        Shell::end(['user' => $user, 'active' => 'character']);
    }
}

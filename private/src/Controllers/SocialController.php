<?php
// SocialController: Web UI for Digital Student ID Card (3D Flip), Friends, and Chat with Focus Mode.
declare(strict_types=1);

namespace VC\Controllers;

use VC\Database;
use VC\Domain\Social;
use VC\Security\Auth;
use VC\Security\Csrf;
use VC\Security\Footer;
use VC\Security\Headers;
use VC\Security\Pwa;

final class SocialController
{
    /**
     * Profil pemain (/profil, /profil?id=X, /profil?q=nama): karakter, bio, level & pencapaian,
     * lencana, sertifikat, koleksi, teman. Bisa membuka profil pengguna lain + tambah teman,
     * kirim pesan (DM) & ajak mabar. Privasi: public | friends | private (pemilik & staf selalu lihat).
     */
    public static function renderProfilePage(): void
    {
        $nonce = Headers::nonce();
        $user = Auth::user();
        if ($user === null) {
            header('Location: /login?redirect=' . urlencode('/profil'));
            exit;
        }
        $viewerId = (int)$user['id'];
        $targetId = (int)($_GET['id'] ?? $viewerId);
        $profile = Social::getProfile($targetId);
        if ($profile === []) {
            $targetId = $viewerId;
            $profile = Social::getProfile($viewerId);
        }
        $isOwner = $targetId === $viewerId;
        $isStaff = in_array($user['role'] ?? '', ['guru', 'wali_kelas', 'kepala_program', 'kepala_sekolah', 'root'], true);
        $friend = $isOwner ? 'self' : Social::friendStatus($viewerId, $targetId);
        $privacy = (string)($profile['privacy_level'] ?? 'public');
        $canSee = $isOwner || $isStaff || $privacy === 'public' || ($privacy === 'friends' && $friend === 'accepted');
        $q = trim((string)($_GET['q'] ?? ''));
        $results = $q !== '' ? Social::searchUsers($q, 30) : [];
        $ach = Social::achievements($targetId);
        $friends = $canSee ? array_slice(Social::getFriends($targetId), 0, 12) : [];
        $av = \VC\Domain\AvatarStyle::forUser($targetId, (string)$profile['role']);
        $roleLabel = ['root' => 'Admin ROOT', 'kepala_sekolah' => 'Kepala Sekolah', 'kepala_program' => 'Kepala Program', 'wali_kelas' => 'Wali Kelas',
            'guru' => 'Guru', 'pembina' => 'Pembina', 'pengurus' => 'Pengurus', 'murid' => 'Siswa', 'murid_akses' => 'Siswa', 'tamu' => 'Tamu'][$profile['role']] ?? 'Pemain';
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES);
        $p = $ach['progress'];
        $level = max(1, (int)$p['level']);
        $pct = 0;
        try {
            $into = \VC\Domain\Gameplay::xpIntoLevel((int)$p['total_xp'], $level);
            $left = \VC\Domain\Gameplay::xpToNextLevel((int)$p['total_xp'], $level);
            $pct = ($into + $left) > 0 ? (int)round(100 * $into / ($into + $left)) : 100;
        } catch (\Throwable $ex) {
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        \VC\View\Shell::start([
            'title' => ($isOwner ? 'Profil Saya' : 'Profil ' . (string)$profile['full_name']) . ' — Virtual City',
            'active' => 'profile',
            'user' => $user,
            'head' => '<style nonce="' . $nonce . '">' . self::PROFILE_CSS . '</style>',
        ]);
        echo '<div class="pf">';

        // ---- Cari pengguna ----
        echo '<form class="pf-search px-panel" method="get" action="/profil" role="search">'
            . '<label for="pfQ" class="pf-sr">Cari pengguna</label>'
            . '<input id="pfQ" name="q" value="' . $e($q) . '" placeholder="🔎 Cari teman / guru (nama atau username)…" minlength="2" maxlength="40" autocomplete="off"' . (isset($_GET['cari']) ? ' autofocus' : '') . '>'
            . '<button class="px-btn" type="submit">Cari</button></form>';
        if ($q !== '') {
            echo '<section class="px-panel pf-box"><h2>Hasil pencarian “' . $e($q) . '”</h2>';
            if ($results === []) {
                echo '<p class="pf-muted">Tidak ada pengguna yang cocok. Coba ketik minimal 2 huruf nama.</p>';
            } else {
                echo '<div class="pf-people">';
                foreach ($results as $r) {
                    echo '<a class="pf-person" href="/profil?id=' . (int)$r['id'] . '"><b>' . $e($r['full_name'] ?: $r['username']) . '</b>'
                        . '<small>' . $e($r['class_name'] ?: ucfirst(str_replace('_', ' ', (string)$r['role']))) . ' · LV ' . (int)$r['level'] . '</small></a>';
                }
                echo '</div>';
            }
            echo '</section>';
        }

        // ---- Kartu utama ----
        echo '<section class="px-panel pf-hero">';
        $photo = \VC\Domain\ProfilePhoto::url($targetId);
        echo '<div class="pf-photo-wrap">';
        echo '<div class="pf-photo" id="pfPhoto">' . ($photo !== '' ? '<img src="' . $e($photo) . '" alt="Foto profil ' . $e($profile['full_name']) . '" width="120" height="120">' : '<span aria-hidden="true">' . $e(mb_strtoupper(mb_substr((string)($profile['full_name'] ?: $profile['username']), 0, 1))) . '</span>') . '</div>';
        echo '<div class="pf-avatar"><canvas id="pfSprite" width="22" height="42" aria-label="Karakter ' . $e($profile['full_name']) . '"></canvas></div>';
        if ($isOwner) {
            echo '<label class="px-btn pf-photo-btn">📷 Ganti Foto<input type="file" id="pfPhotoIn" accept="image/jpeg,image/png,image/webp" hidden></label>';
            if ($photo !== '') {
                echo '<button type="button" class="px-btn px-btn--dark pf-photo-btn" id="pfPhotoDel">🗑️ Hapus Foto</button>';
            }
        } elseif ($photo !== '' && \VC\Security\Owner::is($user)) {
            echo '<button type="button" class="px-btn px-btn--dark pf-photo-btn" id="pfPhotoDel" data-uid="' . $targetId . '">🗑️ Hapus Foto (moderasi)</button>';
        }
        echo '</div>';
        echo '<div class="pf-id">';
        echo '<span class="pf-title">🏅 ' . $e($p['titles'] ?: 'Siswa Baru') . '</span>';
        echo '<h1>' . $e($profile['full_name'] ?: $profile['username']) . '</h1>';
        echo '<p class="pf-sub">' . $e($roleLabel) . (!empty($profile['class_name']) ? ' · ' . $e($profile['class_name']) : '') . (!empty($profile['program_name']) ? ' · ' . $e($profile['program_name']) : '') . '</p>';
        if ($canSee && !empty($profile['status_text'])) {
            echo '<p class="pf-status">💭 ' . $e($profile['status_text']) . '</p>';
        }
        echo '<div class="pf-lv"><span>LV ' . $level . '</span><span class="pf-xp" role="progressbar" aria-label="XP" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"><i style="width:' . $pct . '%"></i></span><span>' . (int)$p['total_xp'] . ' XP</span></div>';
        echo '<div class="pf-actions">';
        if ($isOwner) {
            echo '<button type="button" class="px-btn px-btn--gold" id="pfEditBtn">✏️ Edit Profil</button>';
            echo '<a class="px-btn" href="/?karakter=1">🎨 Ganti Karakter</a>';
            echo '<a class="px-btn px-btn--dark" href="/teman">👥 Teman</a>';
        } else {
            if ($friend === 'none') {
                echo '<button type="button" class="px-btn px-btn--gold" data-friend="' . $targetId . '">➕ Tambah Teman</button>';
            } elseif ($friend === 'pending_out') {
                echo '<span class="px-btn px-btn--dark pf-static">⏳ Menunggu konfirmasi</span>';
            } elseif ($friend === 'pending_in') {
                echo '<a class="px-btn px-btn--gold" href="/teman">✅ Terima pertemanan</a>';
            } elseif ($friend === 'accepted') {
                echo '<span class="px-btn px-btn--dark pf-static">🤝 Berteman</span>';
            }
            if (($profile['role'] ?? '') !== 'tamu') {
                echo '<a class="px-btn" href="/obrolan?dm=' . $targetId . '">💬 Kirim Pesan</a>';
            }
            echo '<button type="button" class="px-btn" data-mabar="' . $targetId . '">🎮 Ajak Mabar</button>';
        }
        echo '</div><p class="pf-msg" id="pfMsg" aria-live="polite"></p>';
        echo '</div></section>';

        if (!$canSee) {
            echo '<section class="px-panel pf-box pf-private"><h2>🔒 Profil privat</h2><p>' . $e($profile['full_name']) . ' hanya menampilkan profil lengkap ' . ($privacy === 'friends' ? 'kepada teman.' : 'kepada dirinya sendiri.') . '</p></section>';
        } else {
            // ---- Bio ----
            echo '<section class="px-panel pf-box"><h2>📝 Tentang</h2>';
            echo '<p class="pf-bio">' . ($profile['bio'] !== '' && $profile['bio'] !== null ? nl2br($e($profile['bio'])) : '<span class="pf-muted">' . ($isOwner ? 'Belum ada bio. Tekan “Edit Profil” untuk menulis tentang dirimu.' : 'Belum menulis bio.') . '</span>') . '</p>';
            if (!empty($profile['interests'])) {
                echo '<div class="pf-tags">' . implode('', array_map(static fn($t) => '<span>' . htmlspecialchars(trim($t), ENT_QUOTES) . '</span>', array_filter(explode(',', (string)$profile['interests']), static fn($t) => trim($t) !== ''))) . '</div>';
            }
            echo '</section>';

            // ---- Statistik ----
            $stats = [
                ['⭐', 'Level', $level], ['✨', 'Total XP', (int)$p['total_xp']], ['🪙', 'Koin', (int)$p['coins']],
                ['🎯', 'Misi selesai', $ach['missions_done']], ['🗺️', 'Tempat dikunjungi', $ach['places'] . ' / 38'],
                ['📚', 'Tugas dikumpulkan', $ach['tasks_done']], ['🔥', 'Login beruntun', (int)$p['login_streak'] . ' hari'], ['🤝', 'Teman', $ach['friends']],
            ];
            echo '<section class="px-panel pf-box"><h2>🏆 Pencapaian</h2><div class="pf-stats">';
            foreach ($stats as [$ic, $lb, $val]) {
                echo '<div class="pf-stat"><span aria-hidden="true">' . $ic . '</span><b>' . $e($val) . '</b><small>' . $e($lb) . '</small></div>';
            }
            echo '</div></section>';

            // ---- Lencana ----
            echo '<section class="px-panel pf-box"><h2>🎖️ Lencana <small>(' . count($ach['badges']) . ' / ' . $ach['badge_total'] . ')</small></h2>';
            if ($ach['badges'] === []) {
                echo '<p class="pf-muted">Belum ada lencana. Selesaikan misi & jelajahi sekolah untuk mendapatkannya.</p>';
            } else {
                echo '<div class="pf-badges">';
                foreach ($ach['badges'] as $b) {
                    echo '<div class="pf-badge pf-r-' . $e($b['rarity']) . '" title="' . $e($b['description']) . '"><span aria-hidden="true">' . $e($b['icon_emoji']) . '</span><b>' . $e($b['name']) . '</b><small>' . $e(ucfirst((string)$b['rarity'])) . '</small></div>';
                }
                echo '</div>';
            }
            echo '</section>';

            if ($ach['certificates'] !== [] || $ach['items'] !== []) {
                echo '<section class="px-panel pf-box pf-two">';
                echo '<div><h2>📜 Sertifikat</h2>';
                if ($ach['certificates'] === []) {
                    echo '<p class="pf-muted">Belum ada.</p>';
                } else {
                    echo '<ul class="pf-list">';
                    foreach ($ach['certificates'] as $c) {
                        echo '<li><b>' . $e($c['title']) . '</b><small>' . $e(substr((string)$c['issued_at'], 0, 10)) . ' · ' . $e($c['cert_number']) . '</small></li>';
                    }
                    echo '</ul>';
                }
                echo '</div><div><h2>🎒 Koleksi Toko</h2>';
                if ($ach['items'] === []) {
                    echo '<p class="pf-muted">Belum ada item.</p>';
                } else {
                    echo '<div class="pf-tags">';
                    foreach ($ach['items'] as $it) {
                        echo '<span class="pf-r-' . $e($it['rarity']) . '">' . (!empty($it['equipped']) ? '✅ ' : '') . $e($it['name']) . '</span>';
                    }
                    echo '</div>';
                }
                echo '</div></section>';
            }

            // ---- Teman ----
            if ($friends !== []) {
                echo '<section class="px-panel pf-box"><h2>👥 Teman</h2><div class="pf-people">';
                foreach ($friends as $f) {
                    echo '<a class="pf-person" href="/profil?id=' . (int)$f['id'] . '"><b>' . $e($f['full_name'] ?: $f['username']) . '</b><small>' . $e($f['class_name'] ?: ucfirst((string)$f['role'])) . '</small></a>';
                }
                echo '</div></section>';
            }
        }

        // ---- Form edit (pemilik) ----
        if ($isOwner) {
            echo '<div class="pxd-pick" id="pfEdit" hidden role="dialog" aria-modal="true" aria-labelledby="pfEditTitle"><div class="pxd-pick__box px-panel pf-editbox">';
            echo '<h2 id="pfEditTitle">✏️ Edit Profil</h2><form id="pfForm">';
            echo '<label>Bio <small>(maks. 160)</small><textarea name="bio" maxlength="160" rows="3">' . $e($profile['bio']) . '</textarea></label>';
            echo '<label>Status<input name="status_text" maxlength="80" value="' . $e($profile['status_text']) . '"></label>';
            echo '<label>Minat &amp; keahlian <small>(pisahkan dengan koma)</small><input name="interests" maxlength="120" value="' . $e($profile['interests']) . '"></label>';
            echo '<label>Siapa yang bisa melihat profil lengkap?<select name="privacy_level">';
            foreach (['public' => '🌍 Semua orang', 'friends' => '🤝 Hanya teman', 'private' => '🔒 Hanya saya (& guru)'] as $k => $lb) {
                echo '<option value="' . $k . '"' . ($privacy === $k ? ' selected' : '') . '>' . $lb . '</option>';
            }
            echo '</select></label>';
            echo '<div class="pf-actions"><button type="button" class="px-btn px-btn--dark" data-close>Batal</button><button type="submit" class="px-btn px-btn--gold">💾 Simpan</button></div>';
            echo '</form></div></div>';
        }
        echo '</div>';

        echo '<script src="/assets/js/pixel-avatar.js?v=8.1.2"></script>';
        echo '<script nonce="' . $nonce . '">
(function () {
  var CFG = ' . json_encode($av['config'], $flags) . ';
  var cv = document.getElementById("pfSprite");
  if (cv && window.PixelAvatar) { try { window.PixelAvatar.paint(cv, window.PixelAvatar.normalize(CFG), false); } catch (e) {} }
  var CSRF = (document.querySelector("meta[name=csrf]") || {}).content || "";
  var msg = document.getElementById("pfMsg");
  function say(t, ok) { if (msg) { msg.textContent = t; msg.style.color = ok ? "#9fe08a" : "#ffb4a8"; } }
  function post(url, body) {
    return fetch(url, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "X-CSRF": CSRF }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return {}; }); });
  }
  document.addEventListener("click", function (e) {
    var t = e.target.closest ? e.target.closest("[data-friend],[data-mabar],#pfEditBtn,[data-close]") : null;
    if (!t) return;
    if (t.hasAttribute("data-friend")) {
      t.disabled = true;
      post("/api/friends/request", { friend_id: parseInt(t.getAttribute("data-friend"), 10) }).then(function (d) {
        var ok = d.status === "success" || d.status === "ok";
        say(d.message || (ok ? "Permintaan pertemanan terkirim!" : "Gagal."), ok);
        if (ok) t.textContent = "⏳ Menunggu konfirmasi"; else t.disabled = false;
      });
    } else if (t.hasAttribute("data-mabar")) {
      if (window.vcParty) window.vcParty.invite(parseInt(t.getAttribute("data-mabar"), 10)).then(function (d) { say(d.message || "", d.status === "ok"); });
      else location.href = "/?mabar=1";
    } else if (t.id === "pfEditBtn") {
      document.getElementById("pfEdit").hidden = false;
    } else if (t.hasAttribute("data-close")) {
      document.getElementById("pfEdit").hidden = true;
    }
  });
  // Foto profil: diperkecil di browser (persegi 320 px, JPEG) lalu diunggah — server tanpa GD.
  var pin = document.getElementById("pfPhotoIn");
  if (pin) pin.addEventListener("change", function () {
    var f = pin.files && pin.files[0];
    if (!f) return;
    if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { say("Pilih foto JPG, PNG, atau WebP.", false); return; }
    say("Memproses foto…", true);
    var url = URL.createObjectURL(f), img = new Image();
    img.onload = function () {
      var side = Math.min(img.naturalWidth, img.naturalHeight), S = 320;
      var c = document.createElement("canvas"); c.width = S; c.height = S;
      var x = c.getContext("2d");
      x.drawImage(img, (img.naturalWidth - side) / 2, (img.naturalHeight - side) / 2, side, side, 0, 0, S, S);
      URL.revokeObjectURL(url);
      c.toBlob(function (blob) {
        if (!blob) { say("Gagal memproses foto.", false); return; }
        var fd = new FormData(); fd.append("photo", blob, "foto.jpg"); fd.append("_csrf", CSRF);
        fetch("/api/profile/photo", { method: "POST", credentials: "same-origin", headers: { "X-CSRF": CSRF }, body: fd })
          .then(function (r) { return r.json().catch(function () { return {}; }); })
          .then(function (d) {
            if (d.status === "ok") { say("✅ Foto profil diperbarui!", true); setTimeout(function () { location.reload(); }, 600); }
            else say(d.message || "Gagal mengunggah foto.", false);
          }).catch(function () { say("Koneksi terputus.", false); });
      }, "image/jpeg", 0.85);
    };
    img.onerror = function () { URL.revokeObjectURL(url); say("Foto tidak bisa dibaca.", false); };
    img.src = url;
  });
  var pdel = document.getElementById("pfPhotoDel");
  if (pdel) pdel.addEventListener("click", function () {
    if (!confirm("Hapus foto profil?")) return;
    var fd = new FormData(); fd.append("action", "remove"); fd.append("_csrf", CSRF);
    if (pdel.getAttribute("data-uid")) fd.append("user_id", pdel.getAttribute("data-uid"));
    fetch("/api/profile/photo", { method: "POST", credentials: "same-origin", headers: { "X-CSRF": CSRF }, body: fd })
      .then(function (r) { return r.json(); }).then(function () { location.reload(); });
  });
  var form = document.getElementById("pfForm");
  if (form) form.addEventListener("submit", function (e) {
    e.preventDefault();
    var b = {}; ["bio", "status_text", "interests", "privacy_level"].forEach(function (k) { b[k] = form.elements[k].value; });
    post("/api/profile/update", b).then(function (d) { if (d.status === "success") location.reload(); else alert(d.message || "Gagal menyimpan."); });
  });
})();
</script>';
        \VC\View\Shell::end(['active' => 'profile', 'scripts' => ['/assets/js/party.js?v=7.5.0']]);
    }

    private const PROFILE_CSS = '.pf{max-width:880px;margin:0 auto;padding:12px 12px 24px;display:flex;flex-direction:column;gap:12px}'
        . '.pf-sr{position:absolute;left:-9999px}.pf-search{display:flex;gap:8px;padding:10px!important}.pf-search input{flex:1;min-width:0;font-size:16px}'
        . '.pf-box{padding:14px 16px!important}.pf-box h2{margin:0 0 10px;font-size:18px}.pf-box h2 small{opacity:.7;font-size:14px}'
        . '.pf-hero{display:flex;gap:18px;align-items:center;padding:16px!important;flex-wrap:wrap}'
        . '.pf-avatar{width:110px;height:170px;display:grid;place-items:end center;background:linear-gradient(#2a3d5c,#1b2638);border:3px solid #d9a441;flex:0 0 auto}'
        . '.pf-avatar canvas{width:88px;height:168px;image-rendering:pixelated}'
        . '.pf-photo-wrap{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;justify-content:center}'
        . '.pf-photo{width:120px;height:120px;border-radius:50%;overflow:hidden;border:4px solid #d9a441;box-shadow:0 0 0 3px #1b2638;background:linear-gradient(135deg,#f59e0b,#b45309);display:grid;place-items:center;font-size:48px;font-weight:800;color:#fff7e6}'
        . '.pf-photo img{width:100%;height:100%;object-fit:cover;display:block}'
        . '.pf-photo-btn{min-height:44px;font-size:14px;cursor:pointer;flex-basis:100%;justify-content:center;text-align:center}'
        . '@media(max-width:520px){.pf-avatar{width:84px;height:130px}.pf-avatar canvas{width:66px;height:126px}.pf-photo{width:96px;height:96px}}'
        . '.pf-id{flex:1;min-width:220px}.pf-id h1{margin:4px 0 2px;font-size:clamp(22px,4.6vw,30px);line-height:1.15}'
        . '.pf-title{display:inline-block;padding:2px 8px;background:rgba(217,164,65,.2);border:2px solid #d9a441;font-size:13px}'
        . '.pf-sub{margin:0;opacity:.85}.pf-status{margin:6px 0 0;font-style:italic}'
        . '.pf-lv{display:flex;align-items:center;gap:8px;margin:10px 0;font-weight:700}.pf-xp{flex:1;max-width:260px;height:10px;background:#0d121d;border:2px solid #20130a;position:relative}.pf-xp i{position:absolute;inset:0 auto 0 0;background:#4ade80}'
        . '.pf-actions{display:flex;flex-wrap:wrap;gap:8px}.pf-static{cursor:default}.pf-msg{min-height:1.2em;margin:6px 0 0;font-size:14px}'
        . '.pf-muted{opacity:.7}.pf-bio{margin:0;line-height:1.6;white-space:normal}'
        . '.pf-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.pf-tags span{padding:3px 9px;background:rgba(56,189,248,.14);border:2px solid rgba(56,189,248,.45);font-size:14px}'
        . '.pf-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px}'
        . '.pf-stat{display:flex;flex-direction:column;align-items:center;gap:2px;padding:10px 6px;background:rgba(0,0,0,.22);border:2px solid rgba(255,255,255,.08);text-align:center}.pf-stat span{font-size:22px}.pf-stat b{font-size:18px}.pf-stat small{opacity:.8}'
        . '.pf-badges{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px}.pf-badge{display:flex;flex-direction:column;align-items:center;text-align:center;padding:10px 6px;background:rgba(0,0,0,.22);border:2px solid #64748b}.pf-badge span{font-size:28px}.pf-badge small{opacity:.75}'
        . '.pf-r-uncommon{border-color:#4ade80!important}.pf-r-rare{border-color:#38bdf8!important}.pf-r-epic{border-color:#c084fc!important}.pf-r-legendary{border-color:#fbbf24!important}'
        . '.pf-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}.pf-list{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:6px}.pf-list li{display:flex;flex-direction:column}.pf-list small{opacity:.7}'
        . '.pf-people{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px}.pf-person{display:flex;flex-direction:column;padding:8px 10px;background:rgba(0,0,0,.22);border:2px solid rgba(255,255,255,.1);text-decoration:none;color:inherit}.pf-person:hover{border-color:#d9a441}.pf-person small{opacity:.75}'
        . '.pf .pxd-pick{position:fixed;inset:0;z-index:60;display:flex;align-items:center;justify-content:center;padding:12px;background:rgba(10,6,14,.75)}.pf .pxd-pick[hidden]{display:none}'
        . '.pf-editbox{max-width:480px;width:calc(100% - 24px);padding:16px!important}.pf-editbox form{display:flex;flex-direction:column;gap:10px}.pf-editbox label{display:flex;flex-direction:column;gap:4px}.pf-editbox textarea,.pf-editbox input,.pf-editbox select{font-size:16px}'
        . '@media (max-width:600px){.pf-two{grid-template-columns:1fr}.pf-hero{justify-content:center;text-align:center}.pf-lv,.pf-actions{justify-content:center}.pf-xp{max-width:none}}';

    public static function renderFriendsPage(): void
    {
        $nonce = Headers::nonce();
        $user = Auth::user();
        if (!$user) {
            header('Location: /');
            exit;
        }

        $userId = (int)$user['id'];
        $friends = Social::getFriends($userId);
        $pending = Social::getPendingRequests($userId);
        $csrf = Csrf::token();

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo Pwa::headTags();
        echo '<title>Daftar Teman — Virtual City SMK Silber</title>';
        echo '<style nonce="' . $nonce . '">
:root { --bg: #090d16; --card: #131a29; --border: #1f293d; --accent: #22d3ee; }
body { font-family: system-ui, sans-serif; background: var(--bg); color: #e2e8f0; margin: 0; padding: 16px; }
.container { max-width: 650px; margin: 0 auto; }
header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
h1 { font-size: 1.3rem; color: #fff; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 14px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
.btn { background: var(--accent); color: #000; border: none; padding: 6px 12px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.85rem; text-decoration: none; }
.btn-sec { background: #1f293d; color: #fff; }
</style></head><body>';
        echo '<div class="container">';
        echo '<header><div><a href="/profil" style="color:var(--accent);text-decoration:none;font-size:0.9rem">← Profil Kartu Pelajar</a><h1>👥 Teman Sekolah</h1></div>';
        echo '<button class="btn" onclick="addFriend()">+ Tambah Teman</button>';
        echo '</header>';

        if (!empty($pending)) {
            echo '<h3 style="color:#f59e0b;font-size:1rem;margin-bottom:8px">Permintaan Pertemanan Masuk (' . count($pending) . ')</h3>';
            foreach ($pending as $p) {
                echo '<div class="card" style="border-color:#f59e0b">';
                echo '<div><strong>' . htmlspecialchars($p['full_name'], ENT_QUOTES) . '</strong> (' . htmlspecialchars($p['class_name'] ?? 'Siswa', ENT_QUOTES) . ')</div>';
                echo '<div style="display:flex;gap:6px">';
                echo '<button class="btn" onclick="respondFriend(' . (int)$p['sender_id'] . ', \'accept\')">Terima</button>';
                echo '<button class="btn btn-sec" onclick="respondFriend(' . (int)$p['sender_id'] . ', \'reject\')">Tolak</button>';
                echo '</div>';
                echo '</div>';
            }
        }

        echo '<h3 style="color:#fff;font-size:1rem;margin:16px 0 8px 0">Teman Aktif (' . count($friends) . ')</h3>';
        if (empty($friends)) {
            echo '<div class="card" style="justify-content:center;color:#94a3b8">Belum ada teman terhubung. Kirim ajakan berteman ke teman sekelasmu!</div>';
        }
        foreach ($friends as $f) {
            echo '<div class="card">';
            echo '<div><strong style="color:#fff">' . htmlspecialchars($f['full_name'], ENT_QUOTES) . '</strong><br><span style="font-size:0.8rem;color:#94a3b8">' . htmlspecialchars($f['class_name'] ?? 'Siswa', ENT_QUOTES) . ' • ' . htmlspecialchars($f['status_text'] ?? '', ENT_QUOTES) . '</span></div>';
            echo '<div style="display:flex;gap:6px">';
            echo '<a href="/profil?id=' . (int)$f['id'] . '" class="btn btn-sec">Kartu ID</a>';
            echo '<a href="/obrolan" class="btn">Kirim Pesan</a>';
            echo '</div>';
            echo '</div>';
        }

        echo Footer::html();
        echo '</div>';
        echo '<script nonce="' . $nonce . '">
async function addFriend() {
  const targetId = prompt("Masukkan ID Siswa yang ingin diajak berteman:");
  if (!targetId) return;
  const r = await fetch("/api/friends/request", {
    method: "POST",
    headers: {"Content-Type":"application/json", "X-CSRF":"' . $csrf . '"},
    body: JSON.stringify({friend_id: parseInt(targetId)})
  });
  const d = await r.json();
  alert(d.message || "Permintaan dikirim.");
}
async function respondFriend(senderId, action) {
  const r = await fetch("/api/friends/respond", {
    method: "POST",
    headers: {"Content-Type":"application/json", "X-CSRF":"' . $csrf . '"},
    body: JSON.stringify({sender_id: senderId, action: action})
  });
  const d = await r.json();
  if (d.status === "success") location.reload();
  else alert(d.message || "Gagal memproses.");
}
</script></body></html>';
    }

    /** Render Chat page with Focus Mode (/obrolan) */
    public static function renderChatPage(): void
    {
        $nonce = Headers::nonce();
        $user = Auth::user();
        if (!$user) {
            header('Location: /');
            exit;
        }

        $userId = (int)$user['id'];
        // /obrolan?dm=ID → buka/buat percakapan langsung dengan pengguna itu.
        if (isset($_GET['dm'])) {
            $conv = Social::openDirect($userId, (int)$_GET['dm']);
            header('Location: /obrolan' . ($conv > 0 ? '?conv=' . $conv : ''));
            exit;
        }
        $conversations = Social::getConversations($userId);
        $activeConvId = (int)($_GET['conv'] ?? ($conversations[0]['id'] ?? 1));
        if (!Social::canAccess($activeConvId, $userId)) {
            $activeConvId = (int)($conversations[0]['id'] ?? 0);
        }
        $messages = Social::getMessages($activeConvId, $userId, 60);
        $isMod = Social::isModerator($userId);
        $isFocus = Social::isFocusModeActive();
        $csrf = Csrf::token();
        $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo Pwa::headTags();
        echo '<title>Obrolan — SMK Silber</title>';
        echo '<style nonce="' . $nonce . '">
:root { --bg: #090d16; --card: #131a29; --border: #1f293d; --accent: #22d3ee; }
body { font-family: system-ui, sans-serif; background: var(--bg); color: #e2e8f0; margin: 0; padding: 14px; }
.container { max-width: 800px; margin: 0 auto; display: flex; flex-direction: column; height: calc(100dvh - 28px); min-height: 420px; box-sizing: border-box; }
.header { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.conv-chips { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 8px; margin-bottom: 10px; -webkit-overflow-scrolling: touch; }
.chip { background: var(--card); border: 1px solid var(--border); padding: 8px 14px; border-radius: 999px; font-size: 0.85rem; color: #cbd5e1; text-decoration: none; white-space: nowrap; font-weight: 600; min-height: 38px; display: inline-flex; align-items: center; }
.chip.active { background: var(--accent); color: #000; border-color: var(--accent); }
.chat-window { flex-grow: 1; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 14px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; margin-bottom: 10px; }
.msg { position: relative; max-width: 85%; padding: 10px 14px; border-radius: 12px; font-size: 0.92rem; word-break: break-word; }
.msg-self { align-self: flex-end; background: #0369a1; color: #fff; border-bottom-right-radius: 2px; }
.msg-other { align-self: flex-start; background: #1e293b; color: #e2e8f0; border-bottom-left-radius: 2px; }
.msg-who { font-size: 0.75rem; font-weight: bold; margin-bottom: 2px; }
.msg-who a { color: #7dd3fc; text-decoration: none; }
.msg-meta { display: flex; justify-content: flex-end; align-items: center; gap: 8px; font-size: 0.68rem; opacity: 0.8; margin-top: 4px; }
.msg-del { background: none; border: 0; color: inherit; cursor: pointer; font-size: 0.78rem; padding: 2px 4px; min-height: 0; opacity: .75; }
.msg-del:hover { opacity: 1; color: #fca5a5; }
.msg.is-deleted { background: transparent; border: 1px dashed #475569; color: #94a3b8; font-style: italic; }
.msg.is-deleted.mod-view { border-color: #f59e0b; }
.mod-tag { display: block; font-size: 0.7rem; color: #fbbf24; font-style: normal; margin-top: 4px; }
.mod-note { font-size: 0.78rem; color: #fbbf24; margin: -4px 0 8px; }
.focus-alert { background: rgba(239,68,68,0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 8px 14px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
.input-bar { display: flex; gap: 8px; }
.input-bar input { flex-grow: 1; background: #0d121d; border: 1px solid var(--border); color: #fff; padding: 12px; border-radius: 8px; font-size: 16px; min-height: 44px; box-sizing: border-box; }
.input-bar button { background: var(--accent); color: #000; border: none; padding: 0 20px; border-radius: 8px; font-weight: bold; cursor: pointer; min-height: 44px; min-width: 64px; }
@media (max-width: 440px) {
  body { padding: 8px; }
  .container { height: calc(100dvh - 16px); }
  .chat-window { padding: 10px; }
  .msg { max-width: 90%; font-size: 0.88rem; padding: 8px 12px; }
}
</style></head><body>';
        echo '<div class="container">';
        echo '<div class="header"><div><a href="/" style="color:var(--accent);text-decoration:none;font-size:0.9rem">← Beranda</a><h1 style="margin:2px 0;font-size:1.3rem">💬 Obrolan</h1></div>';
        echo '<div style="display:flex;gap:12px"><a href="/profil?cari=1" style="color:var(--accent);font-size:0.85rem;text-decoration:none">🔎 Cari teman</a><a href="/profil" style="color:var(--accent);font-size:0.85rem;text-decoration:none">👤 Profil Saya</a></div>';
        echo '</div>';

        if ($isFocus) {
            echo '<div class="focus-alert"><span>🛑</span><div><strong>Mode Fokus Pembelajaran Aktif:</strong> Jam pelajaran sedang berlangsung. Saluran obrolan santai terkunci sementara.</div></div>';
        }
        if ($isMod) {
            echo '<p class="mod-note">🛡️ Mode moderator: kamu bisa menghapus pesan siapa pun dan tetap melihat isi pesan yang sudah dihapus (bingkai kuning).</p>';
        }

        echo '<div class="conv-chips">';
        foreach ($conversations as $c) {
            $act = ((int)$c['id'] === $activeConvId) ? 'active' : '';
            echo '<a href="/obrolan?conv=' . (int)$c['id'] . '" class="chip ' . $act . '">' . htmlspecialchars($c['name'], ENT_QUOTES) . '</a>';
        }
        echo '</div>';

        echo '<div class="chat-window" id="chatWindow" aria-live="polite"></div>';
        echo '<form id="chatForm" class="input-bar">';
        echo '<input id="msgInput" placeholder="Ketik pesan santun dan edukatif…" maxlength="1000" required autocomplete="off">';
        echo '<button id="btnSend" type="submit">Kirim</button>';
        echo '</form>';
        echo '</div>';
        echo Footer::html();
        echo '<script nonce="' . $nonce . '">
(function () {
  var ME = ' . $userId . ', MOD = ' . ($isMod ? 'true' : 'false') . ', CONV = ' . $activeConvId . ', CSRF = ' . json_encode($csrf) . ';
  var win = document.getElementById("chatWindow");
  var lastId = 0;
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"\']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "\'": "&#39;" }[c]; }); }
  function render(m) {
    var id = parseInt(m.id, 10), self = parseInt(m.sender_id, 10) === ME, del = !!parseInt(m.is_deleted || 0, 10);
    var row = document.getElementById("cmsg-" + id);
    if (!row) { row = document.createElement("div"); row.id = "cmsg-" + id; win.appendChild(row); }
    row.className = "msg " + (self ? "msg-self" : "msg-other") + (del ? " is-deleted" + (MOD ? " mod-view" : "") : "");
    var h = "";
    if (!self) h += "<div class=\"msg-who\"><a href=\"/profil?id=" + parseInt(m.sender_id, 10) + "\">" + esc(m.full_name || m.username) + "</a></div>";
    if (del && !MOD) h += "<div>🗑️ Pesan ini sudah dihapus</div>";
    else h += "<div>" + esc(m.message_text).replace(/\n/g, "<br>") + "</div>";
    if (del && MOD) h += "<span class=\"mod-tag\">🛡️ Dihapus" + (m.deleted_by_name ? " oleh " + esc(m.deleted_by_name) : "") + (m.deleted_at ? " · " + esc(String(m.deleted_at).substr(11, 5)) : "") + "</span>";
    h += "<div class=\"msg-meta\"><span>" + esc(String(m.created_at || "").substr(11, 5)) + "</span>";
    if (!del && (self || MOD)) h += "<button type=\"button\" class=\"msg-del px-plain\" data-del=\"" + id + "\" title=\"Hapus pesan\" aria-label=\"Hapus pesan\">🗑️</button>";
    h += "</div>";
    row.innerHTML = h;
    row.setAttribute("data-msg", JSON.stringify(m));
  }
  function stick(fn) { var atBottom = win.scrollHeight - win.scrollTop - win.clientHeight < 80; fn(); if (atBottom) win.scrollTop = win.scrollHeight; }
  var initial = ' . json_encode(array_values($messages), $flags) . ';
  if (!initial.length) win.innerHTML = "<div id=\"chatEmpty\" style=\"text-align:center;color:#94a3b8;margin:auto\">Belum ada pesan. Mulai percakapan sekarang!</div>";
  initial.forEach(function (m) { render(m); lastId = Math.max(lastId, parseInt(m.id, 10)); });
  win.scrollTop = win.scrollHeight;

  function poll() {
    fetch("/api/chat/messages?conv_id=" + CONV + (lastId > 0 ? "&since_id=" + lastId : ""), { credentials: "same-origin" })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || d.status !== "success") return;
        stick(function () {
          var e = document.getElementById("chatEmpty"); if (e && d.messages.length) e.remove();
          d.messages.forEach(function (m) { lastId = Math.max(lastId, parseInt(m.id, 10)); render(m); });
          (d.deleted_ids || []).forEach(function (id) {
            var row = document.getElementById("cmsg-" + id);
            if (row && !row.classList.contains("is-deleted")) { var m = JSON.parse(row.getAttribute("data-msg") || "{}"); m.is_deleted = 1; if (!MOD) m.message_text = ""; render(m); }
          });
        });
      }).catch(function () {});
  }
  setInterval(poll, 2500);

  win.addEventListener("click", function (e) {
    var b = e.target.closest ? e.target.closest("[data-del]") : null;
    if (!b) return;
    if (!confirm("Hapus pesan ini? Pesan akan hilang untuk semua anggota.")) return;
    var id = parseInt(b.getAttribute("data-del"), 10);
    fetch("/api/chat/delete", { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "X-CSRF": CSRF }, body: JSON.stringify({ message_id: id }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.status !== "success") { alert(d.message || "Gagal menghapus."); return; }
        var row = document.getElementById("cmsg-" + id), m = JSON.parse(row.getAttribute("data-msg") || "{}");
        m.is_deleted = 1; if (!MOD) m.message_text = ""; render(m);
      }).catch(function () { alert("Gagal koneksi."); });
  });

  document.getElementById("chatForm").addEventListener("submit", function (e) {
    e.preventDefault();
    var inp = document.getElementById("msgInput"), text = inp.value.trim(), btn = document.getElementById("btnSend");
    if (!text) return;
    btn.disabled = true;
    fetch("/api/chat/send", { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "X-CSRF": CSRF }, body: JSON.stringify({ conversation_id: CONV, message_text: text }) })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.status === "success") { inp.value = ""; poll(); } else alert(d.message || "Gagal mengirim pesan."); })
      .catch(function () { alert("Gagal koneksi."); })
      .then(function () { btn.disabled = false; inp.focus(); });
  });
})();
</script></body></html>';
    }
}

<?php
declare(strict_types=1);

namespace VC\Controllers;

use VC\Database;
use VC\I18n;
use VC\Security\Auth;
use VC\Security\Csrf;
use VC\Security\Headers;
use VC\View\Shell;

final class AuthController
{
    private const ICON_CAP = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/></svg>';
    private const ICON_SHIELD = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>';
    private const ICON_USER = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    private const ICON_LOCK = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
    private const ICON_ID = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M8 10h5M8 14h8"/><circle cx="16.5" cy="9.5" r="1.5"/></svg>';
    private const ICON_HASH = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9h16M4 15h16M10 3L8 21M16 3l-2 18"/></svg>';
    private const ICON_SCHOOL = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18M5 21V7l7-4 7 4v14"/><path d="M10 21v-4a2 2 0 0 1 4 0v4"/></svg>';
    private const ICON_EYE = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    private const ICON_EYE_OFF = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>';
    private const ICON_ARROW = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
    private const ICON_PLUS = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
    private const ICON_DL = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>';
    private const ICON_TG = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>';
    private const ICON_BACK = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>';
    private const ICON_CHECK = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>';

    /** Gaya main menu game pixel-art (video latar, menu kiri, tombol kayu). */
    private const AUTH_CSS_LINK = '<link rel="stylesheet" href="/assets/css/auth-pixel.css?v=7.9.6">';

    /**
     * Video latar + judul game. Video H.264 dulu (didekode hardware di hampir semua HP),
     * WebM cadangan; poster dipakai bila video gagal / mode hemat data / RAM sangat kecil.
     */
    private static function pixelBackdrop(string $nonce): string
    {
        // Latar bisa diganti pemilik di /media (video / gambar + suara video); default = video kota pixel.
        $cfg = ['login' => null, 'login_audio' => false];
        try {
            $cfg = \VC\Domain\SiteMedia::publicConfig();
        } catch (\Throwable $e) {
        }
        $lg = $cfg['login'];
        $audio = !empty($cfg['login_audio']) && ($lg === null || $lg['type'] === 'video');
        if ($lg !== null && $lg['type'] === 'image') {
            $bg = '<img class="px-login__bg" id="pxLoginImg" src="' . htmlspecialchars($lg['url'], ENT_QUOTES) . '" alt="" aria-hidden="true">';
        } else {
            $src = $lg !== null
                ? '<source src="' . htmlspecialchars($lg['url'], ENT_QUOTES) . '"' . ($lg['mime'] !== '' && $lg['mime'] !== 'video/quicktime' ? ' type="' . htmlspecialchars($lg['mime'], ENT_QUOTES) . '"' : '') . '>'
                : '<source src="/assets/video/login-bg.mp4" type="video/mp4"><source src="/assets/video/login-bg.webm" type="video/webm">';
            $bg = '<video class="px-login__bg" id="pxLoginVideo" autoplay muted loop playsinline preload="auto"'
                . ($lg === null ? ' poster="/assets/img/pixel/login-poster.jpg"' : '') . ($audio ? ' data-audio="1"' : '') . ' aria-hidden="true" tabindex="-1">'
                . $src . '</video>';
        }
        return $bg . '<div class="px-login__shade" aria-hidden="true"></div>'
            . '<script nonce="' . $nonce . '">(function(){var v=document.getElementById("pxLoginVideo");if(!v)return;'
            . 'var c=navigator.connection,m=navigator.deviceMemory;'
            . 'function off(){if(v.parentNode)v.parentNode.removeChild(v);}'
            . 'if(!v.hasAttribute("data-audio")&&((c&&c.saveData)||(m&&m<1))){off();return;}'
            . 'var s=v.querySelectorAll("source");if(s.length)s[s.length-1].addEventListener("error",off);'
            . 'var p=v.play&&v.play();if(p&&p.catch)p.catch(function(){});'
            // suara video: browser hanya mengizinkan setelah gestur pertama pengunjung
            . 'if(v.hasAttribute("data-audio")){var on=function(){v.muted=false;v.volume=0.8;var q=v.play();if(q&&q.catch)q.catch(function(){});'
            . '["pointerdown","keydown","touchstart"].forEach(function(e){document.removeEventListener(e,on,true);});};'
            . '["pointerdown","keydown","touchstart"].forEach(function(e){document.addEventListener(e,on,true);});}})();</script>'
            . '<h1 class="px-login__logo" aria-label="Virtual City SMK Silber"><b>VIRTUAL CITY</b><span>SMK SILBER</span></h1>';
    }

    private static function eyeButton(string $inputId, string $btnId): string
    {
        return '<button type="button" id="' . $btnId . '" class="au-eye" aria-label="Tampilkan atau sembunyikan kata sandi" aria-pressed="false" title="Tampilkan sandi" onclick="vcTogglePass(\'' . $inputId . '\',\'' . $btnId . '\')">'
            . '<span data-icon="eye">' . self::ICON_EYE . '</span><span data-icon="eyeoff" style="display:none">' . self::ICON_EYE_OFF . '</span>'
            . '</button>';
    }

    public static function renderRegister(): void
    {
        $user = Auth::user();
        if ($user !== null) {
            header('Location: /', true, 302);
            exit;
        }

        $nonce = Headers::nonce();
        try {
            $classes = Database::get()->query('SELECT id, name, grade FROM classes WHERE deleted_at IS NULL ORDER BY CASE grade WHEN "X" THEN 1 WHEN "XI" THEN 2 WHEN "XII" THEN 3 ELSE 4 END, name LIMIT 300')->fetchAll();
        } catch (\Throwable $e) {
            $classes = [];
        }

        Shell::start([
            'title' => 'Pendaftaran Siswa Baru — ' . I18n::t('app.name'),
            'active' => 'register',
            'topbar' => false,
            'bodyClass' => 'px-auth',
        ]);

        echo self::AUTH_CSS_LINK;
        echo '<div class="au-page">';
        echo self::pixelBackdrop($nonce);
        echo '<div class="au-card" id="regCard">';

        echo '<div class="au-brand">';
        echo '<img class="au-logo" src="/assets/img/logo-silber.jpg" width="58" height="58" alt="Logo SMK Silber">';
        echo '<h1 class="au-title">Pendaftaran Siswa Baru</h1>';
        echo '<p class="au-sub">Langsung aktif. Tanpa menunggu persetujuan.</p>';
        echo '</div>';

        // Stepper sederhana: 1 Identitas -> 2 Akun (satu form, field tidak berubah).
        echo '<div class="au-steps" aria-label="Langkah pendaftaran">';
        echo '<span class="au-step is-active" id="stepInd1"><span class="au-step__num">1</span>Identitas</span>';
        echo '<span class="au-step-sep" aria-hidden="true"></span>';
        echo '<span class="au-step" id="stepInd2"><span class="au-step__num">2</span>Akun</span>';
        echo '</div>';

        echo '<form id="formReg" onsubmit="vcRegisterSubmit(); return false;" novalidate>';
        echo '<input type="hidden" name="_csrf" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES) . '">';
        echo '<div class="sr-only" aria-hidden="true"><label>Isi kosong<input name="website" tabindex="-1" autocomplete="off"></label></div>';

        // ---- Langkah 1: Identitas ----
        echo '<div class="au-stepbody is-open" id="regStep1">';

        echo '<div class="au-field"><label class="au-label" for="regName">' . self::ICON_USER . 'Nama Lengkap</label>';
        echo '<input class="au-input" id="regName" name="full_name" required maxlength="100" autocomplete="name" placeholder="Nama lengkap sesuai sekolah"></div>';

        echo '<div class="au-field"><label class="au-label" for="regNis">' . self::ICON_HASH . 'NIS / NISN</label>';
        echo '<input class="au-input" id="regNis" name="nis" required inputmode="numeric" pattern="[0-9]*" placeholder="Nomor Induk Siswa (angka)"></div>';

        echo '<div class="au-field"><label class="au-label" for="regClass">' . self::ICON_SCHOOL . 'Kelas</label>';
        echo '<select class="au-input" id="regClass" name="class_id"><option value="0">Pilih kelas</option>';
        $curGrade = '';
        foreach ($classes as $c) {
            $g = (string)($c['grade'] ?? '');
            if ($g !== $curGrade) {
                if ($curGrade !== '') echo '</optgroup>';
                $curGrade = $g;
                $label = 'Kelas ' . ($g === 'X' ? '10 (X)' : ($g === 'XI' ? '11 (XI)' : ($g === 'XII' ? '12 (XII)' : $g)));
                echo '<optgroup label="' . htmlspecialchars($label, ENT_QUOTES) . '">';
            }
            echo '<option value="' . (int)$c['id'] . '">' . htmlspecialchars((string)$c['name'], ENT_QUOTES) . '</option>';
        }
        if ($curGrade !== '') echo '</optgroup>';
        echo '</select></div>';

        echo '<div class="au-stepnav">';
        echo '<button type="button" class="au-btn2 au-btn2--blue" id="btnRegNext" onclick="vcRegStep(2)">Lanjut ' . self::ICON_ARROW . '</button>';
        echo '</div>';
        echo '</div>';

        // ---- Langkah 2: Akun ----
        echo '<div class="au-stepbody" id="regStep2">';

        echo '<div class="au-field"><label class="au-label" for="regUser">' . self::ICON_ID . 'Username</label>';
        echo '<input class="au-input" id="regUser" name="username" required pattern="[a-z0-9._-]{4,32}" autocomplete="username" placeholder="huruf kecil / angka, 4-32 karakter">';
        echo '<p class="au-hint">Hanya huruf kecil, angka, titik, garis bawah, atau strip.</p></div>';

        echo '<div class="au-field"><label class="au-label" for="regPass">' . self::ICON_LOCK . 'Kata Sandi</label>';
        echo '<div class="au-passwrap">';
        echo '<input class="au-input" id="regPass" name="password" type="password" required minlength="8" autocomplete="new-password" placeholder="Minimal 8 karakter">';
        echo self::eyeButton('regPass', 'btnToggleRegPass');
        echo '</div><p class="au-hint">Gabungkan huruf besar, huruf kecil, dan angka agar kuat.</p>';
        echo '<div class="au-meter" aria-hidden="true"><div class="au-meter__bar" id="regMeterBar"></div></div>';
        echo '<p class="au-meter__label" id="regMeterLabel" aria-live="polite">Kekuatan sandi akan muncul di sini.</p></div>';

        echo '<div id="regFeedback" class="au-alert" role="alert" aria-live="polite"></div>';

        echo '<div class="au-stepnav">';
        echo '<button type="button" class="au-btn2 au-btn2--ghost" id="btnRegBack" onclick="vcRegStep(1)">' . self::ICON_BACK . ' Kembali</button>';
        echo '</div>';
        echo '</div>';

        echo '<button id="btnReg" class="au-cta" type="submit">' . self::ICON_PLUS . '<span id="btnRegLabel">Daftar Sekarang</span></button>';
        echo '</form>';

        echo '<div class="au-foot">';
        echo '<p style="margin:0">Sudah punya akun? <a href="/login">Masuk di sini</a></p>';
        echo '<div class="au-links">';
        echo '<a href="/download">' . self::ICON_DL . 'Pasang Aplikasi</a>';
        echo '<a href="/tutorial">' . self::ICON_ARROW . 'Tutorial</a>';
        echo '</div></div>';

        echo '<p class="au-copy">Virtual City SMK Silber &mdash; Portal Sekolah</p>';
        echo '</div>';
        echo '<nav class="px-login__extra" aria-label="Menu lain">'
            . '<a class="px-btn px-btn--dark" href="/login">🔑 Sudah punya akun? Masuk</a>'
            . '<a class="px-btn" href="/gabung">🏫 Gabung pakai Kode</a></nav>';
        echo '</div>';

        echo '<script nonce="' . $nonce . '">'
            . 'var vcRegStepNo = 1;'
            . 'function vcTogglePass(inputId, btnId) {'
            . '  var p = document.getElementById(inputId);'
            . '  var b = document.getElementById(btnId);'
            . '  if (!p || !b) return;'
            . '  var show = p.type === "password";'
            . '  p.type = show ? "text" : "password";'
            . '  b.setAttribute("aria-pressed", show ? "true" : "false");'
            . '  b.title = show ? "Sembunyikan sandi" : "Tampilkan sandi";'
            . '  var eye = b.querySelector("[data-icon=eye]"), off = b.querySelector("[data-icon=eyeoff]");'
            . '  if (eye && off) { eye.style.display = show ? "none" : ""; off.style.display = show ? "" : "none"; }'
            . '}'
            . 'function vcRegStep(n) {'
            . '  n = n === 2 ? 2 : 1;'
            . '  if (n === 2) {'
            . '    var s1 = document.getElementById("regStep1");'
            . '    var ok = true;'
            . '    if (s1) s1.querySelectorAll("input,select").forEach(function(el){ if (!el.reportValidity()) ok = false; });'
            . '    if (!ok) return;'
            . '  }'
            . '  vcRegStepNo = n;'
            . '  var b1 = document.getElementById("regStep1"), b2 = document.getElementById("regStep2");'
            . '  var i1 = document.getElementById("stepInd1"), i2 = document.getElementById("stepInd2");'
            . '  if (b1) b1.classList.toggle("is-open", n === 1);'
            . '  if (b2) b2.classList.toggle("is-open", n === 2);'
            . '  if (i1) { i1.classList.toggle("is-active", n === 1); i1.classList.toggle("is-done", n === 2); }'
            . '  if (i2) { i2.classList.toggle("is-active", n === 2); i2.classList.toggle("is-done", false); }'
            . '  var cta = document.getElementById("btnReg");'
            . '  if (cta) cta.style.display = n === 2 ? "" : "none";'
            . '  var fb = document.getElementById("regFeedback");'
            . '  if (fb && n === 1) { fb.className = "au-alert"; fb.textContent = ""; }'
            . '  var focusEl = document.getElementById(n === 2 ? "regUser" : "regName");'
            . '  if (focusEl && n === 2) focusEl.focus();'
            . '  window.scrollTo({ top: 0, behavior: "smooth" });'
            . '}'
            . 'function vcRegMsg(msg, cls) {'
            . '  var fb = document.getElementById("regFeedback");'
            . '  if (!fb) return;'
            . '  fb.className = "au-alert " + cls;'
            . '  fb.textContent = msg;'
            . '}'
            . 'function vcRegBusy(busy) {'
            . '  var btn = document.getElementById("btnReg");'
            . '  var lbl = document.getElementById("btnRegLabel");'
            . '  if (!btn) return;'
            . '  btn.disabled = busy;'
            . '  var spin = btn.querySelector(".au-spin");'
            . '  if (busy && !spin) {'
            . '    var s = document.createElement("span");'
            . '    s.className = "au-spin";'
            . '    btn.insertBefore(s, btn.firstChild);'
            . '  }'
            . '  if (!busy && spin && spin.parentNode) spin.parentNode.removeChild(spin);'
            . '  if (lbl) lbl.textContent = busy ? "Mendaftarkan..." : "Daftar Sekarang";'
            . '}'
            . 'var passInput = document.getElementById("regPass");'
            . 'if (passInput) {'
            . '  passInput.addEventListener("input", function() {'
            . '    var v = passInput.value;'
            . '    var bar = document.getElementById("regMeterBar");'
            . '    var lbl = document.getElementById("regMeterLabel");'
            . '    if (!bar || !lbl) return;'
            . '    var score = 0;'
            . '    if (v.length >= 8) score++;'
            . '    if (v.length >= 12) score++;'
            . '    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;'
            . '    if (/[0-9]/.test(v)) score++;'
            . '    if (/[^A-Za-z0-9]/.test(v)) score++;'
            . '    var conf = ['
            . '      [0, "Kekuatan sandi akan muncul di sini.", ""],'
            . '      [20, "Lemah", "#ef4444"],'
            . '      [40, "Cukup", "#f59e0b"],'
            . '      [60, "Baik", "#eab308"],'
            . '      [80, "Kuat", "#10b981"],'
            . '      [100, "Sangat Kuat", "#10b981"]'
            . '    ][v ? score : 0];'
            . '    bar.style.width = conf[0] + "%";'
            . '    bar.style.background = conf[2] || "transparent";'
            . '    lbl.textContent = conf[1];'
            . '  });'
            . '}'
            . 'async function vcRegisterSubmit() {'
            . '  var f = document.getElementById("formReg");'
            . '  if (!f || !f.reportValidity()) return;'
            . '  vcRegBusy(true);'
            . '  try {'
            . '    var fd = new FormData(f);'
            . '    var token = (f.querySelector("input[name=_csrf]") || {}).value || "";'
            . '    var r = await fetch("/api/register", { method: "POST", body: fd, headers: { "X-CSRF": token }, credentials: "same-origin" });'
            . '    var d = await r.json();'
            . '    if (r.ok && (d.status === "approved" || d.status === "success" || d.status === "pending")) {'
            . '      vcRegMsg(d.message || "Pendaftaran berhasil!", "au-alert--ok");'
            . '      if (window.UI && UI.toast) UI.toast(d.message || "Pendaftaran berhasil!", "success", 3000);'
            . '      setTimeout(function() { location.href = d.redirect || "/"; }, 700);'
            . '    } else {'
            . '      vcRegMsg(d.message || "Pendaftaran gagal. Periksa data Anda.", "au-alert--err");'
            . '      vcRegBusy(false);'
            . '    }'
            . '  } catch (e) {'
            . '    vcRegMsg("Koneksi ke server gagal. Coba lagi.", "au-alert--err");'
            . '    vcRegBusy(false);'
            . '  }'
            . '}'
            . 'document.addEventListener("DOMContentLoaded", function() { vcRegStep(1); });'
            . '</script>';

        Shell::end();
    }

    public static function renderLogin(): void
    {
        $user = Auth::user();
        if ($user !== null) {
            $staffRoles = ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru'];
            if (in_array($user['role'], $staffRoles, true)) {
                header('Location: /admin', true, 302);
            } else {
                header('Location: /', true, 302);
            }
            exit;
        }

        $nonce = Headers::nonce();
        $csrfToken = Csrf::token();
        $needLogin = !empty($_GET['need_login']);
        $isForcedAdmin = !empty($_GET['admin']);
        $rawRedirect = trim((string)($_GET['redirect'] ?? ''));
        $redirectUrl = (str_starts_with($rawRedirect, '/') && !str_starts_with($rawRedirect, '//')) ? $rawRedirect : '';

        Shell::start([
            'title' => 'Masuk Portal — ' . I18n::t('app.name'),
            'active' => 'login',
            'topbar' => false,
            'bodyClass' => 'px-auth',
        ]);

        // Menu utama ala game (START · GABUNG KODE · AUTHOR · HELP). Langsung ke form bila datang dari
        // halaman yang butuh login (redirect / need_login / admin) atau #masuk.
        $skipMenu = $isForcedAdmin || !empty($_GET['need_login']) || $redirectUrl !== '';
        echo self::AUTH_CSS_LINK;
        echo '<div class="au-page" id="auPage" data-stage="' . ($skipMenu ? 'form' : 'menu') . '">';
        echo self::pixelBackdrop($nonce);
        echo '<nav class="px-mainmenu" id="pxMainMenu" aria-label="Menu utama">'
            . '<button type="button" class="px-mm px-mm--start" data-mm="start" autofocus><i class="px-mm__ic">🎮</i><b>START<small>Masuk ke dunia sekolah</small></b><kbd>ENTER</kbd></button>'
            . '<a class="px-mm" href="/gabung"><i class="px-mm__ic">🏫</i><b>GABUNG KODE<small>Pakai kode kelas dari guru</small></b><kbd>›</kbd></a>'
            . '<a class="px-mm" href="/author"><i class="px-mm__ic">👤</i><b>AUTHOR<small>Kenali pembuat game ini</small></b><kbd>›</kbd></a>'
            . '<a class="px-mm" href="/bantuan"><i class="px-mm__ic">❓</i><b>HELP<small>Cara main &amp; bantuan</small></b><kbd>›</kbd></a>'
            . '<p class="px-mm__foot">Belum punya akun? <a href="/daftar">Daftar di sini</a></p>'
            . '</nav>';
        echo '<button type="button" class="px-backmenu" data-mm="menu">◀ MENU</button>';
        echo '<div class="au-card' . ($isForcedAdmin ? ' au-admin' : '') . '" id="loginCard">';

        echo '<div class="au-brand">';
        echo '<img class="au-logo" src="/assets/img/logo-silber.jpg" width="58" height="58" alt="Logo SMK Silber">';
        echo '<h1 class="au-title" id="loginTitle">' . ($isForcedAdmin ? 'Masuk Staf &amp; ROOT' : 'Masuk Portal Siswa') . '</h1>';
        echo '<p class="au-sub" id="loginSubtitle">' . ($isForcedAdmin ? 'Khusus guru &amp; administrator sekolah' : 'Dunia Virtual 3D &amp; Pembelajaran SMK Silber') . '</p>';
        echo '</div>';

        echo '<div class="au-tabs" role="tablist" aria-label="Pilih portal">';
        echo '<button type="button" id="tabSiswa" class="au-tab" role="tab" aria-selected="' . ($isForcedAdmin ? 'false' : 'true') . '" onclick="vcSetMode(false)">' . self::ICON_CAP . 'Siswa</button>';
        echo '<button type="button" id="tabAdmin" class="au-tab" role="tab" aria-selected="' . ($isForcedAdmin ? 'true' : 'false') . '" onclick="vcSetMode(true)">' . self::ICON_SHIELD . 'Staf &amp; ROOT</button>';
        echo '</div>';

        if ($needLogin) {
            echo '<div class="au-alert au-alert--info" role="status">' . self::ICON_LOCK . '<span>Silakan masuk terlebih dahulu untuk melanjutkan.</span></div>';
        } elseif ($isForcedAdmin) {
            echo '<div class="au-alert au-alert--warn" role="status">' . self::ICON_SHIELD . '<span>Area khusus guru &amp; administrator.</span></div>';
        }

        echo '<form id="formLoginPage" onsubmit="vcLoginSubmit(); return false;" novalidate>';
        echo '<input type="hidden" name="_csrf" id="loginCsrfPage" value="' . htmlspecialchars($csrfToken, ENT_QUOTES) . '">';
        echo '<input type="hidden" name="redirect" id="loginRedirectPage" value="' . htmlspecialchars($redirectUrl, ENT_QUOTES) . '">';

        echo '<div class="au-field"><label class="au-label" for="loginUserPage" id="labelUserWrap">' . self::ICON_USER . '<span id="labelUserPage">' . ($isForcedAdmin ? 'Username Guru / ROOT' : 'Username / NIS') . '</span></label>';
        echo '<input class="au-input" id="loginUserPage" name="username" autocomplete="username" placeholder="' . ($isForcedAdmin ? 'Username guru / administrator' : 'NIS atau username') . '" required autofocus></div>';

        echo '<div class="au-field"><label class="au-label" for="loginPassPage">' . self::ICON_LOCK . 'Kata Sandi</label>';
        echo '<div class="au-passwrap">';
        echo '<input class="au-input" id="loginPassPage" name="password" type="password" autocomplete="current-password" placeholder="Kata sandi Anda" required>';
        echo self::eyeButton('loginPassPage', 'btnTogglePassPage');
        echo '</div></div>';

        echo '<div id="loginFeedbackPage" class="au-alert" role="alert" aria-live="polite"></div>';

        echo '<button id="btnLoginSubmitPage" class="au-cta" type="submit">'
            . '<span id="btnLoginIcon">' . self::ICON_ARROW . '</span><span id="btnLoginLabel">' . ($isForcedAdmin ? 'Masuk Staf &amp; ROOT' : 'Masuk ke Portal Siswa') . '</span></button>';
        echo '</form>';

        echo '<div class="au-foot">';
        echo '<p style="margin:0">Belum punya akun siswa? <a href="/daftar">Daftar siswa baru</a></p>';
        echo '<div class="au-links">';
        echo '<a href="/download">' . self::ICON_DL . 'Unduh Aplikasi</a>';
        echo '<a href="/tutorial">' . self::ICON_ARROW . 'Tutorial</a>';
        echo '<a href="/telegram">' . self::ICON_TG . 'Telegram</a>';
        echo '</div></div>';

        echo '<p class="au-copy">Virtual City SMK Silber &mdash; Portal Sekolah</p>';
        echo '</div>';
        echo '<nav class="px-login__extra" aria-label="Menu lain">'
            . '<a class="px-btn" href="/gabung">🏫 Gabung pakai Kode Kelas</a>'
            . '<a class="px-btn px-btn--dark" href="/daftar">📝 Daftar Akun</a></nav>';
        echo '</div>';

        // Menu utama: START → form (tanpa muat ulang), ◀ MENU kembali; panah ↑↓ memilih tombol seperti game.
        echo '<script nonce="' . $nonce . '">(function(){var pg=document.getElementById("auPage");if(!pg)return;'
            . 'function stage(s){pg.setAttribute("data-stage",s);try{history.replaceState(null,"",s==="form"?"#masuk":location.pathname+location.search);}catch(e){}'
            . 'var f=s==="form"?document.getElementById("loginUserPage"):document.querySelector(".px-mm--start");if(f)setTimeout(function(){f.focus();},60);}'
            . 'if(location.hash==="#masuk")pg.setAttribute("data-stage","form");'
            . 'document.addEventListener("click",function(e){var b=e.target.closest?e.target.closest("[data-mm]"):null;if(!b)return;stage(b.getAttribute("data-mm")==="start"?"form":"menu");});'
            . 'document.addEventListener("keydown",function(e){if(pg.getAttribute("data-stage")!=="menu")return;var it=[].slice.call(document.querySelectorAll(".px-mm"));var i=it.indexOf(document.activeElement);'
            . 'if(e.key==="ArrowDown"||e.key==="ArrowUp"){e.preventDefault();i=(i+(e.key==="ArrowDown"?1:it.length-1))%it.length;it[i<0?0:i].focus();}});'
            . 'if(pg.getAttribute("data-stage")==="form"){var u=document.getElementById("loginUserPage");if(u)u.focus();}})();</script>';
        echo '<script nonce="' . $nonce . '">'
            . 'var vcIsAdminMode = ' . ($isForcedAdmin ? 'true' : 'false') . ';'
            . 'var vcCsrf = "' . htmlspecialchars($csrfToken, ENT_QUOTES) . '";'
            . 'function vcTogglePass(inputId, btnId) {'
            . '  var p = document.getElementById(inputId);'
            . '  var b = document.getElementById(btnId);'
            . '  if (!p || !b) return;'
            . '  var show = p.type === "password";'
            . '  p.type = show ? "text" : "password";'
            . '  b.setAttribute("aria-pressed", show ? "true" : "false");'
            . '  b.title = show ? "Sembunyikan sandi" : "Tampilkan sandi";'
            . '  var eye = b.querySelector("[data-icon=eye]"), off = b.querySelector("[data-icon=eyeoff]");'
            . '  if (eye && off) { eye.style.display = show ? "none" : ""; off.style.display = show ? "" : "none"; }'
            . '}'
            . 'function vcMsg(msg, cls) {'
            . '  var fb = document.getElementById("loginFeedbackPage");'
            . '  if (!fb) return;'
            . '  fb.className = "au-alert " + cls;'
            . '  fb.textContent = msg;'
            . '  if (window.UI && typeof UI.toast === "function") { UI.toast(msg, cls === "au-alert--err" ? "error" : "success"); }'
            . '}'
            . 'function vcSetMode(isAdmin) {'
            . '  vcIsAdminMode = !!isAdmin;'
            . '  var card = document.getElementById("loginCard");'
            . '  if (card) card.classList.toggle("au-admin", vcIsAdminMode);'
            . '  var tS = document.getElementById("tabSiswa"), tA = document.getElementById("tabAdmin");'
            . '  if (tS) tS.setAttribute("aria-selected", vcIsAdminMode ? "false" : "true");'
            . '  if (tA) tA.setAttribute("aria-selected", vcIsAdminMode ? "true" : "false");'
            . '  var t = document.getElementById("loginTitle");'
            . '  if (t) t.textContent = vcIsAdminMode ? "Masuk Staf & ROOT" : "Masuk Portal Siswa";'
            . '  var sub = document.getElementById("loginSubtitle");'
            . '  if (sub) sub.textContent = vcIsAdminMode ? "Khusus guru & administrator sekolah" : "Dunia Virtual 3D & Pembelajaran SMK Silber";'
            . '  var lbl = document.getElementById("labelUserPage");'
            . '  if (lbl) lbl.textContent = vcIsAdminMode ? "Username Guru / ROOT" : "Username / NIS";'
            . '  var u = document.getElementById("loginUserPage");'
            . '  if (u) u.placeholder = vcIsAdminMode ? "Username guru / administrator" : "NIS atau username";'
            . '  var bl = document.getElementById("btnLoginLabel");'
            . '  if (bl) bl.textContent = vcIsAdminMode ? "Masuk Staf & ROOT" : "Masuk ke Portal Siswa";'
            . '}'
            . 'function vcBusy(busy) {'
            . '  var btn = document.getElementById("btnLoginSubmitPage");'
            . '  if (!btn) return;'
            . '  btn.disabled = busy;'
            . '  var ic = document.getElementById("btnLoginIcon");'
            . '  if (busy) {'
            . '    if (ic && !ic.querySelector(".au-spin")) {'
            . '      ic.innerHTML = \'<span class="au-spin"></span>\';'
            . '    }'
            . '    var lbl = document.getElementById("btnLoginLabel");'
            . '    if (lbl) lbl.textContent = "Memproses...";'
            . '  } else {'
            . '    if (ic) ic.innerHTML = ' . json_encode(self::ICON_ARROW) . ';'
            . '    var lbl2 = document.getElementById("btnLoginLabel");'
            . '    if (lbl2) lbl2.textContent = vcIsAdminMode ? "Masuk Staf & ROOT" : "Masuk ke Portal Siswa";'
            . '  }'
            . '}'
            . 'async function vcLoginSubmit() {'
            . '  var f = document.getElementById("formLoginPage");'
            . '  if (!f || !f.reportValidity()) return;'
            . '  vcBusy(true);'
            . '  var url = vcIsAdminMode ? "/api/login_admin" : "/api/login";'
            . '  try {'
            . '    var fd = new FormData(f);'
            . '    if (!fd.get("_csrf")) fd.set("_csrf", vcCsrf);'
            . '    var r = await fetch(url, { method: "POST", body: fd, headers: { "X-CSRF": vcCsrf }, credentials: "same-origin" });'
            . '    var d = await r.json();'
            . '    if (d.status === "2fa_required") { vcShow2FA(); return; }'
            . '    if (r.ok) {'
            . '      vcMsg(d.message || "Login berhasil!", "au-alert--ok");'
            . '      setTimeout(function() { location.href = d.redirect || (vcIsAdminMode ? "/admin" : "/"); }, 400);'
            . '    } else {'
            . '      vcMsg(d.message || "Username atau kata sandi salah.", "au-alert--err");'
            . '      vcBusy(false);'
            . '    }'
            . '  } catch (e) {'
            . '    vcMsg("Koneksi ke server terputus. Coba lagi.", "au-alert--err");'
            . '    vcBusy(false);'
            . '  }'
            . '}'
            // ---- Kartu 2FA: 6 digit input besar (gabungan tetap masuk #inputTotpCode) ----
            . 'function vcOtpSync() {'
            . '  var v = "";'
            . '  for (var i = 1; i <= 6; i++) {'
            . '    var el = document.getElementById("otpDigit" + i);'
            . '    v += el ? (el.value || "").replace(/[^0-9]/g, "") : "";'
            . '  }'
            . '  var hidden = document.getElementById("inputTotpCode");'
            . '  if (hidden) hidden.value = v;'
            . '  return v;'
            . '}'
            . 'function vcOtpBind() {'
            . '  for (var i = 1; i <= 6; i++) {'
            . '    (function(idx) {'
            . '      var el = document.getElementById("otpDigit" + idx);'
            . '      if (!el) return;'
            . '      el.addEventListener("input", function() {'
            . '        el.value = el.value.replace(/[^0-9]/g, "").slice(0, 1);'
            . '        if (el.value) { var nx = document.getElementById("otpDigit" + (idx + 1)); if (nx) nx.focus(); }'
            . '        vcOtpSync();'
            . '      });'
            . '      el.addEventListener("keydown", function(e) {'
            . '        if (e.key === "Backspace" && !el.value && idx > 1) {'
            . '          var pv = document.getElementById("otpDigit" + (idx - 1));'
            . '          if (pv) { pv.focus(); pv.value = ""; vcOtpSync(); e.preventDefault(); }'
            . '        }'
            . '      });'
            . '      el.addEventListener("paste", function(e) {'
            . '        var txt = (e.clipboardData || window.clipboardData).getData("text") || "";'
            . '        var digits = txt.replace(/[^0-9]/g, "").slice(0, 6);'
            . '        if (!digits) return;'
            . '        e.preventDefault();'
            . '        for (var k = 1; k <= 6; k++) {'
            . '          var t = document.getElementById("otpDigit" + k);'
            . '          if (t) t.value = digits[k - 1] || "";'
            . '        }'
            . '        var last = document.getElementById("otpDigit" + Math.max(1, digits.length));'
            . '        if (last) last.focus();'
            . '        vcOtpSync();'
            . '      });'
            . '    })(i);'
            . '  }'
            . '  var first = document.getElementById("otpDigit1");'
            . '  if (first) first.focus();'
            . '}'
            . 'function vcShow2FA() {'
            . '  var card = document.getElementById("loginCard");'
            . '  if (!card) return;'
            . '  card.innerHTML = \''
            . '<div class="au-brand">'
            . '<span style="display:inline-flex;width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.1);border:1.5px solid rgba(255,255,255,.35);color:#F59E0B;align-items:center;justify-content:center">' . self::ICON_SHIELD . '</span>'
            . '<h1 class="au-title" style="font-size:1.2rem">Verifikasi Dua Faktor</h1>'
            . '<p class="au-sub">Masukkan 6 digit kode dari aplikasi Authenticator.</p>'
            . '</div>'
            . '<form id="form2FA" onsubmit="vcSubmit2FA(); return false;">'
            . '<input type="hidden" id="inputTotpCode" value="">'
            . '<div class="au-otp" role="group" aria-label="Kode verifikasi 6 digit">'
            . '<input class="au-otp__digit" id="otpDigit1" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="one-time-code" autofocus aria-label="Digit 1">'
            . '<input class="au-otp__digit" id="otpDigit2" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 2">'
            . '<input class="au-otp__digit" id="otpDigit3" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 3">'
            . '<input class="au-otp__digit" id="otpDigit4" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 4">'
            . '<input class="au-otp__digit" id="otpDigit5" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 5">'
            . '<input class="au-otp__digit" id="otpDigit6" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit 6">'
            . '</div>'
            . '<div id="feedback2FA" class="au-alert" role="alert" aria-live="polite"></div>'
            . '<button id="btnSubmit2FA" class="au-cta" type="submit"><span id="btn2FAIcon">' . self::ICON_ARROW . '</span><span id="btn2FALabel">Verifikasi &amp; Masuk</span></button>'
            . '</form>'
            . '<div class="au-center" style="margin-top:12px"><a class="au-back" href="/login?admin=1">Kembali ke login staf</a></div>\';'
            . '  vcOtpBind();'
            . '}'
            . 'function vc2FAMsg(msg, cls) {'
            . '  var fb = document.getElementById("feedback2FA");'
            . '  if (!fb) return;'
            . '  fb.className = "au-alert " + cls;'
            . '  fb.textContent = msg;'
            . '}'
            . 'async function vcSubmit2FA() {'
            . '  var code = vcOtpSync();'
            . '  var btn = document.getElementById("btnSubmit2FA");'
            . '  if (!code || code.length < 6 || !btn) { vc2FAMsg("Masukkan 6 digit kode dengan lengkap.", "au-alert--err"); return; }'
            . '  btn.disabled = true;'
            . '  var ic = document.getElementById("btn2FAIcon");'
            . '  if (ic) ic.innerHTML = \'<span class="au-spin"></span>\';'
            . '  var lbl = document.getElementById("btn2FALabel");'
            . '  if (lbl) lbl.textContent = "Memverifikasi...";'
            . '  try {'
            . '    var res = await fetch("/api/totp/verify", {'
            . '      method: "POST",'
            . '      headers: { "Content-Type": "application/json", "X-CSRF": vcCsrf },'
            . '      body: JSON.stringify({ code: code, _csrf: vcCsrf })'
            . '    });'
            . '    var d = await res.json();'
            . '    if (res.ok) {'
            . '      vc2FAMsg("Verifikasi berhasil! Mengarahkan...", "au-alert--ok");'
            . '      setTimeout(function() { location.href = d.redirect || "/admin"; }, 400);'
            . '    } else {'
            . '      vc2FAMsg(d.message || "Kode salah atau kedaluwarsa.", "au-alert--err");'
            . '      btn.disabled = false;'
            . '      if (ic) ic.innerHTML = ' . json_encode(self::ICON_ARROW) . ';'
            . '      if (lbl) lbl.textContent = "Verifikasi & Masuk";'
            . '    }'
            . '  } catch (e) {'
            . '    vc2FAMsg("Gagal menghubungi server.", "au-alert--err");'
            . '    btn.disabled = false;'
            . '    if (lbl) lbl.textContent = "Verifikasi & Masuk";'
            . '  }'
            . '}'
            . ($isForcedAdmin ? 'document.addEventListener("DOMContentLoaded", function(){ vcSetMode(true); });' : '')
            . '</script>';

        Shell::end();
    }
}

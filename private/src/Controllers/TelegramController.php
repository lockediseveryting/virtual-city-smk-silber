<?php
// TelegramController: lets a user link/unlink their Telegram account, with guest login support.
declare(strict_types=1);

namespace VC\Controllers;

use VC\Security\Auth;
use VC\Security\Csrf;
use VC\TgBot\AccountLink;
use VC\View\Shell;

final class TelegramController
{
    public static function render(): void
    {
        $user = Auth::user();
        $botUsername = (string)\vc_env('TELEGRAM_BOT_USERNAME', 'hackertkjsmksilber_bot');
        $csrf = Csrf::token();
        $nonce = \VC\Security\Headers::nonce();

        Shell::start(['user' => $user, 'active' => 'telegram', 'title' => 'Tautkan Telegram — SMK Silber']);

        $siteUrl = (string)\vc_env('SITE_URL', 'http://xox.smksilber.cloud');

        echo '<section class="section" style="max-width:640px;margin:0 auto">';
        echo '<div style="margin-bottom:16px">';
        echo '<div class="row" style="gap:8px;margin-bottom:6px;align-items:center"><span class="badge badge--neon">SMK SILBER</span><span class="badge badge--gold">Integrasi Bot Telegram</span></div>';
        echo '<h1 style="font-size:1.35rem;margin:0 0 8px">🔗 Tautkan Telegram ke Virtual City</h1>';
        echo '<p class="muted" style="font-size:0.88rem;line-height:1.5">Penautan akun Telegram bersifat <b>opsional (tidak wajib)</b>. Seluruh fitur Virtual City dapat dinikmati langsung melalui website browser tanpa harus memiliki Telegram. Penautan akun ini berguna bagi Anda yang ingin mengecek koin, jadwal, tugas, dan berinteraksi dengan AI Si Silber langsung lewat aplikasi Telegram.</p>';
        echo '</div>';

        // Information banner with exact URLs
        echo '<div class="card" style="background:rgba(2,132,199,0.12);border:1px solid rgba(56,189,248,0.3);margin-bottom:16px;padding:12px 14px">';
        echo '<div style="font-size:0.83rem;color:#cbd5e1;line-height:1.5">';
        echo '🌐 <b>Alamat Website Resmi:</b> <a href="' . htmlspecialchars($siteUrl, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="color:#38bdf8;font-weight:700">' . htmlspecialchars($siteUrl, ENT_QUOTES) . '</a><br>';
        echo '🤖 <b>Akun Bot Telegram Resmi:</b> <a href="https://t.me/' . htmlspecialchars($botUsername, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="color:#22d3ee;font-weight:700">@' . htmlspecialchars($botUsername, ENT_QUOTES) . '</a>';
        echo '</div></div>';

        if ($user === null) {
            // Guest View: explain and offer inline login + direct login link so users never get lost
            echo '<div class="card" style="border:2px solid rgba(2,132,199,0.5);box-shadow:0 12px 36px rgba(2,132,199,0.25);margin-bottom:16px">';
            echo '<div style="font-weight:700;font-size:1.1rem;color:#22d3ee;margin-bottom:6px">🔐 Masuk untuk Menautkan Akun</div>';
            echo '<p style="margin:0 0 14px;font-size:0.9rem;color:#cbd5e1">Untuk menghasilkan 8 karakter kode tautan unik, silakan login ke akun Virtual City Anda terlebih dahulu:</p>';
            
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">';
            echo '<a href="/login?redirect=/telegram" class="btn btn--gold" style="text-decoration:none;font-weight:700">🚀 Masuk ke Halaman Login (/login)</a>';
            echo '<a href="/daftar" class="btn btn--secondary" style="text-decoration:none">📝 Daftar Akun Baru</a>';
            echo '</div>';

            echo '<div style="font-size:0.8rem;color:#94a3b8;margin-bottom:8px">Atau masuk cepat langsung dari formulir di bawah ini:</div>';
            echo '<form id="formTgLogin" onsubmit="return false">';
            echo '<input type="hidden" name="_csrf" id="tgLoginCsrf" value="' . htmlspecialchars($csrf, ENT_QUOTES) . '">';
            echo '<div class="field"><label for="tgUser">👤 Username atau NIS</label>';
            echo '<input id="tgUser" name="username" autocomplete="username" placeholder="Masukkan NIS atau username..." required></div>';
            echo '<div class="field"><label for="tgPass">🔒 Password</label>';
            echo '<input id="tgPass" name="password" type="password" autocomplete="current-password" placeholder="••••••••" required></div>';
            echo '<button id="btnTgLogin" class="btn btn--block btn--primary" type="button" onclick="vcTgLogin()" style="margin-top:8px">Masuk &amp; Lanjutkan Penautan</button>';
            echo '</form>';
            echo '<div id="tgLoginMsg" style="margin-top:10px"></div>';
            echo '</div>';

            echo '<div class="card">';
            echo '<div style="font-weight:700">🤖 Bot Telegram Resmi</div>';
            echo '<p class="muted" style="margin:8px 0;font-size:0.88rem">Bot Telegram SMK Silber: <code>@' . htmlspecialchars($botUsername, ENT_QUOTES) . '</code>.<br>Setelah masuk, tekan tombol <b>"Buat Kode Tautan"</b> untuk mendapatkan kode 8 karakter unik yang siap dikirimkan ke bot Telegram.</p>';
            echo '<a href="https://t.me/' . htmlspecialchars($botUsername, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" class="btn btn--sm" style="text-decoration:none">Buka Bot @' . htmlspecialchars($botUsername, ENT_QUOTES) . ' ↗</a>';
            echo '</div>';
        } else {
            // Authenticated View
            $link = AccountLink::linkForUser((int)$user['id']);

            if ($link !== null) {
                echo '<div class="card" style="border:1px solid #10b981;background:rgba(16,185,129,0.06);box-shadow:0 8px 24px rgba(16,185,129,0.15)">';
                echo '<div style="font-weight:700;color:#34d399;font-size:1.05rem">✅ Akun Telegram Berhasil Tertaut</div>';
                echo '<div class="muted" style="font-size:0.88rem;margin:10px 0;line-height:1.6">';
                echo 'Akun Web: <b>' . htmlspecialchars((string)($user['full_name'] ?: $user['username']), ENT_QUOTES) . '</b> (' . htmlspecialchars((string)$user['role'], ENT_QUOTES) . ')<br>';
                echo 'Telegram User ID: <code>' . htmlspecialchars((string)$link['tg_user_id'], ENT_QUOTES) . '</code><br>';
                if (!empty($link['tg_username'])) {
                    echo 'Telegram Username: <b>@' . htmlspecialchars((string)$link['tg_username'], ENT_QUOTES) . '</b><br>';
                }
                echo 'Ditautkan pada: ' . htmlspecialchars(substr((string)$link['linked_at'], 0, 16), ENT_QUOTES) . '</div>';
                echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">';
                echo '<a href="https://t.me/' . htmlspecialchars($botUsername, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" class="btn btn--gold" style="text-decoration:none">🤖 Buka Bot @' . htmlspecialchars($botUsername, ENT_QUOTES) . '</a>';
                echo '<button class="btn btn--danger btn--sm" type="button" onclick="vcTgUnlink()">Putuskan Tautan Akun</button>';
                echo '</div></div>';
            } else {
                echo '<div class="card" style="border:2px solid rgba(2,132,199,0.5);box-shadow:0 12px 36px rgba(2,132,199,0.25)">';
                echo '<div style="font-weight:700;font-size:1.05rem;color:#38bdf8">Status Akun: Belum Tertaut</div>';
                echo '<p style="margin:8px 0 12px;font-size:0.9rem;color:#cbd5e1">Ikuti langkah-langkah mudah di bawah ini untuk menghubungkan akun Virtual City Anda ke Telegram:</p>';
                
                echo '<div style="background:rgba(15,23,42,0.8);border:1px solid rgba(56,189,248,0.25);border-radius:12px;padding:14px;margin-bottom:14px">';
                echo '<ol style="margin:0;padding-left:20px;line-height:1.8;font-size:0.88rem;color:#e2e8f0">';
                echo '<li>Klik tombol emas <b>"Buat Kode Tautan"</b> di bawah ini untuk mendapatkan <b>8 karakter kode unik</b> (berlaku 10 menit).</li>';
                echo '<li>Setelah kode muncul, klik tombol <b>"🚀 Tautkan Otomatis ke Telegram"</b> untuk langsung membuka bot dan menautkan 1-klik.</li>';
                echo '<li><b>Atau secara manual:</b> Buka chat bot Telegram <a href="https://t.me/' . htmlspecialchars($botUsername, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="color:#22d3ee;font-weight:700">@' . htmlspecialchars($botUsername, ENT_QUOTES) . '</a> lalu kirim perintah <code>/link KODE</code> (atau kirim langsung kodenya).</li>';
                echo '</ol></div>';

                echo '<button id="btnMakeCode" class="btn btn--gold btn--block" type="button" onclick="vcTgCode()" style="font-size:1rem;padding:12px">⚡ Buat Kode Tautan Sekarang</button>';
                echo '<div id="tgCodeBox" style="margin-top:14px"></div>';
                echo '</div>';
            }
        }
        echo '</section>';

        Shell::end(['user' => $user, 'active' => 'telegram', 'scripts' => []]);

        echo '<script nonce="' . $nonce . '">' . "\n"
            . 'var botUsername = ' . json_encode($botUsername) . ';' . "\n"
            . 'function vcTgCsrf(){var m=document.querySelector(\'meta[name="csrf"]\');return m?m.content:"";}' . "\n"
            . 'async function vcTgLogin(){' . "\n"
            . '  var b=document.getElementById("btnTgLogin");var msg=document.getElementById("tgLoginMsg");' . "\n"
            . '  var f=document.getElementById("formTgLogin");if(!f)return;' . "\n"
            . '  b.disabled=true;b.textContent="Memproses...";msg.innerHTML="";' . "\n"
            . '  try{' . "\n"
            . '    var fd=new FormData(f);if(!fd.get("_csrf"))fd.set("_csrf",vcTgCsrf());' . "\n"
            . '    var r=await fetch("/api/login",{method:"POST",body:fd,headers:{"X-CSRF":vcTgCsrf()},credentials:"same-origin"});' . "\n"
            . '    var d=await r.json();' . "\n"
            . '    if(r.ok){location.reload();}' . "\n"
            . '    else{msg.innerHTML="<span style=\'color:#ef4444\'>"+(d.message||"Login gagal.")+"</span>";b.disabled=false;b.textContent="Masuk & Lanjutkan Penautan";}' . "\n"
            . '  }catch(e){msg.innerHTML="<span style=\'color:#ef4444\'>Gangguan koneksi ke server.</span>";b.disabled=false;b.textContent="Masuk & Lanjutkan Penautan";}' . "\n"
            . '}' . "\n"
            . 'async function vcTgCode(){' . "\n"
            . '  var box=document.getElementById("tgCodeBox");box.innerHTML="<div class=\'muted\' style=\'text-align:center;padding:12px\'>Membuat kode tautan resmi... ⏳</div>";' . "\n"
            . '  try{' . "\n"
            . '    var r=await fetch("/api/telegram/link-code",{method:"POST",headers:{"Content-Type":"application/json","X-CSRF":vcTgCsrf()},body:JSON.stringify({_csrf:vcTgCsrf()})});' . "\n"
            . '    var d=await r.json();' . "\n"
            . '    if(d.code){' . "\n"
            . '      var c = d.code;' . "\n"
            . '      box.innerHTML="<div style=\'background:rgba(15,23,42,0.95);border:2px solid #0284c7;padding:18px;border-radius:14px;box-shadow:0 8px 30px rgba(2,132,199,0.3)\'>" '
            . '      + "<div style=\'font-size:0.85rem;color:#94a3b8;font-weight:700\'>KODE TAUTAN RESMI (Berlaku 10 Menit):</div>" '
            . '      + "<div style=\'display:flex;align-items:center;justify-content:space-between;background:rgba(2,132,199,0.15);border:1px dashed #38bdf8;padding:10px 16px;border-radius:10px;margin:10px 0\'>" '
            . '      + "<span style=\'font-size:2rem;font-weight:800;letter-spacing:5px;color:#22d3ee;font-family:monospace\'>"+c+"</span>" '
            . '      + "<button type=\'button\' class=\'btn btn--sm btn--secondary\' onclick=\'vcCopyTgCode(\""+c+"\")\' style=\'padding:8px 12px;font-size:0.85rem\'>📋 Salin</button>" '
            . '      + "</div>" '
            . '      + "<div style=\'display:flex;gap:10px;flex-wrap:wrap;margin:14px 0 10px\'>" '
            . '      + "<a href=\'https://t.me/"+botUsername+"?start=link_"+c+"\' target=\'_blank\' rel=\'noopener noreferrer\' class=\'btn btn--gold btn--block\' style=\'text-decoration:none;font-weight:700;font-size:0.95rem;text-align:center;padding:12px\'>🚀 Tautkan Otomatis (1-Klik ke Telegram)</a>" '
            . '      + "</div>" '
            . '      + "<div style=\'font-size:0.83rem;color:#cbd5e1;line-height:1.5;margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,0.1)\'>" '
            . '      + "💬 <b>Atau cara manual:</b> Kirim langsung di chat bot <b>@"+botUsername+"</b>: <code>/link "+c+"</code> (atau cukup ketik <code>"+c+"</code> saja)." '
            . '      + "</div></div>";' . "\n"
            . '    }else{box.innerHTML="<span style=\'color:#ef4444\'>Gagal: "+(d.message||"Coba lagi.")+"</span>";}' . "\n"
            . '  }catch(e){box.innerHTML="<span style=\'color:#ef4444\'>Gangguan koneksi ke server.</span>";}' . "\n"
            . '}' . "\n"
            . 'function vcCopyTgCode(text){' . "\n"
            . '  if(navigator.clipboard && navigator.clipboard.writeText){' . "\n"
            . '    navigator.clipboard.writeText(text).then(function(){ if(window.UI&&UI.toast) UI.toast("Kode tautan berhasil disalin: "+text, "success"); else alert("Kode disalin!"); });' . "\n"
            . '  } else {' . "\n"
            . '    prompt("Salin kode tautan berikut:", text);' . "\n"
            . '  }' . "\n"
            . '}' . "\n"
            . 'async function vcTgUnlink(){' . "\n"
            . '  if(!confirm("Yakin ingin memutuskan tautan Telegram dari akun Virtual City ini?"))return;' . "\n"
            . '  await fetch("/api/telegram/unlink",{method:"POST",headers:{"Content-Type":"application/json","X-CSRF":vcTgCsrf()},body:JSON.stringify({_csrf:vcTgCsrf()})});' . "\n"
            . '  location.reload();' . "\n"
            . '}' . "\n"
            . '</script>';
    }
}

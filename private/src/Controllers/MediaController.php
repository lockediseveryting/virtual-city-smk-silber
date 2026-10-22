<?php
// /media — pengatur media khusus PEMILIK (coffelatte314): latar halaman login (video/gambar + suara video)
// & musik latar per tempat (login, dashboard siswa, dashboard admin, Dunia 3D, Dunia Pixel).
// Pustaka menyimpan semua unggahan; "Bawaan" tidak menghapus apa pun.
declare(strict_types=1);

namespace VC\Controllers;

use VC\Domain\SiteMedia;
use VC\Security\Auth;
use VC\Security\Headers;
use VC\View\Shell;
use VC\View\UiTheme;

final class MediaController
{
    public static function render(): void
    {
        $nonce = Headers::nonce();
        $data = ['state' => SiteMedia::state(), 'slots' => SiteMedia::MUSIC_SLOTS, 'dir' => SiteMedia::DIR, 'max' => SiteMedia::MAX_BYTES,
            'theme' => UiTheme::setting(), 'auto' => UiTheme::autoTheme()];
        $thCss = '';
        $thHtml = '<button type="button" class="md-th mdth-' . UiTheme::autoTheme() . '" data-theme="auto"><b>🔄 Otomatis ikut musim</b><small>Sekarang: ' . htmlspecialchars(UiTheme::THEMES[UiTheme::autoTheme()][0], ENT_QUOTES) . '</small>'
            . '<span class="md-th__demo"><i class="px-btn">Tombol</i><i class="px-btn px-btn--gold">Utama</i></span></button>';
        foreach (UiTheme::THEMES as $k => $t) {
            $thCss .= UiTheme::vars($k, '.mdth-' . $k);
            $thHtml .= '<button type="button" class="md-th mdth-' . $k . '" data-theme="' . $k . '"><b>' . $t[1] . ' ' . htmlspecialchars($t[0], ENT_QUOTES) . '</b>'
                . '<span class="md-th__demo"><i class="px-btn">Tombol</i><i class="px-btn px-btn--gold">Utama</i><i class="px-btn px-btn--dark">Lain</i></span></button>';
        }
        Shell::start(['title' => 'Media Login & Musik — Virtual City', 'active' => 'admin', 'user' => Auth::user(),
            'head' => '<style nonce="' . $nonce . '">' . self::CSS . $thCss . '</style>']);
        echo '<div class="md-wrap">';
        echo '<header class="md-head px-panel"><h1>🎬 Media, 🎵 Musik &amp; 🎨 Tema</h1><p>Khusus pemilik (coffelatte314). Semua berkas yang diunggah tersimpan di <b>Pustaka</b> — '
            . 'kembali ke bawaan tidak menghapus apa pun. Hapus permanen hanya lewat tombol 🗑️.</p></header>';
        echo '<section class="px-panel md-sec"><h2>⬆️ Unggah media baru</h2>'
            . '<form id="mdUp" class="md-up"><input type="file" id="mdFile" accept="video/mp4,video/webm,image/jpeg,image/png,image/webp,image/gif,audio/mpeg,audio/ogg,audio/mp4,audio/x-m4a,audio/wav,.mp3,.m4a,.ogg,.wav" required>'
            . '<input id="mdTitle" maxlength="80" placeholder="Judul (opsional), mis. Latar Ramadan 2026">'
            . '<button class="px-btn px-btn--gold" type="submit">Unggah</button></form>'
            . '<div class="md-prog" hidden><i></i></div><p id="mdUpMsg" class="md-msg" aria-live="polite"></p>'
            . '<p class="md-hint">Video MP4 (H.264) paling lancar di semua HP; maks. ' . (SiteMedia::MAX_BYTES / 1048576) . ' MB. Musik: MP3 / OGG / M4A. '
            . 'Gunakan hanya media milik sendiri / berlisensi (hak cipta).</p></section>';
        echo '<section class="px-panel md-sec"><h2>🎨 Tema tombol &amp; panel (semua pengguna)</h2>'
            . '<p class="md-hint" style="margin:0 0 10px">Mengganti warna SEMUA tombol, panel &amp; menu kayu di seluruh halaman untuk semua siswa, guru &amp; admin. ' . count(UiTheme::THEMES) . ' tema + otomatis ikut musim / hari raya.</p>'
            . '<div class="md-themes" id="mdThemes">' . $thHtml . '</div></section>';
        // ---- Latar dashboard (semua pengguna) ----
        $dash = \VC\Domain\DashScene::clientConfig();
        $sea = ['auto' => '🔄 Otomatis (ikut tanggal)', 'semi' => '🌸 Musim Semi', 'panas' => '☀️ Musim Panas', 'gugur' => '🍂 Musim Gugur', 'dingin' => '❄️ Musim Dingin',
            'ramadan' => '🌙 Ramadan', 'idulfitri' => '🕌 Idul Fitri', 'iduladha' => '🐐 Idul Adha', 'merdeka' => '🇮🇩 Kemerdekaan', 'natal' => '🎄 Natal', 'tahunbaru' => '🎆 Tahun Baru',
            'imlek' => '🧧 Imlek', 'nyepi' => '🕉️ Nyepi', 'waisak' => '🪷 Waisak', 'paskah' => '🥚 Paskah'];
        $day = ['auto' => '🔄 Otomatis (jam perangkat)', 'pagi' => '🌅 Pagi', 'siang' => '☀️ Siang', 'sore' => '🌇 Sore', 'malam' => '🌙 Malam'];
        $opt = static function (array $list, string $cur): string { $h = ''; foreach ($list as $k => $v) { $h .= '<option value="' . $k . '"' . ($k === $cur ? ' selected' : '') . '>' . $v . '</option>'; } return $h; };
        echo '<section class="px-panel md-sec" id="dash"><h2>🏞️ Latar dashboard (semua pengguna)</h2>'
            . '<p class="md-hint" style="margin:0 0 10px">Mengganti latar dashboard SEMUA siswa, guru &amp; admin. Karakter otomatis berdiri di jalan/lantai adegan, latar bergerak, dan warnanya mengikuti pagi/siang/sore/malam.</p>'
            . '<div class="md-row"><b>Musim</b><select id="dsSeason">' . $opt($sea, $dash['season']) . '</select></div>'
            . '<div class="md-row" style="margin-top:6px"><b>Waktu</b><select id="dsDay">' . $opt($day, $dash['daypart']) . '</select></div>'
            . '<label class="md-check"><input type="checkbox" id="dsFx"' . ($dash['effects'] === 'on' ? ' checked' : '') . '> 🌗 Efek musim &amp; waktu di latar custom (gelap saat malam, warna senja, salju/kembang api). Matikan agar latar selalu terang &amp; kontras — animasi latar &amp; karakter tetap jalan.</label>'
            . '<div class="md-grid" id="dsGrid" style="margin-top:10px"><button type="button" class="md-card ds-card' . ($dash['scene'] === 'auto' ? ' is-on' : '') . '" data-scene="auto"><span class="md-thumb">🔄 Otomatis</span><b>Otomatis ikut musim</b><small>Bergilir tiap hari dari adegan yang cocok</small></button>';
        $cards = '';
        foreach ($dash['scenes'] as $sid => $sc) {
            $cards .= '<button type="button" class="md-card ds-card' . ($dash['scene'] === $sid ? ' is-on' : '') . '" data-scene="' . htmlspecialchars($sid, ENT_QUOTES) . '">'
                . '<img class="md-thumb" src="' . htmlspecialchars($sc['img'], ENT_QUOTES) . '" alt="" loading="lazy">'
                . '<b>' . ($sc['type'] === 'video' ? '🎞️ ' : '🖼️ ') . htmlspecialchars($sc['title'], ENT_QUOTES) . '</b>'
                . '<small>Musim: ' . htmlspecialchars(implode(', ', $sc['seasons']), ENT_QUOTES) . ' · <a href="/?adegan=' . htmlspecialchars($sid, ENT_QUOTES) . '" target="_blank" rel="noopener">pratinjau</a></small></button>';
        }
        echo $cards . '</div></section>';
        // ---- Event Kostum Anime ----
        $cos = \VC\Domain\CostumeEvent::catalog(true);
        $an = '';
        foreach ($cos['anime'] as $a) {
            $an .= '<label class="md-check" style="margin-top:6px"><input type="checkbox" data-cos-anime="' . htmlspecialchars($a['id'], ENT_QUOTES) . '"' . ($a['on'] ? ' checked' : '') . '> '
                . $a['icon'] . ' <b>' . htmlspecialchars($a['title'], ENT_QUOTES) . '</b> <small>(' . count($a['chars']) . ' karakter: '
                . htmlspecialchars(implode(', ', array_slice(array_column($a['chars'], 'name'), 0, 6)), ENT_QUOTES) . '…)</small></label>';
        }
        echo '<section class="px-panel md-sec" id="kostum"><h2>🎭 Event Kostum Anime</h2>'
            . '<p class="md-hint" style="margin:0 0 10px">Kostum muncul di penyunting karakter (tab 🎭 Kostum Anime) untuk semua pengguna, lalu tampil di dashboard, Dunia Pixel &amp; 3D. Kostum = gaya buatan sendiri (warna, rambut, pakaian); nama karakter milik pemegang hak cipta.</p>'
            . '<label class="md-check"><input type="checkbox" id="cosOn"' . ($cos['enabled'] ? ' checked' : '') . '> Event aktif</label>'
            . '<div class="md-row" style="margin-top:8px"><b>Nama event</b><input id="cosTitle" maxlength="60" value="' . htmlspecialchars($cos['title'], ENT_QUOTES) . '" style="flex:1;min-width:180px;font-size:16px"><button type="button" class="px-btn" id="cosTitleSave">Simpan</button></div>'
            . $an . '</section>';
        echo '<section class="px-panel md-sec"><h2>🖼️ Latar halaman login</h2><div id="mdLogin" class="md-grid"></div>'
            . '<label class="md-check"><input type="checkbox" id="mdAudio"> 🔊 Putar suara bawaan video di halaman login (musik login otomatis diam; suara mulai setelah pengunjung mengetuk layar — aturan browser)</label>'
            . '<p class="md-hint"><a href="/login?preview=1" target="_blank" rel="noopener">👁️ Lihat halaman login</a> (buka di jendela privat bila sudah login).</p></section>';
        echo '<section class="px-panel md-sec"><h2>🎵 Musik latar</h2>'
            . '<p class="md-hint" style="margin:0 0 10px"><button type="button" class="px-btn px-btn--gold" id="mdMusicUp">⬆️ Unggah lagu MP3</button> lalu pilih lagunya di tempat yang diinginkan.</p>'
            . '<div id="mdMusic" class="md-music"></div>'
            . '<p class="md-hint">"Bawaan" = musik chiptune asli game. Tombol 🎵/🔇 di tiap halaman tetap bisa mematikan musik per perangkat.</p></section>';
        echo '<section class="px-panel md-sec"><h2>📚 Pustaka</h2><div id="mdLib" class="md-lib"></div></section>';
        echo '<p id="mdMsg" class="md-msg md-float" aria-live="polite"></p></div>';
        echo '<script type="application/json" id="mdData">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        echo '<script nonce="' . $nonce . '">' . self::JS . self::DASH_JS . '</script>';
        Shell::end();
    }

    private const CSS = '.md-wrap{max-width:900px;margin:0 auto;padding:14px;display:flex;flex-direction:column;gap:12px}'
        . '.md-head,.md-sec{padding:14px!important}.md-head h1{margin:0 0 6px}.md-sec h2{margin:0 0 10px;font-size:18px}.md-hint{font-size:13px;opacity:.8;margin:8px 0 0}'
        . '.md-up{display:flex;flex-wrap:wrap;gap:8px}.md-up input{flex:1;min-width:200px;font-size:16px}.md-prog{height:12px;margin-top:8px;border:2px solid #20130a;background:rgba(0,0,0,.3)}.md-prog i{display:block;height:100%;width:0;background:#4ade80;transition:width .2s}'
        . '.md-msg{min-height:1.2em;margin:6px 0 0;font-weight:700}.md-float{position:sticky;bottom:10px;text-align:center}'
        . '.md-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}'
        . '.md-card{display:flex;flex-direction:column;gap:6px;padding:8px;background:rgba(0,0,0,.25);border:3px solid transparent}.md-card.is-on{border-color:#4ade80}'
        . '.md-thumb{aspect-ratio:16/9;width:100%;object-fit:cover;background:#111;display:grid;place-items:center;color:#fff6e0;font-size:13px}'
        . '.md-card b{font-size:14px;word-break:break-word}.md-check{display:flex;gap:8px;align-items:flex-start;margin-top:12px;font-size:14px}.md-check input{width:20px;height:20px;flex:0 0 auto}'
        . '.md-music{display:grid;gap:8px}.md-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:8px;background:rgba(0,0,0,.22)}.md-row b{min-width:170px}.md-row select{flex:1;min-width:180px;font-size:16px}'
        . '.md-lib{display:grid;gap:8px}.md-item{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:8px;background:rgba(0,0,0,.22)}.md-item input{flex:1;min-width:160px;font-size:16px}.md-item small{opacity:.75}'
        . '.md-tag{padding:2px 6px;border:2px solid #d9a441;font-size:12px}'
        . '.ds-card{all:unset;box-sizing:border-box;cursor:pointer;display:flex;flex-direction:column;gap:6px;padding:8px;background:rgba(0,0,0,.25);border:3px solid transparent;color:inherit;font-family:inherit}.ds-card:hover{border-color:#d9a441}.ds-card.is-on{border-color:#4ade80;box-shadow:0 0 0 2px #4ade80}.ds-card small{font-size:12px;opacity:.8}.ds-card a{color:#fde68a}'
        . '.md-themes{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px}'
        . '.md-th{all:unset;box-sizing:border-box;cursor:pointer;display:flex;flex-direction:column;gap:6px;padding:10px;background:var(--px-ink-3);color:var(--px-cream,#fff1d0);border:3px solid var(--px-wood-2);font-family:var(--px-font)}'
        . '.md-th:hover{border-color:var(--px-gold)}.md-th.is-on{border-color:#4ade80;box-shadow:0 0 0 3px #4ade80}.md-th b{font-size:15px;color:var(--px-gold)}.md-th small{font-size:12px;opacity:.8}'
        . '.md-th__demo{display:flex;flex-wrap:wrap;gap:2px;pointer-events:none}.md-th__demo .px-btn{font-style:normal;min-height:30px!important;padding:3px 8px!important;font-size:12px!important;margin:3px!important}';

    /** Latar dashboard: pilih adegan / musim / waktu → POST /api/media/action {action:'dash'}. */
    private const DASH_JS = <<<'JS'
(function(){
var C=(document.querySelector("meta[name=csrf]")||{}).content||"";
function send(b,btn){b.action="dash";b._csrf=C;
  return fetch("/api/media/action",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-CSRF":C},body:JSON.stringify(b)})
  .then(function(r){return r.json();}).then(function(d){var m=document.getElementById("mdMsg");if(m){m.textContent=d.message||(d.status==="ok"?"Tersimpan.":"Gagal.");m.style.color=d.status==="ok"?"#bbf7d0":"#fca5a5";}
    if(d.status==="ok"&&btn){document.querySelectorAll(".ds-card").forEach(function(c){c.classList.toggle("is-on",c===btn);});}}).catch(function(){});}
var grid=document.getElementById("dsGrid");
if(grid)grid.addEventListener("click",function(e){if(e.target.closest("a"))return;var c=e.target.closest(".ds-card");if(c)send({scene:c.getAttribute("data-scene")},c);});
function cos(b){b.action="costume";b._csrf=C;return fetch("/api/media/action",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-CSRF":C},body:JSON.stringify(b)}).then(function(r){return r.json();}).then(function(d){var m=document.getElementById("mdMsg");if(m){m.textContent=d.message||"";m.style.color=d.status==="ok"?"#bbf7d0":"#fca5a5";}});}
var co=document.getElementById("cosOn");if(co)co.addEventListener("change",function(){cos({enabled:co.checked?1:0});});
var ct=document.getElementById("cosTitleSave");if(ct)ct.addEventListener("click",function(){cos({title:document.getElementById("cosTitle").value});});
document.querySelectorAll("[data-cos-anime]").forEach(function(el){el.addEventListener("change",function(){cos({anime:el.getAttribute("data-cos-anime"),on:el.checked?1:0});});});
var fx=document.getElementById("dsFx");if(fx)fx.addEventListener("change",function(){send({effects:fx.checked?"on":"off"});});
["dsSeason","dsDay"].forEach(function(id){var el=document.getElementById(id);if(el)el.addEventListener("change",function(){send(id==="dsSeason"?{season:el.value}:{daypart:el.value});});});
})();
JS;

    private const JS = <<<'JS'
(function(){
var D=JSON.parse(document.getElementById("mdData").textContent),S=D.state;
var C=(document.querySelector("meta[name=csrf]")||{}).content||"";
function esc(s){return String(s==null?"":s).replace(/[&<>"']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];});}
function url(it){return D.dir+"/"+encodeURIComponent(it.file);}
function say(t,bad){var m=document.getElementById("mdMsg");m.textContent=t;m.style.color=bad?"#fca5a5":"#bbf7d0";clearTimeout(say.t);say.t=setTimeout(function(){m.textContent="";},5000);}
function act(b){b.action=b.action;return fetch("/api/media/action",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-CSRF":C},body:JSON.stringify(b)}).then(function(r){return r.json();}).then(function(d){if(d.status==="ok"){S=d.state;render();say(d.message||"Tersimpan.");}else say(d.message||"Gagal.",true);return d;}).catch(function(){say("Koneksi gagal.",true);});}
function mb(n){return (n/1048576).toFixed(1)+" MB";}
var KIND={video:"🎬 Video",image:"🖼️ Gambar",audio:"🎵 Audio"};
function render(){
  var items=S.items||[];
  // latar login
  var h='<div class="md-card'+(S.login.active==="default"?" is-on":"")+'"><img class="md-thumb" src="/assets/img/pixel/login-poster.jpg" alt=""><b>Bawaan (video kota pixel)</b>'
    +(S.login.active==="default"?'<span class="md-tag">✅ Dipakai</span>':'<button type="button" class="px-btn" data-login="default">Pakai</button>')+'</div>';
  items.filter(function(i){return i.kind==="video"||i.kind==="image";}).forEach(function(i){
    var on=S.login.active===i.id;
    h+='<div class="md-card'+(on?" is-on":"")+'">'+(i.kind==="video"?'<video class="md-thumb" src="'+esc(url(i))+'#t=1" muted preload="metadata" playsinline></video>':'<img class="md-thumb" src="'+esc(url(i))+'" alt="">')
      +'<b>'+esc(i.title)+'</b><small>'+KIND[i.kind]+' · '+mb(i.size)+'</small>'+(on?'<span class="md-tag">✅ Dipakai</span>':'<button type="button" class="px-btn" data-login="'+esc(i.id)+'">Pakai</button>')+'</div>';
  });
  document.getElementById("mdLogin").innerHTML=h;
  document.getElementById("mdAudio").checked=!!S.login.audio;
  // musik
  var opts=items.filter(function(i){return i.kind==="audio"||i.kind==="video";});
  var m="";
  Object.keys(D.slots).forEach(function(k){
    var cur=S.music[k]||"default";
    m+='<div class="md-row"><b>'+esc(D.slots[k])+'</b><select data-slot="'+k+'"><option value="default">🎮 Bawaan (chiptune game)</option>'
      +opts.map(function(i){return '<option value="'+esc(i.id)+'"'+(cur===i.id?" selected":"")+'>'+(i.kind==="video"?"🎬 ":"🎵 ")+esc(i.title)+'</option>';}).join("")+'</select>'
      +(cur!=="default"?'<button type="button" class="px-btn" data-play="'+esc(cur)+'">▶️ Dengar</button>':"")+'</div>';
  });
  document.getElementById("mdMusic").innerHTML=m;
  // pustaka
  var l=items.length?"":'<p class="md-hint">Pustaka masih kosong — unggah video, gambar, atau lagu di atas.</p>';
  items.slice().reverse().forEach(function(i){
    var used=[];if(S.login.active===i.id)used.push("latar login");Object.keys(S.music).forEach(function(k){if(S.music[k]===i.id)used.push(D.slots[k]);});
    l+='<div class="md-item"><span class="md-tag">'+KIND[i.kind]+'</span><input value="'+esc(i.title)+'" data-title="'+esc(i.id)+'" aria-label="Judul"><small>'+mb(i.size)+(used.length?" · dipakai: "+esc(used.join(", ")):"")+'</small>'
      +'<a class="px-btn" href="'+esc(url(i))+'" target="_blank" rel="noopener">👁️</a><button type="button" class="px-btn px-btn--dark" data-del="'+esc(i.id)+'">🗑️ Hapus</button></div>';
  });
  document.getElementById("mdLib").innerHTML=l;
}
var player=null;
document.addEventListener("click",function(e){
  var t=e.target.closest?e.target.closest("[data-login],[data-del],[data-play]"):null;if(!t)return;
  if(t.hasAttribute("data-login"))act({action:"login",id:t.getAttribute("data-login")});
  else if(t.hasAttribute("data-del")){var id=t.getAttribute("data-del");if(confirm("Hapus permanen berkas ini dari pustaka? Tempat yang memakainya kembali ke bawaan."))act({action:"delete",id:id});}
  else if(t.hasAttribute("data-play")){var it=(S.items||[]).filter(function(i){return i.id===t.getAttribute("data-play");})[0];if(!it)return;
    if(player&&!player.paused){player.pause();t.textContent="▶️ Dengar";return;}player=new Audio(url(it));player.play();t.textContent="⏸️ Stop";}
});
document.addEventListener("change",function(e){
  var t=e.target;
  if(t.id==="mdAudio")act({action:"login_audio",on:t.checked});
  else if(t.hasAttribute("data-slot"))act({action:"music",slot:t.getAttribute("data-slot"),id:t.value});
  else if(t.hasAttribute("data-title"))act({action:"rename",id:t.getAttribute("data-title"),title:t.value});
});
// unggah dipecah 3 MB per potongan
document.getElementById("mdUp").addEventListener("submit",function(e){
  e.preventDefault();
  var f=document.getElementById("mdFile").files[0],msg=document.getElementById("mdUpMsg"),bar=document.querySelector(".md-prog"),btn=e.target.querySelector("button");
  if(!f)return;
  if(f.size>D.max){msg.textContent="Berkas terlalu besar (maks. "+mb(D.max)+").";return;}
  var CH=3*1048576,total=Math.max(1,Math.ceil(f.size/CH)),id="",i;
  var a=new Uint8Array(12);(window.crypto||{}).getRandomValues?crypto.getRandomValues(a):a.forEach(function(_,k){a[k]=Math.random()*256;});
  for(i=0;i<a.length;i++)id+=("0"+a[i].toString(16)).slice(-2);
  bar.hidden=false;btn.disabled=true;msg.textContent="Mengunggah…";
  var title=document.getElementById("mdTitle").value;
  (function send(n){
    fetch("/api/media/upload",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/octet-stream","X-CSRF":C,"X-Upload-Id":id,"X-Chunk-Index":String(n),"X-Chunk-Total":String(total),"X-File-Name":encodeURIComponent(f.name),"X-File-Title":encodeURIComponent(title)},body:f.slice(n*CH,(n+1)*CH)})
      .then(function(r){return r.json();}).then(function(d){
        if(d.status!=="ok"){msg.textContent="❌ "+(d.message||"Gagal");btn.disabled=false;return;}
        bar.firstChild.style.width=Math.round(100*(n+1)/total)+"%";
        if(d.done){msg.textContent=d.message;btn.disabled=false;S.items.push(d.item);render();e.target.reset();setTimeout(function(){bar.hidden=true;bar.firstChild.style.width="0";},1200);}
        else send(n+1);
      }).catch(function(){msg.textContent="❌ Koneksi terputus. Coba lagi.";btn.disabled=false;});
  })(0);
});
function themes(){var b=document.querySelectorAll("[data-theme]");for(var i=0;i<b.length;i++)b[i].classList.toggle("is-on",b[i].getAttribute("data-theme")===D.theme);}
document.getElementById("mdThemes").addEventListener("click",function(e){
  var t=e.target.closest?e.target.closest("[data-theme]"):null;if(!t)return;
  var k=t.getAttribute("data-theme");
  fetch("/api/media/action",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-CSRF":C},body:JSON.stringify({action:"theme",theme:k})})
    .then(function(r){return r.json();}).then(function(d){if(d.status==="ok"){D.theme=k;themes();say(d.message+" Memuat ulang…");setTimeout(function(){location.reload();},900);}else say(d.message||"Gagal.",true);});
});
document.getElementById("mdMusicUp").addEventListener("click",function(){
  var fi=document.getElementById("mdFile");fi.setAttribute("accept","audio/mpeg,audio/mp3,.mp3,audio/ogg,.ogg,audio/mp4,.m4a,audio/wav,.wav");
  document.getElementById("mdUp").scrollIntoView({behavior:"smooth",block:"center"});fi.click();
  setTimeout(function(){fi.setAttribute("accept","video/mp4,video/webm,image/jpeg,image/png,image/webp,image/gif,audio/mpeg,audio/ogg,audio/mp4,audio/x-m4a,audio/wav,.mp3,.m4a,.ogg,.wav");},1500);
});
// berkas dipilih → langsung unggah (tidak perlu tekan Unggah)
document.getElementById("mdFile").addEventListener("change",function(){var f=document.getElementById("mdUp");if(this.files&&this.files[0]){if(f.requestSubmit)f.requestSubmit();else f.querySelector("button").click();}});
themes();
render();
})();
JS;
}

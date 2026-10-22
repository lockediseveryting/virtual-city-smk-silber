<?php
// Credits: TEAM pembuat game + "Tentang Virtual City" (satu sumber) — dipakai /team (/tentang), Bantuan & Cara Main.
// Isi disimpan di settings:
//   team_members = daftar anggota [{id,name,role,avatar,motto,work,specialist[],tools[],education[],quote,link,
//                  backstory,socials[[jenis,url]],portfolio[[judul,deskripsi,url]]}] — anggota pertama = Author (/author)
//   about_game   = {title,text} — tujuan game dibuat (juga dipakai untuk meta deskripsi / mesin pencari)
// Hanya akun pemilik (Security\Owner = coffelatte314) yang bisa menambah, mengedit, menghapus & mengurutkan.
declare(strict_types=1);

namespace VC\View;

use VC\Security\Owner;

final class Credits
{
    /** Isi awal (dipakai sampai pemilik menyimpan perubahan pertama). */
    public const AUTHOR = [
        'id' => 'author',
        'name' => 'M. Fardan AlFarizi',
        'role' => 'AUTHOR · LEAD DEVELOPER',
        'avatar' => '👨‍💻',
        'motto' => 'Sedia project sebelum nganggur.',
        'work' => '',
        'specialist' => ['Software Development', 'Web Development', 'Cyber Security Enthusiast', 'IT Support', 'Solo Game Dev (3D Pixel-Art)'],
        'tools' => ['Unity', 'Godot', 'Tailscale', 'React / Next.js', 'Python', 'PHP', 'Three.js'],
        'education' => ['SD Muhammadiyah 02 Palembang', 'SMP Negeri 43 Palembang', 'SMK Muhammadiyah 02 Palembang'],
        'quote' => 'Art is not only on canvas, but is everywhere where you put ideas into it.',
        'link' => 'https://locked.rudd-catla.ts.net/',
        'backstory' => '',
        'socials' => [],
        'portfolio' => [['Virtual City SMK Silber', 'Sekolah virtual 3D & pixel-art: kelas, chat, voice, mabar 15 orang.', '/']],
    ];
    /** Tombol sosmed yang dikenali: jenis => [label, ikon]. */
    public const SOCIALS = [
        'instagram' => ['Instagram', '📸'], 'tiktok' => ['TikTok', '🎵'], 'discord' => ['Discord', '💬'], 'youtube' => ['YouTube', '▶️'],
        'github' => ['GitHub', '🐙'], 'linkedin' => ['LinkedIn', '💼'], 'x' => ['X / Twitter', '✖️'], 'facebook' => ['Facebook', '📘'],
        'whatsapp' => ['WhatsApp', '🟢'], 'telegram' => ['Telegram', '✈️'], 'email' => ['Email', '✉️'], 'web' => ['Website', '🌐'],
    ];
    public const ABOUT = [
        'title' => 'Tentang Virtual City SMK Silber',
        'text' => "Virtual City SMK Silber adalah sekolah virtual berbentuk game untuk SMK Muhammadiyah 2 Palembang. "
            . "Siswa dan guru bisa masuk ke denah sekolah 3D maupun dunia pixel-art, belajar di kelas virtual, chat dan ngobrol pakai mikrofon, "
            . "mengerjakan tugas & soal dari guru, mengikuti jadwal pelajaran, misi, dan main bareng (mabar) sampai 15 orang.\n"
            . "Game ini dibuat supaya belajar terasa lebih seru, sekolah tetap terasa dekat walau dari rumah, dan siswa terbiasa dengan teknologi.",
    ];
    public const MAX_MEMBERS = 60;
    private const LIST_FIELDS = ['specialist', 'tools', 'education'];

    private static function setting(string $key)
    {
        try {
            $st = \VC\Database::get()->prepare('SELECT value FROM settings WHERE key = ?');
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v !== false ? json_decode((string)$v, true) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function store(string $key, $value): void
    {
        \VC\Database::get()->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime("now"))
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at')
            ->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }

    /** Daftar anggota (urutan tampil). Anggota pertama = kartu utama. */
    public static function members(): array
    {
        $list = self::setting('team_members');
        if (is_array($list) && $list !== []) {
            return array_values(array_map([self::class, 'clean'], array_filter($list, 'is_array')));
        }
        // Belum pernah disimpan: author + contributor lama (settings.team_contributors).
        $out = [self::AUTHOR];
        $old = self::setting('team_contributors');
        $old = is_array($old) ? $old : [['coffelatte314', 'Contributor', '']];
        foreach ($old as $i => $c) {
            if (is_array($c) && trim((string)($c[0] ?? '')) !== '') {
                $out[] = self::clean(['id' => 'c' . $i, 'name' => (string)$c[0], 'role' => (string)($c[1] ?? '') ?: 'Contributor', 'avatar' => '🧑‍💻', 'link' => (string)($c[2] ?? '')]);
            }
        }
        return $out;
    }

    public static function about(): array
    {
        $a = self::setting('about_game');
        return is_array($a) && trim((string)($a['text'] ?? '')) !== ''
            ? ['title' => (string)($a['title'] ?? self::ABOUT['title']), 'text' => (string)$a['text']] : self::ABOUT;
    }

    /** Normalisasi + batas panjang (semua teks polos, di-escape saat tampil). */
    private static function clean(array $m): array
    {
        $t = static fn($v, int $n): string => mb_substr(trim(strip_tags((string)$v)), 0, $n);
        $out = [
            'id' => preg_replace('/[^a-z0-9_-]/i', '', (string)($m['id'] ?? '')) ?: ('m' . bin2hex(random_bytes(4))),
            'name' => $t($m['name'] ?? '', 60),
            'role' => $t($m['role'] ?? '', 60),
            'avatar' => $t($m['avatar'] ?? '', 8) ?: '🧑‍💻',
            'motto' => $t($m['motto'] ?? '', 160),
            'work' => $t($m['work'] ?? '', 600),
            'quote' => $t($m['quote'] ?? '', 240),
            'link' => '',
        ];
        foreach (self::LIST_FIELDS as $f) {
            $v = $m[$f] ?? [];
            if (is_string($v)) {
                $v = preg_split('/\r?\n|,(?=\s)/', $v) ?: [];
            }
            $out[$f] = array_values(array_slice(array_filter(array_map(static fn($x) => mb_substr(trim(strip_tags((string)$x)), 0, 80), (array)$v), static fn($x) => $x !== ''), 0, 20));
        }
        $link = trim((string)($m['link'] ?? ''));
        if ($link !== '' && preg_match('#^https?://[^\s<>"\']{3,200}$#i', $link)) {
            $out['link'] = $link;
        }
        $out['backstory'] = $t($m['backstory'] ?? '', 4000);
        // Sosmed: [[jenis, url]] atau teks "instagram https://…" per baris.
        $soc = $m['socials'] ?? [];
        if (is_string($soc)) {
            $soc = array_map(static fn($l) => preg_split('/\s+/', trim($l), 2), preg_split('/\r?\n/', $soc) ?: []);
        }
        $out['socials'] = [];
        foreach ((array)$soc as $row) {
            $kind = strtolower(trim((string)($row[0] ?? '')));
            $url = trim((string)($row[1] ?? ''));
            if ($kind === 'twitter') {
                $kind = 'x';
            }
            if ($kind === 'email' && filter_var($url, FILTER_VALIDATE_EMAIL)) {
                $url = 'mailto:' . $url;
            }
            if (isset(self::SOCIALS[$kind]) && (preg_match('#^https?://[^\s<>"\']{3,200}$#i', $url) || preg_match('#^mailto:[^\s<>"\']{3,120}$#i', $url))) {
                $out['socials'][] = [$kind, $url];
            }
        }
        $out['socials'] = array_slice($out['socials'], 0, 12);
        // Portofolio: [[judul, deskripsi, url]] atau teks "Judul | deskripsi | https://…" per baris.
        $pf = $m['portfolio'] ?? [];
        if (is_string($pf)) {
            $pf = array_map(static fn($l) => array_map('trim', explode('|', $l, 3)), preg_split('/\r?\n/', $pf) ?: []);
        }
        $out['portfolio'] = [];
        foreach ((array)$pf as $row) {
            $title = $t($row[0] ?? '', 80);
            if ($title === '') {
                continue;
            }
            $url = trim((string)($row[2] ?? ''));
            $out['portfolio'][] = [$title, $t($row[1] ?? '', 240), (preg_match('#^(https?://[^\s<>"\']{3,200}|/[A-Za-z0-9/_?=&.-]{0,100})$#i', $url) && !str_starts_with($url, '//')) ? $url : ''];
        }
        $out['portfolio'] = array_slice($out['portfolio'], 0, 16);
        return $out;
    }

    /** Aksi pemilik: save (tambah/ubah) · delete · move (up/down) · about. */
    public static function manage(?array $user, array $in): array
    {
        if (!Owner::is($user)) {
            return ['status' => 'error', 'message' => 'Hanya pemilik (coffelatte314) yang bisa mengubah halaman Team.'];
        }
        $act = (string)($in['action'] ?? '');
        $list = self::members();
        $find = static function (string $id) use ($list): int {
            foreach ($list as $i => $m) {
                if ($m['id'] === $id) {
                    return $i;
                }
            }
            return -1;
        };
        if ($act === 'about') {
            $title = mb_substr(trim(strip_tags((string)($in['title'] ?? ''))), 0, 100) ?: self::ABOUT['title'];
            $text = mb_substr(trim(strip_tags((string)($in['text'] ?? ''))), 0, 3000);
            if ($text === '') {
                return ['status' => 'error', 'message' => 'Isi "Tentang game" tidak boleh kosong.'];
            }
            self::store('about_game', ['title' => $title, 'text' => $text]);
            \VC\Security\AuditLog::log('team_about_save', [], (int)$user['id']);
            return ['status' => 'ok', 'message' => 'Tentang game disimpan.'];
        }
        if ($act === 'save') {
            $m = self::clean((array)($in['member'] ?? []));
            if ($m['name'] === '') {
                return ['status' => 'error', 'message' => 'Nama wajib diisi.'];
            }
            if (trim((string)(($in['member'] ?? [])['link'] ?? '')) !== '' && $m['link'] === '') {
                return ['status' => 'error', 'message' => 'Tautan harus diawali http:// atau https://'];
            }
            $i = $find((string)(($in['member'] ?? [])['id'] ?? ''));
            if ($i >= 0) {
                $m['id'] = $list[$i]['id'];
                $list[$i] = $m;
            } else {
                if (count($list) >= self::MAX_MEMBERS) {
                    return ['status' => 'error', 'message' => 'Maksimal ' . self::MAX_MEMBERS . ' anggota.'];
                }
                $list[] = $m;
            }
            $msg = $i >= 0 ? 'Data ' . $m['name'] . ' diperbarui.' : $m['name'] . ' ditambahkan ke Team.';
        } elseif ($act === 'delete') {
            $i = $find((string)($in['id'] ?? ''));
            if ($i < 0) {
                return ['status' => 'error', 'message' => 'Anggota tidak ditemukan.'];
            }
            if (count($list) <= 1) {
                return ['status' => 'error', 'message' => 'Minimal harus ada satu anggota.'];
            }
            $m = $list[$i];
            array_splice($list, $i, 1);
            $msg = $m['name'] . ' dihapus dari Team.';
        } elseif ($act === 'move') {
            $i = $find((string)($in['id'] ?? ''));
            $j = $i + (($in['dir'] ?? '') === 'up' ? -1 : 1);
            if ($i < 0 || $j < 0 || $j >= count($list)) {
                return ['status' => 'ok', 'message' => ''];
            }
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
            $m = $list[$j];
            $msg = 'Urutan diubah.';
        } else {
            return ['status' => 'error', 'message' => 'Aksi tidak dikenal.'];
        }
        self::store('team_members', array_values($list));
        \VC\Security\AuditLog::log('team_member_' . $act, ['name' => $m['name']], (int)$user['id']);
        return ['status' => 'ok', 'message' => $msg];
    }

    /** Daftar nama kontributor (untuk Bantuan / Cara Main). */
    public static function contributors(): array
    {
        return array_map(static fn($m) => [$m['name'], $m['role'], $m['link']], array_slice(self::members(), 1));
    }

    private static function memberHtml(array $m, bool $lead, bool $canEdit, int $i, int $n): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $chips = static fn(array $xs): string => implode('', array_map(static fn($x) => '<span class="cr-chip">' . htmlspecialchars($x, ENT_QUOTES) . '</span>', $xs));
        $h = '<article class="cr-member' . ($lead ? ' is-lead' : '') . '" data-id="' . $e($m['id']) . '">';
        $h .= '<div class="cr-head"><div class="cr-avatar" aria-hidden="true">' . $e($m['avatar']) . '</div><div>'
            . ($m['role'] !== '' ? '<small class="cr-kicker">' . $e($m['role']) . '</small>' : '')
            . '<h2 class="cr-name">' . $e($m['name']) . '</h2>'
            . ($m['motto'] !== '' ? '<p class="cr-motto">“' . $e($m['motto']) . '”</p>' : '') . '</div></div>';
        if ($m['work'] !== '') {
            $h .= '<h3>🧩 Yang dikerjakan</h3><p class="cr-work">' . nl2br($e($m['work'])) . '</p>';
        }
        if ($m['specialist']) {
            $h .= '<h3>🎯 Spesialis</h3><div class="cr-chips">' . $chips($m['specialist']) . '</div>';
        }
        if ($m['tools']) {
            $h .= '<h3>🛠️ Tools &amp; Tech</h3><div class="cr-chips">' . $chips($m['tools']) . '</div>';
        }
        if ($m['education']) {
            $h .= '<h3>🎓 Pendidikan</h3><ol class="cr-edu">' . implode('', array_map(static fn($x) => '<li>' . htmlspecialchars($x, ENT_QUOTES) . '</li>', $m['education'])) . '</ol>';
        }
        if ($m['quote'] !== '') {
            $h .= '<blockquote class="cr-quote">“' . $e($m['quote']) . '”</blockquote>';
        }
        if ($m['link'] !== '') {
            $h .= '<a class="px-btn px-btn--gold cr-link" href="' . $e($m['link']) . '" target="_blank" rel="noopener noreferrer">🌐 ' . ($lead ? 'Live Project &amp; Portfolio' : 'Profil / Portfolio') . '</a>';
        }
        $h .= self::socialsHtml($m);
        if ($lead) {
            $h .= '<a class="px-btn cr-link" href="/author">📖 Backstory &amp; portofolio Author →</a>';
        }
        if ($canEdit) {
            $h .= '<div class="cr-tools"><button type="button" class="px-btn" data-cr="edit" data-id="' . $e($m['id']) . '">✏️ Edit</button>'
                . ($i > 0 ? '<button type="button" class="px-btn" data-cr="up" data-id="' . $e($m['id']) . '" title="Naikkan">⬆️</button>' : '')
                . ($i < $n - 1 ? '<button type="button" class="px-btn" data-cr="down" data-id="' . $e($m['id']) . '" title="Turunkan">⬇️</button>' : '')
                . ($n > 1 ? '<button type="button" class="px-btn px-btn--dark" data-cr="delete" data-id="' . $e($m['id']) . '">🗑️ Hapus</button>' : '') . '</div>';
        }
        return $h . '</article>';
    }

    public static function socialsHtml(array $m): string
    {
        if (!$m['socials']) {
            return '';
        }
        $h = '<div class="cr-socials">';
        foreach ($m['socials'] as [$kind, $url]) {
            [$label, $icon] = self::SOCIALS[$kind];
            $h .= '<a class="cr-soc cr-soc--' . $kind . '" href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer">' . $icon . ' ' . $label . '</a>';
        }
        return $h . '</div>';
    }

    /** Halaman /author — profil Author bergaya kartu karakter game (backstory, statistik, portofolio, sosmed). */
    public static function authorPage(bool $canEdit = false): string
    {
        $m = self::members()[0];
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $h = '<div class="au-page"><section class="au-hero px-panel">'
            . '<div class="au-portrait" aria-hidden="true">' . $e($m['avatar']) . '<span>LV. 99</span></div>'
            . '<div class="au-id"><small class="cr-kicker">' . $e($m['role'] ?: 'AUTHOR') . '</small><h1>' . $e($m['name']) . '</h1>'
            . ($m['motto'] !== '' ? '<p class="cr-motto">“' . $e($m['motto']) . '”</p>' : '')
            . self::socialsHtml($m)
            . ($m['link'] !== '' ? '<a class="px-btn px-btn--gold cr-link" href="' . $e($m['link']) . '" target="_blank" rel="noopener noreferrer">🌐 Live Project &amp; Portfolio</a>' : '')
            . '</div></section>';
        $h .= '<section class="au-sec px-panel"><h2>📜 Backstory</h2>' . ($m['backstory'] !== ''
            ? '<p class="au-story">' . nl2br($e($m['backstory'])) . '</p>'
            : '<p class="au-story au-empty">Kisah sang Author belum ditulis' . ($canEdit ? ' — tekan ✏️ Edit untuk menulis backstory.' : '.') . '</p>') . '</section>';
        $stat = static function (string $title, array $xs, string $icon) use ($e): string {
            if (!$xs) {
                return '';
            }
            return '<section class="au-sec px-panel"><h2>' . $icon . ' ' . $title . '</h2><ul class="au-skills">'
                . implode('', array_map(static fn($x) => '<li>' . htmlspecialchars($x, ENT_QUOTES) . '</li>', $xs)) . '</ul></section>';
        };
        $h .= '<div class="au-cols">' . $stat('Skill / Spesialis', $m['specialist'], '🎯') . $stat('Inventory: Tools & Tech', $m['tools'], '🛠️') . $stat('Riwayat Pendidikan', $m['education'], '🎓') . '</div>';
        if ($m['work'] !== '') {
            $h .= '<section class="au-sec px-panel"><h2>🧩 Quest saat ini</h2><p>' . nl2br($e($m['work'])) . '</p></section>';
        }
        $h .= '<section class="au-sec px-panel"><h2>🏆 Portofolio</h2>';
        if ($m['portfolio']) {
            $h .= '<div class="au-port">';
            foreach ($m['portfolio'] as [$t, $d, $u]) {
                $card = '<b>' . $e($t) . '</b>' . ($d !== '' ? '<small>' . $e($d) . '</small>' : '');
                $h .= $u !== '' ? '<a class="au-item" href="' . $e($u) . '"' . (str_starts_with($u, 'http') ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $card . '<i>Buka →</i></a>' : '<div class="au-item">' . $card . '</div>';
            }
            $h .= '</div>';
        } else {
            $h .= '<p class="au-empty">Belum ada portofolio.</p>';
        }
        $h .= '</section>';
        if ($m['quote'] !== '') {
            $h .= '<blockquote class="cr-quote">“' . $e($m['quote']) . '”</blockquote>';
        }
        if ($canEdit) {
            $h .= '<div class="cr-ownerbar"><button type="button" class="px-btn px-btn--gold" data-cr="edit" data-id="' . $e($m['id']) . '">✏️ Edit profil Author</button>'
                . '<small>Mode pemilik (coffelatte314): ubah nama, backstory, sosmed, portofolio, dll.</small><span id="crMsg" aria-live="polite"></span></div>'
                . '<script type="application/json" id="crData">' . json_encode(['members' => self::members(), 'about' => self::about()], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        }
        return $h . '<p class="au-nav"><a href="/team">👥 Lihat seluruh Team</a> · <a href="/">← Dashboard</a></p></div>';
    }

    /** Halaman Team lengkap (HTML aman, gaya pixel). $canEdit = pemilik. */
    public static function card(bool $canEdit = false): string
    {
        $members = self::members();
        $about = self::about();
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $h = '<div class="cr-card px-panel"><section class="cr-about"><h1 class="cr-team">🏙️ ' . $e($about['title']) . '</h1>'
            . '<p>' . nl2br($e($about['text'])) . '</p>'
            . ($canEdit ? '<button type="button" class="px-btn" data-cr="about">✏️ Edit "Tentang game"</button>' : '') . '</section>';
        $h .= '<h1 class="cr-team">👥 Team Virtual City SMK Silber</h1>';
        $n = count($members);
        foreach ($members as $i => $m) {
            if ($i === 1) {
                $h .= '<h3 class="cr-contrib-title">🤝 Team &amp; Contributor</h3><div class="cr-grid">';
            }
            $h .= self::memberHtml($m, $i === 0, $canEdit, $i, $n);
        }
        if ($n > 1) {
            $h .= '</div>';
        }
        if ($canEdit) {
            $h .= '<div class="cr-ownerbar"><button type="button" class="px-btn px-btn--gold" data-cr="add">➕ Tambah anggota Team</button>'
                . '<small>Mode pemilik (coffelatte314): semua isi bisa ditambah, diedit, dihapus & diurutkan.</small><span id="crMsg" aria-live="polite"></span></div>'
                . '<script type="application/json" id="crData">' . json_encode(['members' => $members, 'about' => $about], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        }
        return $h . '</div>';
    }

    /** JS penyunting (hanya dimuat untuk pemilik). */
    public const EDITOR_JS = <<<'JS'
(function(){
var C=(document.querySelector("meta[name=csrf]")||{}).content||"";
var D=JSON.parse((document.getElementById("crData")||{}).textContent||"{}");
function post(b){return fetch("/api/team/contributors",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-CSRF":C},body:JSON.stringify(b)}).then(function(r){return r.json();});}
function msg(t){var m=document.getElementById("crMsg");if(m)m.textContent=t;}
function esc(s){return String(s==null?"":s).replace(/[&<>"']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];});}
function find(id){var ms=D.members||[];for(var i=0;i<ms.length;i++)if(ms[i].id===id)return ms[i];return null;}
function field(n,l,v,ta,ph){return '<label class="cr-f"><span>'+l+'</span>'+(ta?'<textarea name="'+n+'" rows="'+(ta===true?3:ta)+'" placeholder="'+esc(ph||"")+'">'+esc(v)+'</textarea>':'<input name="'+n+'" value="'+esc(v)+'" placeholder="'+esc(ph||"")+'">')+'</label>';}
function modal(title,inner,onSave){
  var w=document.createElement("div");w.className="cr-modal";w.setAttribute("role","dialog");w.setAttribute("aria-modal","true");
  w.innerHTML='<form class="cr-modal__box px-panel"><h2>'+esc(title)+'</h2>'+inner+'<p class="cr-err" aria-live="polite"></p><div class="cr-tools"><button type="submit" class="px-btn px-btn--gold">💾 Simpan</button><button type="button" class="px-btn px-btn--dark" data-x="1">Batal</button></div></form>';
  document.body.appendChild(w);
  var f=w.querySelector("form");
  w.addEventListener("click",function(e){if(e.target===w||e.target.getAttribute("data-x"))w.parentNode.removeChild(w);});
  f.addEventListener("submit",function(e){e.preventDefault();onSave(f).then(function(d){if(d.status==="ok")location.reload();else f.querySelector(".cr-err").textContent=d.message||"Gagal menyimpan.";});});
  var first=f.querySelector("input,textarea");if(first)first.focus();
}
function editMember(m){
  m=m||{id:"",name:"",role:"Contributor",avatar:"🧑‍💻",motto:"",work:"",specialist:[],tools:[],education:[],quote:"",link:"",backstory:"",socials:[],portfolio:[]};
  modal(m.id?"Edit "+m.name:"Tambah anggota Team",
    field("name","Nama *",m.name)+field("role","Peran / jabatan (mis. AUTHOR · LEAD DEVELOPER, Contributor, Desainer)",m.role)+field("avatar","Ikon / emoji",m.avatar)
    +field("motto","Motto",m.motto)+field("work","Apa yang dikerjakan di game ini",m.work,4)
    +field("specialist","🎯 Spesialis (satu per baris)",(m.specialist||[]).join("\n"),4)+field("tools","🛠️ Tools & Tech (satu per baris)",(m.tools||[]).join("\n"),4)
    +field("education","🎓 Pendidikan / status pendidikan (satu per baris)",(m.education||[]).join("\n"),4,"mis. SMK Muhammadiyah 02 Palembang — kelas XI TJKT")
    +field("quote","Kutipan",m.quote,2)+field("link","Tautan portfolio / profil (https://…)",m.link)
    +field("backstory","📜 Backstory (kisah, tampil di /author untuk Author)",m.backstory,6)
    +field("socials","📱 Sosmed — satu per baris: jenis + link (instagram, tiktok, discord, youtube, github, linkedin, x, facebook, whatsapp, telegram, email, web)",(m.socials||[]).map(function(x){return x[0]+" "+String(x[1]).replace(/^mailto:/,"");}).join("\n"),4,"instagram https://instagram.com/namakamu")
    +field("portfolio","🏆 Portofolio — satu per baris: Judul | deskripsi | link",(m.portfolio||[]).map(function(x){return x.join(" | ");}).join("\n"),4,"Virtual City | Game sekolah 3D | https://…"),
    function(f){var g=function(n){return f.elements[n].value;};return post({action:"save",member:{id:m.id,name:g("name"),role:g("role"),avatar:g("avatar"),motto:g("motto"),work:g("work"),specialist:g("specialist"),tools:g("tools"),education:g("education"),quote:g("quote"),link:g("link"),backstory:g("backstory"),socials:g("socials"),portfolio:g("portfolio")}});});
}
document.addEventListener("click",function(e){
  var b=e.target.closest?e.target.closest("[data-cr]"):null;if(!b)return;
  var a=b.getAttribute("data-cr"),id=b.getAttribute("data-id");
  if(a==="add")editMember(null);
  else if(a==="edit")editMember(find(id));
  else if(a==="about")modal('Edit "Tentang game"',field("title","Judul",(D.about||{}).title||"")+field("text","Isi — untuk apa game ini dibuat (tampil di sini & di Google)",(D.about||{}).text||"",8),function(f){return post({action:"about",title:f.elements.title.value,text:f.elements.text.value});});
  else if(a==="delete"){var m=find(id);if(!confirm("Hapus "+(m?m.name:"anggota ini")+" dari Team?"))return;post({action:"delete",id:id}).then(function(d){if(d.status==="ok")location.reload();else msg(d.message||"Gagal");});}
  else if(a==="up"||a==="down")post({action:"move",id:id,dir:a}).then(function(d){if(d.status==="ok")location.reload();else msg(d.message||"Gagal");});
});
})();
JS;

    public const CSS = '.cr-card{max-width:760px;margin:0 auto;padding:20px!important;text-align:left}'
        . '.cr-about{margin-bottom:18px;padding-bottom:14px;border-bottom:2px dashed rgba(217,164,65,.5)}.cr-about p{line-height:1.6;margin:0 0 10px}'
        . '.cr-member{padding:12px;background:rgba(0,0,0,.18);border:2px solid rgba(217,164,65,.4)}.cr-member.is-lead{border-width:3px;border-color:#d9a441;margin-bottom:6px}'
        . '.cr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px}.cr-grid .cr-avatar{font-size:30px;width:50px;height:50px}.cr-grid .cr-name{font-size:20px}'
        . '.cr-head{display:flex;gap:14px;align-items:center;margin-bottom:6px}.cr-avatar{font-size:44px;width:68px;height:68px;flex:0 0 auto;display:grid;place-items:center;background:#20130a;border:3px solid #d9a441}'
        . '.cr-kicker{color:#d9a441;letter-spacing:.08em}.cr-name{margin:2px 0;font-size:clamp(22px,5vw,30px);word-break:break-word}.cr-motto{margin:0;opacity:.9;font-style:italic}'
        . '.cr-card h3{margin:14px 0 6px;font-size:16px}.cr-chips{display:flex;flex-wrap:wrap;gap:6px}.cr-chip{padding:4px 9px;background:rgba(217,164,65,.16);border:2px solid rgba(217,164,65,.5);font-size:14px}'
        . '.cr-work{margin:0;line-height:1.55}.cr-edu{margin:0;padding-left:22px;line-height:1.7}.cr-quote{margin:14px 0;padding:10px 14px;border-left:4px solid #d9a441;background:rgba(0,0,0,.22);font-style:italic}'
        . '.cr-link{display:inline-flex;margin-top:4px}.cr-team{margin:0 0 14px;font-size:clamp(20px,4vw,26px);text-align:center}'
        . '.cr-contrib-title{margin-top:20px!important;padding-top:14px;border-top:2px dashed rgba(217,164,65,.5)}'
        . '.cr-tools{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.cr-ownerbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:16px;padding:10px;border:2px dashed #d9a441}.cr-ownerbar small{flex:1;min-width:200px;opacity:.8}'
        . '.cr-modal{position:fixed;inset:0;z-index:10040;background:rgba(10,6,3,.72);display:flex;align-items:center;justify-content:center;padding:12px}'
        . '.cr-modal__box{width:min(560px,100%);max-height:92vh;overflow-y:auto;padding:16px!important;display:flex;flex-direction:column;gap:8px}.cr-modal__box h2{margin:0}'
        . '.cr-socials{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0 4px}.cr-soc{display:inline-flex;align-items:center;gap:4px;min-height:40px;padding:6px 10px;border:2px solid #20130a;background:#fff1d0;color:#20130a;font-weight:700;text-decoration:none;font-size:14px}'
        . '.cr-soc--instagram{background:#f9a8d4}.cr-soc--tiktok{background:#111;color:#fff}.cr-soc--discord{background:#818cf8;color:#fff}.cr-soc--youtube{background:#ef4444;color:#fff}.cr-soc--github{background:#24292f;color:#fff}.cr-soc--whatsapp{background:#25d366}'
        . '.au-page{max-width:860px;margin:0 auto;padding:16px;display:flex;flex-direction:column;gap:12px}'
        . '.au-hero{display:flex;gap:18px;align-items:center;flex-wrap:wrap;padding:18px!important}.au-portrait{position:relative;font-size:72px;width:120px;height:120px;display:grid;place-items:center;background:#20130a;border:4px solid #d9a441;box-shadow:0 0 0 4px #20130a,0 0 24px rgba(255,207,74,.45)}'
        . '.au-portrait span{position:absolute;bottom:-12px;left:50%;transform:translateX(-50%);font-size:12px;background:#ffcf4a;color:#20130a;padding:2px 8px;border:2px solid #20130a;white-space:nowrap}'
        . '.au-id{flex:1;min-width:220px}.au-id h1{margin:2px 0;font-size:clamp(24px,6vw,36px)}.au-sec{padding:14px!important}.au-sec h2{margin:0 0 8px;font-size:18px}'
        . '.au-story{line-height:1.7;margin:0}.au-empty{opacity:.7;font-style:italic}.au-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}'
        . '.au-skills{list-style:none;margin:0;padding:0;display:grid;gap:5px}.au-skills li{padding:5px 8px;background:rgba(0,0,0,.22);border-left:4px solid #ffcf4a}'
        . '.au-port{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}.au-item{display:flex;flex-direction:column;gap:4px;padding:10px;background:rgba(0,0,0,.25);border:2px solid rgba(217,164,65,.6);color:inherit;text-decoration:none}'
        . '.au-item small{opacity:.8}.au-item i{margin-top:auto;color:#ffcf4a;font-style:normal;font-weight:700}a.au-item:hover{border-color:#ffcf4a}.au-nav{text-align:center}'
        . '.cr-f{display:flex;flex-direction:column;gap:3px;font-size:14px}.cr-f input,.cr-f textarea{font-size:16px;width:100%;box-sizing:border-box}.cr-err{color:#fca5a5;margin:0;min-height:1em}';
}

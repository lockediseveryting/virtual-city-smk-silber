<?php
// HelpController: Interactive Knowledge Base & Tutorial Center for students, teachers, and admins.
declare(strict_types=1);

namespace VC\Controllers;

use VC\Security\Auth;
use VC\Security\Headers;
use VC\View\Shell;

final class HelpController
{
    public static function render(): void
    {
        $user = Auth::user();
        $nonce = Headers::nonce();

        Shell::start([
            'title'   => 'Pusat Tutorial & Petunjuk — Virtual City SMK Silber',
            'active'  => 'help',
            'user'    => $user,
            'splash'  => false,
        ]);

        // ---- Hero Banner ----
        echo '<div class="section">';
        echo '<div class="card card--glass card--accent game-hero-banner" style="margin-bottom:var(--sp-4);padding:var(--sp-4) var(--sp-5);">';
        echo '<div class="row row--between row--wrap" style="align-items:center;gap:18px">';
        echo '<div style="max-width:580px">';
        echo '<div class="row" style="gap:8px;margin-bottom:6px"><span class="badge badge--neon">📚 Pusat Tutorial Resmi</span><span class="game-tag-xp">⚡ Cyber V5 Edition</span></div>';
        echo '<h1 class="page-title" style="margin:0;font-size:clamp(1.2rem, 3.5vw, 1.75rem)">Pusat Petunjuk &amp; Tutorial Web</h1>';
        echo '<p class="muted" style="margin:6px 0 0;font-size:0.88rem;line-height:1.5">Pelajari cara bermain di Dunia 3D, kontrol HP &amp; laptop, cara menghubungkan bot Telegram, panduan mengumpulkan koin &amp; tugas, serta panduan staf guru &amp; admin.</p>';
        echo '</div>';
        echo '<div class="row" style="gap:10px;flex-wrap:wrap">';
        echo '<button type="button" class="btn btn--gold" data-open-sheet="vcAiMascotSheet" style="border-radius:12px;padding:10px 18px">🤖 Tanya Si Silber AI</button>';
        echo '<a href="/dunia" class="btn btn--secondary" style="border-radius:12px;padding:10px 18px;text-decoration:none">🎮 Masuk Game 3D</a>';
        echo '</div>';
        echo '</div></div>';
        echo '</div>';

        // ---- Live Search & Category Tabs ----
        echo '<div class="section" style="margin-bottom:var(--sp-4)">';
        echo '<div class="card" style="padding:14px;background:rgba(15,23,42,0.85);border:1px solid rgba(56,189,248,0.25)">';
        echo '<div class="field" style="margin-bottom:12px">';
        echo '<input type="text" id="tutorialSearch" placeholder="🔍 Ketik untuk mencari tutorial (misal: joystick, koin, wasd, telegram, tugas, addstaff)..." oninput="vcFilterTutorials(this.value)" autocomplete="off">';
        echo '</div>';
        echo '<div class="chip-row" id="tutorialTabRow">';
        $tabs = [
            ['semua', '🌟 Semua Tutorial'],
            ['siswa', '🎓 Siswa & Akun'],
            ['kontrol', '🕹️ Kontrol 3D (PC & HP)'],
            ['pwa', '📲 Pasang Aplikasi (PWA)'],
            ['telegram', '🤖 Bot Telegram'],
            ['guru', '👨‍🏫 Guru & Admin'],
            ['ai', '🌌 AI Si Silber'],
            ['faq', '❓ FAQ & Tanya Jawab'],
        ];
        foreach ($tabs as [$tabKey, $tabLabel]) {
            $activeCls = ($tabKey === 'semua') ? 'btn--primary is-active' : 'btn--secondary';
            echo '<button type="button" class="btn btn--sm ' . $activeCls . '" onclick="vcSwitchTab(\'' . $tabKey . '\', this)" style="border-radius:8px">' . htmlspecialchars($tabLabel, ENT_QUOTES) . '</button>';
        }
        echo '</div>';
        echo '</div></div>';

        // Container untuk seluruh artikel tutorial
        echo '<div class="stack" id="tutorialArticles" style="gap:var(--sp-5);margin-bottom:var(--sp-6)">';

        // =========================================================================
        // TUTORIAL 1: SISWA & AKUN
        // =========================================================================
        echo '<div class="card tutorial-card" data-category="siswa" style="border-left:4px solid var(--c-primary-2);padding:22px 20px">';
        echo '<div class="row row--between row--wrap" style="align-items:center;margin-bottom:14px;gap:10px">';
        echo '<div class="row" style="gap:10px"><span style="font-size:1.8rem">🎓</span><div><h2 style="margin:0;font-size:1.25rem;color:#fff">Tutorial Siswa: Mulai Belajar &amp; Bermain</h2><span class="badge badge--neon">Panduan Murid</span></div></div>';
        echo '<a href="/karakter" class="btn btn--secondary btn--sm" style="text-decoration:none">🎭 Buka Studio Karakter →</a>';
        echo '</div>';
        echo '<p class="muted" style="font-size:0.86rem;line-height:1.6">Ikuti langkah-langkah mudah di bawah ini untuk memulai petualangan belajarmu di Virtual City SMK Silber:</p>';

        echo '<div class="stack" style="gap:12px;margin-top:14px">';

        echo '<div class="card" style="background:rgba(12,20,34,0.8);border:1px solid rgba(56,189,248,0.25);padding:14px">';
        echo '<div class="row" style="gap:8px;margin-bottom:6px"><span class="badge" style="background:var(--c-primary);color:#04121a;font-weight:800">LANGKAH 1</span><strong style="color:#fff">Masuk atau Daftar Akun Siswa</strong></div>';
        echo '<p style="font-size:0.84rem;color:#cbd5e1;line-height:1.5;margin:0">Buka halaman login. Jika belum memiliki akun, daftar dengan memasukkan NIS, nama lengkap, dan kelasmu. Pendaftaran akan diverifikasi wali kelas. Untuk demonstrasi cepat, Anda juga bisa mencoba tombol 1-klik yang tersedia di kartu masuk.</p>';
        echo '</div>';

        echo '<div class="card" style="background:rgba(12,20,34,0.8);border:1px solid rgba(56,189,248,0.25);padding:14px">';
        echo '<div class="row" style="gap:8px;margin-bottom:6px"><span class="badge" style="background:var(--c-primary);color:#04121a;font-weight:800">LANGKAH 2</span><strong style="color:#fff">Pilih Karakter &amp; Seragam Jurusan 3D</strong></div>';
        echo '<p style="font-size:0.84rem;color:#cbd5e1;line-height:1.5;margin:0">Buka menu <b>Studio Karakter</b> (<code>/karakter</code>). Pilih avatar kebanggaan jurusanmu: <b>TKJ</b> (Cyber Electric Cyan), <b>RPL</b> (Modern Software Engineer), atau <b>DKV</b> (Desain Kreatif). Karakter yang dipilih akan otomatis tampil di dunia 3D multiplayer dan terlihat oleh semua siswa di server.</p>';
        echo '</div>';

        echo '<div class="card" style="background:rgba(12,20,34,0.8);border:1px solid rgba(56,189,248,0.25);padding:14px">';
        echo '<div class="row" style="gap:8px;margin-bottom:6px"><span class="badge" style="background:var(--c-primary);color:#04121a;font-weight:800">LANGKAH 3</span><strong style="color:#fff">Selesaikan Misi Harian &amp; Dapatkan Koin</strong></div>';
        echo '<p style="font-size:0.84rem;color:#cbd5e1;line-height:1.5;margin:0">Setiap hari tersedia misi sekolah di menu <b>Misi</b> (<code>/misi</code>), seperti mengeksplorasi Lab Komputer, Bank Mini, Musholah, memeriksa jadwal kelas, dan menyapa teman. Selesaikan misi untuk memperoleh <b>XP</b> (naik level) dan <b>Koin Sekolah (🪙)</b> yang bisa dibelanjakan di Toko Avatar (<code>/toko</code>).</p>';
        echo '</div>';

        echo '<div class="card" style="background:rgba(12,20,34,0.8);border:1px solid rgba(56,189,248,0.25);padding:14px">';
        echo '<div class="row" style="gap:8px;margin-bottom:6px"><span class="badge" style="background:var(--c-primary);color:#04121a;font-weight:800">LANGKAH 4</span><strong style="color:#fff">Cek Jadwal Pelajaran &amp; Kumpulkan Tugas</strong></div>';
        echo '<p style="font-size:0.84rem;color:#cbd5e1;line-height:1.5;margin:0">Buka <b>Jadwal</b> (<code>/jadwal</code>) untuk melihat mata pelajaran dan ruang kelas di lantai 1 atau 2. Pada menu <b>Tugas</b> (<code>/tugas</code>), unduh materi ajar dari gurumu, kerjakan, lalu unggah dokumen jawaban sebelum batas deadline.</p>';
        echo '</div>';

        echo '</div>';
        echo '<div class="row" style="gap:8px;margin-top:14px;flex-wrap:wrap">';
        echo '<a href="/dunia" class="btn btn--primary btn--sm" style="text-decoration:none">🚀 Masuk ke Dunia 3D</a>';
        echo '<a href="/jadwal" class="btn btn--secondary btn--sm" style="text-decoration:none">📅 Cek Jadwal Kelas</a>';
        echo '<a href="/tugas" class="btn btn--secondary btn--sm" style="text-decoration:none">📝 Buka Ruang Tugas</a>';
        echo '</div>';
        echo '</div>';

        // =========================================================================
        // TUTORIAL 2: KONTROL DUNIA 3D (PC & HP)
        // =========================================================================
        echo '<div class="card tutorial-card" data-category="kontrol" style="border-left:4px solid var(--c-gold);padding:22px 20px">';
        echo '<div class="row row--between row--wrap" style="align-items:center;margin-bottom:14px;gap:10px">';
        echo '<div class="row" style="gap:10px"><span style="font-size:1.8rem">🕹️</span><div><h2 style="margin:0;font-size:1.25rem;color:#fff">Tutorial Kontrol Dunia 3D (Laptop &amp; Smartphone HP)</h2><span class="badge badge--gold">Multiplayer 3D Engine</span></div></div>';
        echo '<a href="/dunia" class="btn btn--gold btn--sm" style="text-decoration:none">🎮 Buka Game 3D Sekarang →</a>';
        echo '</div>';

        echo '<div class="card-grid card-grid--2" style="margin-top:12px;gap:14px">';

        // Sub-card PC
        echo '<div class="card" style="background:rgba(12,20,34,0.9);border:1px solid rgba(2,132,199,0.35);padding:16px">';
        echo '<div class="row" style="gap:8px;margin-bottom:10px"><span style="font-size:1.3rem">💻</span><strong style="color:var(--c-primary-2)">Kontrol di Laptop / Komputer (PC)</strong></div>';
        echo '<ul style="margin:0;padding-left:18px;font-size:0.83rem;line-height:1.8;color:#cbd5e1">';
        echo '<li><b>W / A / S / D</b> atau <b>Tombol Panah</b>: Berjalan ke depan, kiri, mundur, kanan.</li>';
        echo '<li><b>Spasi (Spacebar)</b>: Melompat (Jump).</li>';
        echo '<li><b>Shift (Tahan)</b>: Berlari kencang (Sprint).</li>';
        echo '<li><b>Tombol E</b>: Berinteraksi dengan pintu kelas, hotspot, dan NPC.</li>';
        echo '<li><b>Klik Kiri + Tahan + Geser Mouse</b>: Memutar kamera 360° (Orbit Camera).</li>';
        echo '<li><b>Scroll Mouse</b>: Memperbesar / memperkecil jarak pandang (Zoom In / Out).</li>';
        echo '<li><b>Klik Radar (Pojok Kanan)</b>: Membuka denah sekolah lengkap 2D.</li>';
        echo '</ul>';
        echo '</div>';

        // Sub-card HP
        echo '<div class="card" style="background:rgba(12,20,34,0.9);border:1px solid rgba(255,107,0,0.35);padding:16px">';
        echo '<div class="row" style="gap:8px;margin-bottom:10px"><span style="font-size:1.3rem">📱</span><strong style="color:var(--c-gold)">Kontrol di Layar Sentuh HP Smartphone</strong></div>';
        echo '<ul style="margin:0;padding-left:18px;font-size:0.83rem;line-height:1.8;color:#cbd5e1">';
        echo '<li><b>Virtual Joystick (Kiri Bawah)</b>: Sentuh dan arahkan lingkaran oranye untuk bergerak bebas.</li>';
        echo '<li><b>Geser Layar Bebas (Area Kanan)</b>: Geser satu jari di layar untuk memutar kamera pandang.</li>';
        echo '<li><b>Tombol ⬆ Lompat</b>: Mengetuk tombol untuk melompat.</li>';
        echo '<li><b>Tombol 🏃 Lari</b>: Mengaktifkan mode lari cepat otomatis.</li>';
        echo '<li><b>Tombol 🏢 LT 2 / LT 1</b>: Berpindah langsung antara Lantai 1 dan Lantai 2.</li>';
        echo '<li><b>Tombol ✋ Aksi</b>: Muncul otomatis saat berada di dekat ruangan atau objek interaktif.</li>';
        echo '<li><b>Mode Layar Horizontal (Landscape)</b>: Sangat disarankan memiringkan HP agar kendali joystick dan tampilan gedung optimal!</li>';
        echo '</ul>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        // =========================================================================
        // TUTORIAL 3: TELEGRAM BOT
        // =========================================================================
        echo '<div class="card tutorial-card" data-category="telegram" style="border-left:4px solid #22d3ee;padding:22px 20px">';
        echo '<div class="row row--between row--wrap" style="align-items:center;margin-bottom:14px;gap:10px">';
        echo '<div class="row" style="gap:10px"><span style="font-size:1.8rem">🤖</span><div><h2 style="margin:0;font-size:1.25rem;color:#fff">Tutorial Bot Telegram (@hackertkjsmksilber_bot)</h2><span class="badge" style="background:rgba(34,211,238,0.15);color:#22d3ee;border-color:#0284c7">Integrasi Akun</span></div></div>';
        echo '<a href="/telegram" class="btn btn--secondary btn--sm" style="text-decoration:none">🔗 Halaman Tautkan Akun →</a>';
        echo '</div>';

        echo '<div class="stack" style="gap:12px">';

        echo '<div class="card" style="background:rgba(12,20,34,0.85);border:1px solid rgba(2,132,199,0.3);padding:14px">';
        echo '<h4 style="margin:0 0 8px;color:#38bdf8;font-size:0.95rem">1. Cara Menautkan Akun Web ke Telegram (Opsional &amp; Aman)</h4>';
        echo '<ol style="margin:0;padding-left:18px;font-size:0.84rem;line-height:1.7;color:#cbd5e1">';
        echo '<li>Buka menu <a href="/telegram" style="color:#22d3ee;font-weight:700">Tautkan Telegram</a> pada website.</li>';
        echo '<li>Klik tombol <b>"Buat Kode Taut Akun"</b>. Anda akan mendapatkan 6 digit kode acak (misal: <code>K8X2N9</code>) yang aktif selama 15 menit.</li>';
        echo '<li>Buka aplikasi Telegram dan cari bot: <a href="https://t.me/hackertkjsmksilber_bot" target="_blank" style="color:#38bdf8;font-weight:700">@hackertkjsmksilber_bot</a>.</li>';
        echo '<li>Kirimkan perintah: <code>/link &lt;KODE_6_DIGIT&gt;</code> (contoh: <code>/link K8X2N9</code>).</li>';
        echo '<li>Selesai! Bot akan langsung mengenali nama dan peran akun Anda di sekolah.</li>';
        echo '</ol>';
        echo '</div>';

        echo '<div class="card" style="background:rgba(12,20,34,0.85);border:1px solid rgba(2,132,199,0.3);padding:14px">';
        echo '<h4 style="margin:0 0 8px;color:#38bdf8;font-size:0.95rem">2. Daftar Perintah Umum di Bot</h4>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;font-size:0.82rem;color:#cbd5e1">';
        echo '<div><code>/start</code> : Sambutan bergambar &amp; menu utama</div>';
        echo '<div><code>/menu</code> : Membuka inline keyboard lengkap</div>';
        echo '<div><code>/profil</code> : Melihat data akun &amp; status avatar</div>';
        echo '<div><code>/koin</code> : Cek saldo koin sekolah</div>';
        echo '<div><code>/jadwal</code> : Jadwal pelajaran hari ini</div>';
        echo '<div><code>/tugas</code> : Daftar tugas &amp; tenggat waktu</div>';
        echo '<div><code>/ai &lt;tanya&gt;</code> : Bertanya langsung ke AI Si Silber</div>';
        echo '<div><code>/bantuan</code> : Bantuan &amp; panduan interaktif</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="card" style="background:rgba(12,20,34,0.85);border:1px solid rgba(249,115,22,0.4);padding:14px">';
        echo '<h4 style="margin:0 0 8px;color:var(--c-gold);font-size:0.95rem">3. Perintah Khusus Staf &amp; Guru (Admin Only)</h4>';
        echo '<div style="font-size:0.83rem;color:#cbd5e1;line-height:1.7">';
        echo '<div><code>/addstaff &lt;tg_id&gt; &lt;role&gt; [username] [password]</code> : Membuat akun web staf dan langsung menautkan Telegram!</div>';
        echo '<div><code>/delstaff &lt;tg_id/user&gt;</code> : Menonaktifkan akun staf dan mencabut sesi login.</div>';
        echo '<div><code>/liststaff</code> : Menampilkan daftar seluruh staf terdaftar beserta perannya.</div>';
        echo '<div><code>/setrole &lt;tg_id/user&gt; &lt;role&gt;</code> : Mengubah hak akses role pengguna.</div>';
        echo '<div><code>/resetpass &lt;tg_id/user&gt; [password]</code> : Mereset kata sandi login website staf/murid.</div>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        // =========================================================================
        // TUTORIAL 4: GURU & ADMIN
        // =========================================================================
        echo '<div class="card tutorial-card" data-category="guru" style="border-left:4px solid var(--c-orange);padding:22px 20px">';
        echo '<div class="row row--between row--wrap" style="align-items:center;margin-bottom:14px;gap:10px">';
        echo '<div class="row" style="gap:10px"><span style="font-size:1.8rem">👨‍🏫</span><div><h2 style="margin:0;font-size:1.25rem;color:#fff">Tutorial Guru, Wali Kelas &amp; Administrator</h2><span class="badge" style="background:linear-gradient(135deg,#ff6b00,#ea580c);color:#fff;border:none">Portal Staf Pengajar</span></div></div>';
        echo '<a href="/admin" class="btn btn--gold btn--sm" style="text-decoration:none">🛡️ Masuk Panel Admin →</a>';
        echo '</div>';

        echo '<div class="stack" style="gap:12px">';
        echo '<div class="card" style="background:rgba(12,20,34,0.85);border:1px solid rgba(255,107,0,0.3);padding:14px">';
        echo '<strong style="color:#fff">📌 Mengakses Dashboard Administrasi</strong>';
        echo '<p style="font-size:0.83rem;color:#cbd5e1;line-height:1.6;margin:4px 0 0">Masuk ke website dengan akun guru Anda. Klik tombol <b>🛡️ Admin</b> di navbar atas. Dari dashboard, guru dapat melihat statistik pendaftaran, memverifikasi akun siswa baru, melihat log keamanan, dan mengelola kelas.</p>';
        echo '</div>';

        echo '<div class="card" style="background:rgba(12,20,34,0.85);border:1px solid rgba(255,107,0,0.3);padding:14px">';
        echo '<strong style="color:#fff">📌 Menambah Akun Guru / Staf Baru via Web (ROOT Only)</strong>';
        echo '<p style="font-size:0.83rem;color:#cbd5e1;line-height:1.6;margin:4px 0 0">Pada kartu formulir <b>"Tambah Akun Staf / Guru Baru"</b> di halaman <code>/admin</code>, masukkan Username, Password (atau klik tombol acak sandi), Nama Lengkap, Role, dan ID Telegram staf yang bersangkutan. Klik simpan untuk menerbitkan akun secara otomatis.</p>';
        echo '</div>';

        echo '<div class="card" style="background:rgba(12,20,34,0.85);border:1px solid rgba(255,107,0,0.3);padding:14px">';
        echo '<strong style="color:#fff">📌 Membuat Tugas &amp; Melakukan Penilaian Siswa</strong>';
        echo '<p style="font-size:0.83rem;color:#cbd5e1;line-height:1.6;margin:4px 0 0">Di menu <b>Tugas</b> (<code>/tugas</code>), guru dapat membuat tugas baru dengan menetapkan deadline dan kelas sasaran. Klik pada nama tugas untuk memeriksa file tugas yang diunggah siswa dan memberikan nilai evaluasi secara transparan.</p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // ---- Tambahan 15 Okt 2026: soal PG/essay, jadwal kumpul, broadcast, moderasi chat, upload jadwal, Team ----
        echo '<div class="card tutorial-card" data-category="siswa" style="border-left:4px solid #22c55e;padding:22px 20px">';
        echo '<div class="row" style="gap:10px;margin-bottom:12px"><span style="font-size:1.8rem">🎮</span><div><h2 style="margin:0;font-size:1.25rem;color:#fff">Fitur Baru untuk Siswa</h2><span class="badge badge--neon">Oktober 2026</span></div></div>';
        echo '<ul style="font-size:0.88rem;color:#cbd5e1;line-height:1.7;margin:0;padding-left:20px">'
            . '<li><b>🎮 Mabar</b> sampai 15 orang di dashboard: buat / gabung pakai kode, undang teman, chat, mic, kapten pilih tujuan → berangkat bareng.</li>'
            . '<li><b>📝 Tugas Soal</b>: kerjakan pilihan ganda (nilai langsung keluar) &amp; essay langsung di halaman tugas.</li>'
            . '<li><b>🧭 Navigasi</b> di Dunia 3D: cari ruangan → Arahkan / Antar. Saat ada banner <b>📢 Kumpul</b>, ikuti arahnya — kehadiran tercatat.</li>'
            . '<li><b>🪑 Duduk</b> di kursi Dunia Pixel (E / S↓) &amp; karakter menghadap depan saat diam.</li>'
            . '<li><b>👤 Profil</b>: cari &amp; buka profil teman, lihat bio, lencana, sertifikat &amp; pencapaian; tambah teman, kirim pesan, ajak mabar.</li>'
            . '<li><b>🗑️ Hapus pesan</b> sendiri di Obrolan. <b>🐞 Lapor</b> bug / aduan langsung ke admin ROOT.</li>'
            . '<li><b>👻 Malam hari</b> ada 4 jenis anomali (Bayangan, Pocong, Kuntilanak, Mata Merah) di setiap server — selalu aktif.</li>'
            . '<li><b>🌸 Musim &amp; hari raya</b> semua agama menghiasi dashboard sesuai tanggal.</li></ul>';
        echo '</div>';

        echo '<div class="card tutorial-card" data-category="guru" style="border-left:4px solid #f59e0b;padding:22px 20px">';
        echo '<div class="row" style="gap:10px;margin-bottom:12px"><span style="font-size:1.8rem">🆕</span><div><h2 style="margin:0;font-size:1.25rem;color:#fff">Fitur Baru untuk Guru &amp; Admin</h2><span class="badge badge--gold">Oktober 2026</span></div></div>';
        echo '<div class="stack" style="gap:12px">';
        echo '<div class="card" style="background:rgba(12,20,34,0.85);border:1px solid rgba(255,107,0,0.3);padding:14px">';
        echo '<strong style="color:#fff">✅ Tugas bentuk Soal (Pilihan Ganda &amp; Essay)</strong>';
        echo '<p style="font-size:0.83rem;color:#cbd5e1;line-height:1.6;margin:4px 0 0">Di <b>/kelas → Buat Tugas</b> pilih jenis <b>Soal</b>. Tambah soal satu per satu atau tekan <b>📋 Tempel soal dari Word</b>: tulis nomor soal, pilihan A. B. C. D., dan beri tanda <b>*</b> di depan jawaban benar (soal tanpa pilihan = essay). Pilih <b>Server / Room</b> agar semua anggota room ikut menerima. Pilihan ganda dinilai otomatis; untuk essay buka tugas → <b>📋 Lihat jawaban</b> → isi poin essay → nilai akhir dihitung otomatis → Simpan.</p>';
        echo '</div>';
        echo '<div class="card" style="background:rgba(12,20,34,0.85);border:1px solid rgba(255,107,0,0.3);padding:14px">';
        echo '<strong style="color:#fff">📢 Jadwal Kumpul per Server</strong>';
        echo '<p style="font-size:0.83rem;color:#cbd5e1;line-height:1.6;margin:4px 0 0">Buka <b><a href="/kumpul">/kumpul</a></b>: pilih server → isi pelajaran, ruangan, hari/tanggal &amp; jam. Saat waktunya tiba semua murid di server itu (3D &amp; Pixel) mendapat banner <b>Kumpul di …</b> + navigasi; kehadiran tercatat otomatis dan bisa dilihat di halaman yang sama.</p>';
        echo '</div>';
        echo '<div class="card" style="background:rgba(12,20,34,0.85);border:1px solid rgba(255,107,0,0.3);padding:14px">';
        echo '<strong style="color:#fff">📤 Upload Jadwal Excel</strong>';
        echo '<p style="font-size:0.83rem;color:#cbd5e1;line-height:1.6;margin:4px 0 0">Di <b>/jadwal → Unggah Jadwal dari Excel</b>, tarik file .xlsx/.csv ke kotak. Tidak perlu rapi: kolom acak, kop judul, sel gabungan, salah ketik hari &amp; jam diperbaiki otomatis. Cek pratinjau → <b>Terapkan</b>. Salah? Pakai tab <b>Riwayat &amp; Rollback</b>.</p>';
        echo '</div>';
        echo '<div class="card" style="background:rgba(12,20,34,0.85);border:1px solid rgba(255,107,0,0.3);padding:14px">';
        echo '<strong style="color:#fff">📢 Broadcast (khusus ROOT)</strong>';
        echo '<p style="font-size:0.83rem;color:#cbd5e1;line-height:1.6;margin:4px 0 0">Konsol admin → tab <b>Broadcast</b>: kirim pengumuman ke semua / murid / staf. Muncul sebagai pop-up di dashboard <b>dan</b> masuk lonceng notifikasi. <b>Tes</b> = pratinjau di layar sendiri; <b>Hapus</b> menarik pop-up &amp; notifikasinya.</p>';
        echo '</div>';
        echo '<div class="card" style="background:rgba(12,20,34,0.85);border:1px solid rgba(255,107,0,0.3);padding:14px">';
        echo '<strong style="color:#fff">🛡️ Hapus &amp; Moderasi Chat</strong>';
        echo '<p style="font-size:0.83rem;color:#cbd5e1;line-height:1.6;margin:4px 0 0">Setiap pengguna bisa menghapus pesannya sendiri (🗑️). ROOT &amp; Kepala Sekolah bisa menghapus pesan siapa pun dan <b>tetap melihat isi pesan yang sudah dihapus</b> (bingkai kuning + siapa yang menghapus). Chat game: Konsol → Misi &amp; Chat → antrean moderasi.</p>';
        echo '</div>';
        echo '<div class="card" style="background:rgba(12,20,34,0.85);border:1px solid rgba(255,107,0,0.3);padding:14px">';
        echo '<strong style="color:#fff">👥 Halaman Team</strong>';
        echo '<p style="font-size:0.83rem;color:#cbd5e1;line-height:1.6;margin:4px 0 0"><a href="/team">/team</a> menampilkan pembuat &amp; contributor. Akun ROOT dapat menambah / menghapus contributor langsung di halaman itu.</p>';
        echo '</div>';
        echo '</div></div>';

        // =========================================================================
        // TUTORIAL 5: AI SI SILBER
        // =========================================================================
        echo '<div class="card tutorial-card" data-category="ai" style="border-left:4px solid var(--c-neon);padding:22px 20px">';
        echo '<div class="row row--between row--wrap" style="align-items:center;margin-bottom:14px;gap:10px">';
        echo '<div class="row" style="gap:10px"><span style="font-size:1.8rem">🌌</span><div><h2 style="margin:0;font-size:1.25rem;color:#fff">Tutorial Asisten AI Si Silber</h2><span class="badge badge--neon">Pendamping AI Cerdas</span></div></div>';
        echo '<button type="button" class="btn btn--gold btn--sm" data-open-sheet="vcAiMascotSheet">💬 Buka Chat Sekarang</button>';
        echo '</div>';

        echo '<p class="muted" style="font-size:0.86rem;line-height:1.6">Si Silber dirancang untuk membantu Anda di setiap sudut Virtual City:</p>';
        echo '<div class="card-grid card-grid--3" style="gap:10px;margin-top:10px">';
        echo '<div class="card" style="background:rgba(12,20,34,0.85);padding:12px;border:1px solid rgba(56,189,248,0.25)"><strong style="color:var(--c-primary-2)">🌐 Di Web Browser</strong><p style="font-size:0.8rem;color:#cbd5e1;margin:4px 0 0">Klik tombol bulat di pojok kanan bawah atau ikon di navbar atas untuk memunculkan lembar percakapan.</p></div>';
        echo '<div class="card" style="background:rgba(12,20,34,0.85);padding:12px;border:1px solid rgba(255,107,0,0.25)"><strong style="color:var(--c-gold)">🎮 Di Dunia 3D</strong><p style="font-size:0.8rem;color:#cbd5e1;margin:4px 0 0">Klik tombol <b>🤖 Si Silber</b> di HUD atas saat bermain untuk bertanya tentang rute denah, guru, atau petunjuk misi.</p></div>';
        echo '<div class="card" style="background:rgba(12,20,34,0.85);padding:12px;border:1px solid rgba(34,211,238,0.25)"><strong style="color:#22d3ee">✈️ Di Telegram</strong><p style="font-size:0.8rem;color:#cbd5e1;margin:4px 0 0">Kirim <code>/ai &lt;pertanyaan&gt;</code> ke <code>@hackertkjsmksilber_bot</code> untuk jawaban instan langsung di chat pribadi.</p></div>';
        echo '</div>';
        echo '</div>';

        // =========================================================================
        // TUTORIAL PWA: PASANG APLIKASI DI HP & LAPTOP
        // =========================================================================
        echo '<div class="card tutorial-card" data-category="pwa" style="border-left:4px solid #0284c7;padding:22px 20px">';
        echo '<div class="row row--between row--wrap" style="align-items:center;margin-bottom:14px;gap:10px">';
        echo '<div class="row" style="gap:10px"><span style="font-size:1.8rem">📲</span><div><h2 style="margin:0;font-size:1.25rem;color:#fff">Panduan Pasang Aplikasi (PWA)</h2><span class="badge" style="background:rgba(2,132,199,0.18);color:#7dd3fc;border-color:#0284c7">Aplikasi Mandiri (PWA)</span></div></div>';
        echo '<button type="button" class="btn btn--gold btn--sm" onclick="if(window.pwa){pwa.promptInstall();}" style="font-weight:bold;padding:8px 14px;border-radius:8px">📲 Pasang Aplikasi Sekarang</button>';
        echo '</div>';
        echo '<p class="muted" style="font-size:0.86rem;line-height:1.6">Aplikasi Virtual City SMK Silber dapat dipasang di layar utama HP (Android, iPhone) dan desktop komputer tanpa perlu download file APK atau melalui Play Store! Nikmati pengalaman layar penuh, akses offline, dan performa lebih responsif.</p>';

        echo '<div class="stack" style="gap:12px;margin-top:14px">';

        echo '<div class="card" style="background:rgba(12,20,34,0.8);border:1px solid rgba(56,189,248,0.25);padding:14px">';
        echo '<div class="row" style="gap:8px;margin-bottom:6px"><span class="badge" style="background:#10b981;color:#04121a;font-weight:800">ANDROID</span><strong style="color:#fff">Google Chrome &amp; Browser Bawaan HP</strong></div>';
        echo '<ol style="margin:0;padding-left:20px;font-size:0.84rem;color:#cbd5e1;line-height:1.6">';
        echo '<li>Buka website di browser Google Chrome ponsel Anda.</li>';
        echo '<li>Ketuk tombol <b>"📲 Pasang"</b> di bilah atas atau banner di bawah layar, atau ketuk <b>menu titik tiga (⋮)</b> di pojok kanan atas browser.</li>';
        echo '<li>Pilih menu <b>"Instal aplikasi"</b> atau <b>"Tambahkan ke Layar Utama"</b>.</li>';
        echo '<li>Konfirmasi dengan memilih <b>Instal</b>. Ikon Virtual City SMK Silber akan langsung muncul di beranda HP Anda!</li>';
        echo '</ol></div>';

        echo '<div class="card" style="background:rgba(12,20,34,0.8);border:1px solid rgba(56,189,248,0.25);padding:14px">';
        echo '<div class="row" style="gap:8px;margin-bottom:6px"><span class="badge" style="background:#06b6d4;color:#04121a;font-weight:800">IPHONE / IPAD</span><strong style="color:#fff">Browser Safari iOS</strong></div>';
        echo '<ol style="margin:0;padding-left:20px;font-size:0.84rem;color:#cbd5e1;line-height:1.6">';
        echo '<li>Buka website menggunakan browser <b>Safari</b> di perangkat iOS.</li>';
        echo '<li>Ketuk tombol <b>Bagikan (Share) ⎋</b> di bilah bawah Safari.</li>';
        echo '<li>Gulir ke bawah dan ketuk opsi <b>"Tambahkan ke Layar Utama" (Add to Home Screen) ⊞</b>.</li>';
        echo '<li>Ketuk <b>"Tambah"</b> di sudut kanan atas layar. Aplikasi siap dimainkan dalam layar penuh!</li>';
        echo '</ol></div>';

        echo '<div class="card" style="background:rgba(12,20,34,0.8);border:1px solid rgba(56,189,248,0.25);padding:14px">';
        echo '<div class="row" style="gap:8px;margin-bottom:6px"><span class="badge" style="background:#f97316;color:#04121a;font-weight:800">KOMPUTER / LAPTOP</span><strong style="color:#fff">Google Chrome / Microsoft Edge / Brave</strong></div>';
        echo '<ol style="margin:0;padding-left:20px;font-size:0.84rem;color:#cbd5e1;line-height:1.6">';
        echo '<li>Klik ikon <b>Pasang Aplikasi ⊕</b> yang muncul di sebelah kanan bilah alamat (URL bar) browser.</li>';
        echo '<li>Atau klik menu titik tiga (⋮) browser → pilih <b>"Instal Virtual City SMK Silber"</b>.</li>';
        echo '<li>Aplikasi akan berjalan sebagai jendela terpisah mandiri yang ringan dan bebas gangguan tab browser.</li>';
        echo '</ol></div>';

        echo '</div></div>';

        // =========================================================================
        // TUTORIAL 6: FAQ & TANYA JAWAB
        // =========================================================================
        echo '<div class="card tutorial-card" data-category="faq" style="border-left:4px solid #a855f7;padding:22px 20px">';
        echo '<div class="row" style="gap:10px;margin-bottom:14px"><span style="font-size:1.8rem">❓</span><div><h2 style="margin:0;font-size:1.25rem;color:#fff">Pertanyaan yang Sering Ditanyakan (FAQ)</h2><span class="badge" style="background:rgba(168,85,247,0.15);color:#d8b4fe;border-color:#9333ea">Tanya Jawab</span></div></div>';

        echo '<div class="stack" style="gap:10px">';
        $faqs = [
            ['Apakah saya wajib menautkan Telegram untuk bisa bermain di web?', 'Tidak, penautan akun Telegram bersifat sepenuhnya opsional. Siswa dan staf tetap dapat menikmati seluruh fitur web dan dunia 3D tanpa akun Telegram.'],
            ['Bagaimana cara memasang aplikasi Virtual City di HP atau laptop?', 'Tekan tombol "📲 Pasang" di bilah atas web atau buka tab "📲 Pasang Aplikasi". Di Android dan Chrome, konfirmasi jendela pop-up instalasi. Di iPhone/iPad, ketuk tombol Bagikan (Share ⎋) di Safari lalu pilih "Tambahkan ke Layar Utama" ⊞.'],
            ['Game 3D terasa lambat atau patah-patah di HP lama saya, apa solusinya?', 'Sistem menyediakan tombol pengatur kualitas grafis di HUD game: "⚙️ Grafis: Auto". Anda dapat beralih ke mode "Grafis: Rendah" untuk kinerja yang lebih ringan dan lancar, atau beralih ke Mode Peta Denah 2D (/peta).'],
            ['Bagaimana cara membuka seragam atau aksesoris avatar baru?', 'Selesaikan misi harian untuk mengumpulkan koin sekolah (🪙). Setelah koin mencukupi, buka Toko Avatar (/toko) atau Studio Karakter (/karakter) untuk membuka kostum yang Anda inginkan.'],
            ['Lupa kata sandi akun web, bagaimana cara memperbaikinya?', 'Silakan hubungi Guru Pengajar atau Administrator sekolah. Admin ROOT dapat mereset kata sandi Anda dalam hitungan detik melalui panel admin web atau perintah Telegram /resetpass.'],
        ];
        foreach ($faqs as [$q, $a]) {
            echo '<div class="card" style="background:rgba(12,20,34,0.85);border:1px solid rgba(255,255,255,0.1);padding:14px">';
            echo '<strong style="color:#fff;font-size:0.9rem">Q: ' . htmlspecialchars($q, ENT_QUOTES) . '</strong>';
            echo '<p style="margin:6px 0 0;font-size:0.83rem;color:#cbd5e1;line-height:1.5">' . htmlspecialchars($a, ENT_QUOTES) . '</p>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';

        echo '</div>'; // End tutorialArticles stack

        // Inline client-side filter and tab switcher script
        echo '<script nonce="' . $nonce . '">
function vcSwitchTab(category, btn) {
  var row = document.getElementById("tutorialTabRow");
  if (row) {
    row.querySelectorAll("button").forEach(function(b) {
      b.classList.remove("btn--primary", "is-active");
      b.classList.add("btn--secondary");
    });
  }
  if (btn) {
    btn.classList.remove("btn--secondary");
    btn.classList.add("btn--primary", "is-active");
  }

  var cards = document.querySelectorAll(".tutorial-card");
  cards.forEach(function(card) {
    var cat = card.getAttribute("data-category");
    if (category === "semua" || cat === category) {
      card.style.display = "block";
    } else {
      card.style.display = "none";
    }
  });
}

function vcFilterTutorials(query) {
  var q = (query || "").toLowerCase().trim();
  var cards = document.querySelectorAll(".tutorial-card");
  cards.forEach(function(card) {
    var text = card.textContent.toLowerCase();
    if (!q || text.indexOf(q) !== -1) {
      card.style.display = "block";
    } else {
      card.style.display = "none";
    }
  });
}

document.addEventListener("DOMContentLoaded", function() {
  var params = new URLSearchParams(window.location.search);
  var tabParam = params.get("tab") || (window.location.hash ? window.location.hash.replace("#","") : "");
  if (tabParam) {
    var targetBtn = document.querySelector("#tutorialTabRow button[onclick*=\'" + tabParam + "\']");
    if (targetBtn) targetBtn.click();
  }
});
</script>';

        Shell::end([
            'user'   => $user,
            'active' => 'help',
            'nav'    => true
        ]);
    }
}

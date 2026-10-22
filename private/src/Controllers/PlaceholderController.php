<?php
// PlaceholderController: honest "in development" page for sections scheduled in later phases.
// This keeps navigation intact during the phased build (never a broken link, never fake data).
declare(strict_types=1);

namespace VC\Controllers;

use VC\I18n;
use VC\View\Shell;

final class PlaceholderController
{
    /** Map of route section => [phase, title, description]. */
    private const SECTIONS = [
        'events' => [6, 'Event & Kompetisi', 'Kalender event, kompetisi, live quiz, dan sertifikat digital sedang dibangun pada Fase 6.'],
        'tasks' => [6, 'Tugas & Pembelajaran', 'Materi, tugas, kuis, upload, penilaian, dan buku nilai sedang dibangun pada Fase 6.'],
        'chat' => [7, 'Chat & Sosial', 'Pesan langsung, grup kelas, stories, dan fitur sosial sedang dibangun pada Fase 7.'],
        'profile' => [7, 'Profil & Kartu Pelajar', 'Profil, kartu pelajar digital (QR), teman, dan galeri karya sedang dibangun pada Fase 7.'],
    ];

    public static function render(string $section): void
    {
        $info = self::SECTIONS[$section] ?? [9, 'Segera Hadir', 'Bagian ini akan tersedia pada fase berikutnya.'];
        [$phase, $title, $desc] = $info;

        Shell::start([
            'title' => $title . ' — ' . I18n::t('app.name'),
            'active' => $section,
        ]);

        echo '<div class="section" style="max-width:640px;margin:0 auto;text-align:center">';
        echo '<div class="card card--glass">';
        echo '<div style="font-size:3.5rem">🚧</div>';
        echo '<h2 class="page-title">' . htmlspecialchars($title, ENT_QUOTES) . '</h2>';
        echo '<p class="muted">' . htmlspecialchars($desc, ENT_QUOTES) . '</p>';
        echo '<p><span class="badge badge--gold">Fase ' . (int)$phase . '</span></p>';
        echo '<a class="btn" href="/dunia">🎮 ' . htmlspecialchars(I18n::t('nav.world'), ENT_QUOTES) . '</a> ';
        echo '<a class="btn btn--secondary" href="/">' . htmlspecialchars(I18n::t('nav.home'), ENT_QUOTES) . '</a>';
        echo '</div></div>';

        Shell::end(['active' => $section]);
    }
}

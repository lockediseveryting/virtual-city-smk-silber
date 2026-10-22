<?php
// HomeController: beranda = dashboard game pixel-art (kota + karakter + menu papan kayu).
// Belum login → layar judul / login.
declare(strict_types=1);

namespace VC\Controllers;

use VC\I18n;
use VC\Security\Auth;
use VC\View\PixelDashboard;
use VC\View\Shell;

final class HomeController
{
    private const STAFF_ROLES = ['guru', 'wali_kelas', 'kepala_program', 'kepala_sekolah', 'root'];

    public static function render(): void
    {
        $user = Auth::user();
        if ($user === null) {
            AuthController::renderLogin();
            return;
        }

        Shell::start([
            'title' => I18n::t('home.title') . ' — ' . I18n::t('app.name'),
            'active' => 'home',
            'user' => $user,
            'topbar' => false,
            'nav' => false,
            'shell' => false,
            'bodyClass' => 'px-dash',
            'head' => PixelDashboard::CSS,
        ]);

        $menu = [
            ['/dunia', '🎮', 'Dunia 3D', 'Masuk kelas virtual 3D', 'gold'],
            ['/pixel', '🧱', 'Dunia Pixel', 'Versi 2D ringan'],
            ['/jadwal', '📅', 'Jadwal', 'Pelajaran hari ini'],
            ['/gabung', '🏫', 'Gabung Kelas', 'Pakai kode dari guru'],
            ['/event', '🎉', 'Event', 'Kegiatan sekolah'],
            ['/chat', '💬', 'Chat', 'Ngobrol dengan teman'],
        ];
        $icons = [];
        if (in_array($user['role'] ?? '', self::STAFF_ROLES, true)) {
            $icons[] = ['/admin', '🛡️', 'Panel Admin'];
        }
        $icons[] = ["js:UI.openSheet('vcNotifSheet');vcLoadNotifs()", '🔔', 'Notifikasi'];
        $icons[] = ["js:UI.openSheet('vcSettingsSheet')", '⚙️', 'Pengaturan'];
        $icons[] = ['js:vcLogout()', '🚪', 'Keluar'];

        PixelDashboard::render($user, $menu, $icons, 'MENU UTAMA');
        echo PixelDashboard::JS;

        Shell::end(['user' => $user, 'active' => 'home', 'nav' => false]);
    }
}

<?php
// Migration runner: idempotent, ordered migrations. CLI only: php private/bin/migrate.php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$pdo = \VC\Database::get();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    id INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL, applied_at TEXT NOT NULL)');

$migrations = [
    '001_phase1_core' => static function (\PDO $pdo): void {
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid TEXT UNIQUE NOT NULL,             -- public random id (never sequential in URLs)
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "murid",
            full_name TEXT NOT NULL DEFAULT "",
            nis TEXT,
            class_id INTEGER,
            status TEXT NOT NULL DEFAULT "active", -- active | pending | suspended | graduated
            must_change_password INTEGER NOT NULL DEFAULT 0,
            deleted_at TEXT,
            created_at TEXT)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_nis ON users(nis)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS user_permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            permission TEXT NOT NULL,
            scope_type TEXT NOT NULL DEFAULT "global", -- global | program | class | org
            scope_id TEXT NOT NULL DEFAULT "0",
            granted_by INTEGER,
            granted_at TEXT,
            expires_at INTEGER)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_up_user ON user_permissions(user_id)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts TEXT NOT NULL,
            user_id INTEGER,
            ip TEXT,
            action TEXT NOT NULL,
            result TEXT NOT NULL DEFAULT "ok",
            ctx TEXT NOT NULL DEFAULT "{}",
            chain_hash TEXT NOT NULL)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TEXT)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS terms_acceptances (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            terms_version TEXT NOT NULL,
            accepted_at TEXT)');
    },
    '002_session_support' => static function (\PDO $pdo): void {
        // Server-side session bookkeeping for device list + remote logout.
        $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
            id TEXT PRIMARY KEY,                 -- session id HASH only
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            device TEXT, ip TEXT, created_at TEXT, last_active TEXT, revoked INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id)');
    },
    '003_school_structure' => static function (\PDO $pdo): void {
        $pdo->exec('CREATE TABLE IF NOT EXISTS programs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,          -- TJKT | AKL | BD | DKV
            name TEXT NOT NULL,
            accent_color TEXT NOT NULL DEFAULT "#00b06f",
            created_at TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid TEXT UNIQUE NOT NULL,
            program_id INTEGER NOT NULL REFERENCES programs(id),
            grade TEXT NOT NULL,                -- X | XI | XII
            name TEXT NOT NULL,                 -- e.g. "X TJKT 1"
            wali_kelas_user_id INTEGER,
            deleted_at TEXT)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_class_name ON classes(name) WHERE deleted_at IS NULL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS student_roster (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nis TEXT UNIQUE NOT NULL,
            full_name TEXT NOT NULL,
            class_id INTEGER REFERENCES classes(id),
            claimed_by_user_id INTEGER,         -- set when a registered user matched this row
            created_at TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_roster_nis ON student_roster(nis)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS class_members (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            class_id INTEGER NOT NULL REFERENCES classes(id),
            PRIMARY KEY (user_id, class_id))');
        // Registration requests (status: pending | approved | rejected)
        $pdo->exec('CREATE TABLE IF NOT EXISTS registrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reg_uid TEXT UNIQUE NOT NULL,
            full_name TEXT NOT NULL,
            nis TEXT NOT NULL,
            class_id INTEGER REFERENCES classes(id),
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            decided_by INTEGER,
            decided_at TEXT,
            reason TEXT,
            created_at TEXT,
            ip TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reg_status ON registrations(status)');
        // Approvals must purge this table when approved (row moves to users).
        // Seed the four official programs (editable later from dashboard).
        $seed = $pdo->prepare('INSERT INTO programs (code, name, accent_color, created_at) VALUES (?, ?, ?, datetime("now"))');
        foreach ([
            ['TJKT', 'Teknik Jaringan Komputer dan Telekomunikasi', '#22d3ee'],
            ['AKL', 'Akuntansi dan Keuangan Lembaga', '#22c55e'],
            ['BD', 'Bisnis Digital', '#f97316'],
            ['DKV', 'Desain Komunikasi Visual', '#e879f9'],
        ] as $p) {
            try { $seed->execute($p); } catch (Throwable $e) {}
        }
    },
    '004_schedule_system' => static function (\PDO $pdo): void {
        // Physical campus rooms
        $pdo->exec('CREATE TABLE IF NOT EXISTS rooms_physical (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            floor INTEGER NOT NULL DEFAULT 1,
            building TEXT NOT NULL DEFAULT "A",
            capacity INTEGER NOT NULL DEFAULT 36,
            created_at TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rooms_code ON rooms_physical(code)');

        // Subjects (Mapel)
        $pdo->exec('CREATE TABLE IF NOT EXISTS subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            program_id INTEGER REFERENCES programs(id),
            created_at TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_subjects_code ON subjects(code)');

        // Standard bell / timetable slots
        $pdo->exec('CREATE TABLE IF NOT EXISTS time_slots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            day_of_week INTEGER NOT NULL,
            period_number INTEGER NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            is_break INTEGER NOT NULL DEFAULT 0,
            label TEXT NOT NULL DEFAULT ""
        )');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_slot_day_period ON time_slots(day_of_week, period_number)');

        // Schedule versions (immutable history, rollback ready)
        $pdo->exec('CREATE TABLE IF NOT EXISTS schedule_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            version_uid TEXT UNIQUE NOT NULL,
            mode TEXT NOT NULL DEFAULT "weekly",
            target_date TEXT,
            filename TEXT NOT NULL,
            file_hash TEXT NOT NULL,
            total_rows INTEGER NOT NULL,
            valid_rows INTEGER NOT NULL,
            created_by INTEGER REFERENCES users(id),
            created_at TEXT,
            status TEXT NOT NULL DEFAULT "active",
            rollback_at TEXT,
            rollback_by INTEGER REFERENCES users(id)
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sched_ver_status ON schedule_versions(status)');

        // Regular weekly base schedule entries
        $pdo->exec('CREATE TABLE IF NOT EXISTS schedule_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            version_id INTEGER NOT NULL REFERENCES schedule_versions(id) ON DELETE CASCADE,
            day_of_week INTEGER NOT NULL,
            period_number INTEGER NOT NULL,
            class_id INTEGER NOT NULL REFERENCES classes(id),
            subject_id INTEGER NOT NULL REFERENCES subjects(id),
            teacher_user_id INTEGER REFERENCES users(id),
            room_id INTEGER REFERENCES rooms_physical(id),
            notes TEXT,
            created_at TEXT
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_se_class_day ON schedule_entries(class_id, day_of_week)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_se_teacher ON schedule_entries(teacher_user_id, day_of_week)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_se_room ON schedule_entries(room_id, day_of_week)');

        // Daily date-specific overrides
        $pdo->exec('CREATE TABLE IF NOT EXISTS schedule_overrides (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            version_id INTEGER REFERENCES schedule_versions(id) ON DELETE CASCADE,
            date TEXT NOT NULL,
            period_number INTEGER NOT NULL,
            class_id INTEGER NOT NULL REFERENCES classes(id),
            action TEXT NOT NULL DEFAULT "substitute",
            subject_id INTEGER REFERENCES subjects(id),
            teacher_user_id REFERENCES users(id),
            room_id INTEGER REFERENCES rooms_physical(id),
            notes TEXT,
            created_at TEXT,
            created_by INTEGER REFERENCES users(id)
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_so_date_class ON schedule_overrides(date, class_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_so_date_teacher ON schedule_overrides(date, teacher_user_id)');

        // Seed physical rooms
        $rooms = [
            ['R01', 'Ruang 1', 2, 'A', 36],
            ['R02', 'Ruang 2', 2, 'A', 36],
            ['R03', 'Ruang 3', 1, 'A', 36],
            ['R04', 'Ruang 4', 1, 'B', 36],
            ['R05', 'Ruang 5', 1, 'B', 36],
            ['R06', 'Ruang 6', 1, 'B', 36],
            ['R07', 'Ruang 7', 1, 'B', 36],
            ['R08', 'Ruang 8', 2, 'B', 36],
            ['R09', 'Ruang 9', 2, 'B', 36],
            ['R10', 'Ruang 10', 1, 'C', 36],
            ['R11', 'Ruang 11', 1, 'C', 36],
            ['R12', 'Ruang 12', 2, 'C', 36],
            ['R13', 'Ruang 13', 2, 'C', 36],
            ['LAB_TIK_1', 'Lab. TIK 1 (Bawah)', 1, 'B', 40],
            ['LAB_TIK_2', 'Lab. TIK 2 (Atas)', 2, 'B', 40],
            ['LAB_RAKITAN', 'Lab. Rakitan Komputer', 2, 'A', 36],
            ['PERPUS', 'Perpustakaan', 1, 'C', 60],
            ['BANK_MINI', 'Bank Mini SMK Silber', 1, 'B', 25],
            ['MINI_MARKET', 'Mini Market SMK Silber', 1, 'B', 25],
            ['RUANG_PRAKTIK', 'Ruang Praktik Siswa', 1, 'A', 40],
            ['KANTOR', 'Kantor Guru & Kepala Sekolah', 1, 'A', 30],
            ['RUANG_TU', 'Ruang Tata Usaha (TU)', 1, 'A', 15],
            ['RUANG_BK', 'Ruang BK & BKK', 2, 'A', 15],
            ['RUANG_SENI', 'Ruang Seni & Studio DKV', 1, 'A', 36],
            ['RUANG_IPM', 'Ruang Organisasi IPM / OSIS', 1, 'A', 25],
            ['RUANG_INDUSTRI', 'Ruang Silber Industries', 1, 'A', 30],
            ['MUSHOLAH', 'Musholah Sekolah', 1, 'D', 100],
            ['LAP_FUTSAL', 'Lapangan Futsal & Olahraga', 1, 'Luar', 50],
        ];
        $insRoom = $pdo->prepare('INSERT OR IGNORE INTO rooms_physical (code, name, floor, building, capacity, created_at) VALUES (?, ?, ?, ?, ?, datetime("now"))');
        foreach ($rooms as $r) {
            $insRoom->execute($r);
        }

        // Seed core subjects
        $subjects = [
            ['PAI', 'Pendidikan Agama Islam', null],
            ['PKN', 'Pendidikan Pancasila dan Kewarganegaraan', null],
            ['BIN', 'Bahasa Indonesia', null],
            ['BING', 'Bahasa Inggris', null],
            ['MTK', 'Matematika Terapan', null],
            ['SEJ', 'Sejarah Indonesia', null],
            ['PJOK', 'Pendidikan Jasmani & Olahraga', null],
            ['SBK', 'Seni Budaya', null],
            ['INF', 'Informatika Dasar', null],
            ['IPAS', 'Projek IPAS', null],
            ['TJKT_JARDAS', 'Dasar Jaringan Komputer & Telekomunikasi', 1],
            ['TJKT_ROUTER', 'Administrasi Infrastruktur Jaringan (MikroTik/Cisco)', 1],
            ['TJKT_SERVER', 'Administrasi Sistem Jaringan (Linux/Server)', 1],
            ['TJKT_CYBER', 'Keamanan Jaringan & Cyber Security Dasar', 1],
            ['AKL_DASAR', 'Dasar-Dasar Akuntansi & Keuangan Lembaga', 2],
            ['AKL_PERBANKAN', 'Perbankan Dasar & Bank Mini', 2],
            ['AKL_MYOB', 'Komputer Akuntansi (Spreadsheet & Software)', 2],
            ['BD_MARKET', 'Digital Marketing & Social Media Strategy', 3],
            ['BD_ECOMMERCE', 'Pengelolaan Bisnis Online & E-Commerce', 3],
            ['BD_RETAIL', 'Operasional Toko & Retail Mini Market', 3],
            ['DKV_DESAIN', 'Dasar Desain Grafis & Komposisi Visual', 4],
            ['DKV_VEKTOR', 'Desain Vektor & Ilustrasi Digital', 4],
            ['DKV_FOTO', 'Fotografi & Videografi Produk', 4],
        ];
        $insSubj = $pdo->prepare('INSERT OR IGNORE INTO subjects (code, name, program_id, created_at) VALUES (?, ?, ?, datetime("now"))');
        foreach ($subjects as $s) {
            $insSubj->execute($s);
        }

        // Seed timetable slots (Senin s/d Jumat)
        $insSlot = $pdo->prepare('INSERT OR IGNORE INTO time_slots (day_of_week, period_number, start_time, end_time, is_break, label) VALUES (?, ?, ?, ?, ?, ?)');
        for ($day = 1; $day <= 5; $day++) {
            $slots = [
                [0, '07:00', '07:30', 1, $day === 1 ? 'Upacara Bendera' : 'Apel Pagi / Literasi'],
                [1, '07:30', '08:15', 0, 'Jam Ke-1'],
                [2, '08:15', '09:00', 0, 'Jam Ke-2'],
                [3, '09:00', '09:45', 0, 'Jam Ke-3'],
                [4, '09:45', '10:05', 1, 'Istirahat 1'],
                [5, '10:05', '10:50', 0, 'Jam Ke-4'],
                [6, '10:50', '11:35', 0, 'Jam Ke-5'],
                [7, '11:35', '12:20', 1, 'Sholat Dzuhur & Istirahat 2'],
                [8, '12:20', '13:05', 0, 'Jam Ke-6'],
                [9, '13:05', '13:50', 0, 'Jam Ke-7'],
                [10, '13:50', '14:35', 0, 'Jam Ke-8'],
            ];
            foreach ($slots as $sl) {
                $insSlot->execute([$day, $sl[0], $sl[1], $sl[2], $sl[3], $sl[4]]);
            }
        }
    },
    '005_learning_events' => static function (\PDO $pdo): void {
        // 1. Learning materials
        $pdo->exec('CREATE TABLE IF NOT EXISTS materials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_id INTEGER NOT NULL REFERENCES subjects(id),
            teacher_id INTEGER NOT NULL REFERENCES users(id),
            class_id INTEGER NOT NULL REFERENCES classes(id),
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            content_type TEXT NOT NULL DEFAULT "text", -- text | pdf | video | link
            content_url TEXT,
            file_path TEXT,
            published_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mat_class ON materials(class_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mat_subject ON materials(subject_id)');

        // 2. Assignments & submissions
        $pdo->exec('CREATE TABLE IF NOT EXISTS assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_id INTEGER NOT NULL REFERENCES subjects(id),
            teacher_id INTEGER NOT NULL REFERENCES users(id),
            class_id INTEGER NOT NULL REFERENCES classes(id),
            title TEXT NOT NULL,
            instructions TEXT NOT NULL,
            deadline TEXT NOT NULL,
            max_score INTEGER NOT NULL DEFAULT 100,
            allow_late INTEGER NOT NULL DEFAULT 1,
            late_penalty INTEGER NOT NULL DEFAULT 10,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_asg_class ON assignments(class_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_asg_deadline ON assignments(deadline)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            assignment_id INTEGER NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
            student_id INTEGER NOT NULL REFERENCES users(id),
            content_text TEXT,
            file_name TEXT,
            file_path TEXT,
            submitted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_late INTEGER NOT NULL DEFAULT 0,
            score INTEGER,
            feedback TEXT,
            graded_at TEXT,
            graded_by INTEGER REFERENCES users(id),
            UNIQUE(assignment_id, student_id))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sub_student ON submissions(student_id)');

        // 3. Quizzes & question banks
        $pdo->exec('CREATE TABLE IF NOT EXISTS quizzes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_id INTEGER NOT NULL REFERENCES subjects(id),
            teacher_id INTEGER NOT NULL REFERENCES users(id),
            class_id INTEGER NOT NULL REFERENCES classes(id),
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            duration_minutes INTEGER NOT NULL DEFAULT 30,
            passing_score INTEGER NOT NULL DEFAULT 75,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS quiz_questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quiz_id INTEGER NOT NULL REFERENCES quizzes(id) ON DELETE CASCADE,
            question_text TEXT NOT NULL,
            question_type TEXT NOT NULL DEFAULT "multiple_choice", -- multiple_choice | true_false | short_answer
            points INTEGER NOT NULL DEFAULT 10,
            order_num INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_qq_quiz ON quiz_questions(quiz_id)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS quiz_options (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question_id INTEGER NOT NULL REFERENCES quiz_questions(id) ON DELETE CASCADE,
            option_text TEXT NOT NULL,
            is_correct INTEGER NOT NULL DEFAULT 0,
            order_num INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_qo_question ON quiz_options(question_id)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS quiz_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quiz_id INTEGER NOT NULL REFERENCES quizzes(id),
            student_id INTEGER NOT NULL REFERENCES users(id),
            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at TEXT,
            score INTEGER NOT NULL DEFAULT 0,
            max_score INTEGER NOT NULL DEFAULT 100,
            passed INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_qa_student ON quiz_attempts(student_id)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS quiz_answers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            attempt_id INTEGER NOT NULL REFERENCES quiz_attempts(id) ON DELETE CASCADE,
            question_id INTEGER NOT NULL REFERENCES quiz_questions(id),
            selected_option_id INTEGER REFERENCES quiz_options(id),
            answer_text TEXT,
            is_correct INTEGER NOT NULL DEFAULT 0,
            points_earned INTEGER NOT NULL DEFAULT 0)');

        // 4. Organizations & Extracurriculars
        $pdo->exec('CREATE TABLE IF NOT EXISTS organizations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            category TEXT NOT NULL DEFAULT "ekskul", -- organisasi | ekskul | komunitas
            description TEXT NOT NULL DEFAULT "",
            logo TEXT,
            room_location TEXT,
            regular_schedule TEXT,
            quota INTEGER NOT NULL DEFAULT 50,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS org_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            org_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            role TEXT NOT NULL DEFAULT "anggota", -- pembina | ketua | pengurus | anggota
            status TEXT NOT NULL DEFAULT "pending", -- pending | approved | rejected
            joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(org_id, user_id))');

        // 5. Events & Attendance
        $pdo->exec('CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            event_type TEXT NOT NULL DEFAULT "activity", -- activity | live_quiz | competition | meeting
            map_location TEXT,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            quota INTEGER NOT NULL DEFAULT 100,
            rewards_xp INTEGER NOT NULL DEFAULT 50,
            rewards_coins INTEGER NOT NULL DEFAULT 25,
            status TEXT NOT NULL DEFAULT "published", -- draft | published | running | finished | cancelled
            created_by INTEGER REFERENCES users(id),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS event_attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            attended_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            verification_code TEXT NOT NULL,
            UNIQUE(event_id, user_id))');

        $pdo->exec('CREATE TABLE IF NOT EXISTS certificates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id),
            event_id INTEGER REFERENCES events(id),
            title TEXT NOT NULL,
            cert_number TEXT UNIQUE NOT NULL,
            verify_hash TEXT NOT NULL,
            issued_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

        // Seed default school organizations & clubs
        $orgs = [
            ['Ikatan Pelajar Muhammadiyah / OSIS', 'ipm-osis', 'organisasi', 'Organisasi kesiswaan resmi SMK Silber pencetak kader pemimpin berakhlak mulia.', 'Ruang IPM', 'Setiap Sabtu 10:00', 60],
            ['Cyber Security & Network Club', 'cyber-club', 'komunitas', 'Klub riset keamanan jaringan, ethical hacking, CTF, dan simulasi pertahanan siber.', 'Lab TIK 1', 'Setiap Rabu & Jumat 15:30', 35],
            ['Software Engineering & AI Dev', 'rpl-dev', 'komunitas', 'Pengembangan aplikasi web, mobile, robotika, dan kecerdasan buatan SMK Silber.', 'Lab TIK 2', 'Setiap Kamis 15:30', 40],
            ['Multimedia & DKV Creative Studio', 'dkv-studio', 'ekskul', 'Klub produksi video, fotografi, 3D modeling, desain grafis, dan animasi digital.', 'Ruang Seni', 'Setiap Selasa 15:30', 45],
            ['Futsal & Sport Club', 'futsal-club', 'ekskul', 'Latihan rutin futsal, kebugaran fisik, turnamen antar-sekolah dan kejuaraan daerah.', 'Lapangan Futsal', 'Setiap Senin & Kamis 16:00', 50],
            ['Bank Mini & Keuangan Syariah', 'bank-mini-club', 'komunitas', 'Praktik perbankan nyata, akuntansi mikro, kasir digital, dan literasi finansial.', 'Bank Mini', 'Setiap Jumat 13:30', 30],
        ];
        $insOrg = $pdo->prepare('INSERT OR IGNORE INTO organizations (name, slug, category, description, room_location, regular_schedule, quota) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($orgs as $o) {
            $insOrg->execute($o);
        }

        // Seed initial school events
        $events = [
            ['MPLS Virtual Campus Tour', 'Orientasi Pengenalan Lingkungan Sekolah SMK Silber melalui Dunia 3D.', 'activity', 'Gedung A', date('Y-m-d 08:00:00', strtotime('+1 day')), date('Y-m-d 11:30:00', strtotime('+1 day')), 150, 100, 50, 'published'],
            ['Lomba Desain Vektor Antar-Kelas', 'Kompetisi kreativitas desain logo dan poster digital menyambut Dies Natalis SMK Silber.', 'competition', 'Lab TIK 2', date('Y-m-d 13:00:00', strtotime('+3 days')), date('Y-m-d 16:00:00', strtotime('+3 days')), 50, 200, 100, 'published'],
            ['Mini CTF Siber Silber #1', 'Tantangan capture-the-flag seputar web security dasar dan network forensics.', 'competition', 'Lab TIK 1', date('Y-m-d 14:00:00', strtotime('+5 days')), date('Y-m-d 17:00:00', strtotime('+5 days')), 30, 250, 150, 'published'],
        ];
        $insEvt = $pdo->prepare('INSERT INTO events (title, description, event_type, map_location, start_time, end_time, quota, rewards_xp, rewards_coins, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime("now"))');
        foreach ($events as $ev) {
            $insEvt->execute($ev);
        }
    },
    '006_social_system' => static function (\PDO $pdo): void {
        // 1. User Profiles & Digital ID Card
        $pdo->exec('CREATE TABLE IF NOT EXISTS user_profiles (
            user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            bio TEXT NOT NULL DEFAULT "",
            status_text TEXT NOT NULL DEFAULT "Sedang belajar di Virtual City SMK Silber",
            avatar_color TEXT NOT NULL DEFAULT "#22d3ee",
            interests TEXT NOT NULL DEFAULT "Teknologi, Desain, Jaringan",
            card_theme TEXT NOT NULL DEFAULT "cyber_indigo",
            qr_token TEXT NOT NULL,
            privacy_level TEXT NOT NULL DEFAULT "public", -- public | friends | private
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

        // Initialize existing users with profile and unique QR token
        $users = $pdo->query('SELECT id, uid FROM users')->fetchAll(\PDO::FETCH_ASSOC);
        $insProf = $pdo->prepare('INSERT OR IGNORE INTO user_profiles (user_id, qr_token) VALUES (?, ?)');
        foreach ($users as $u) {
            $qrToken = 'SILBER-ID-' . bin2hex(random_bytes(8));
            $insProf->execute([(int)$u['id'], $qrToken]);
        }

        // 2. Friendships & Social Graph
        $pdo->exec('CREATE TABLE IF NOT EXISTS friendships (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            friend_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            status TEXT NOT NULL DEFAULT "pending", -- pending | accepted | blocked
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            accepted_at TEXT,
            UNIQUE(user_id, friend_id))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_fr_user ON friendships(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_fr_friend ON friendships(friend_id)');

        // 3. 24-Hour Stories
        $pdo->exec('CREATE TABLE IF NOT EXISTS stories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            content_text TEXT NOT NULL,
            background_color TEXT NOT NULL DEFAULT "#0d9488",
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_story_expires ON stories(expires_at)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS story_views (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            story_id INTEGER NOT NULL REFERENCES stories(id) ON DELETE CASCADE,
            viewer_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            viewed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(story_id, viewer_id))');

        // 4. Conversations & Direct Messages
        $pdo->exec('CREATE TABLE IF NOT EXISTS conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL DEFAULT "direct", -- direct | group | channel
            name TEXT NOT NULL DEFAULT "",
            description TEXT NOT NULL DEFAULT "",
            channel_tag TEXT,
            created_by INTEGER REFERENCES users(id),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS conversation_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            role TEXT NOT NULL DEFAULT "member", -- owner | admin | member
            joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(conversation_id, user_id))');

        $pdo->exec('CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
            sender_id INTEGER NOT NULL REFERENCES users(id),
            message_text TEXT NOT NULL,
            is_reported INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_msg_conv ON messages(conversation_id, created_at)');

        // Seed default school public channels
        $channels = [
            ['channel', '📢 Pengumuman Resmi', 'Informasi dan pengumuman resmi dari sekolah.', 'announcement'],
            ['channel', '💬 Obrolan Santai', 'Ruang bincang santai antarsiswa SMK Silber.', 'general'],
            ['channel', '💡 Tanya Jawab Pelajaran', 'Diskusi seputar soal, tugas, dan pemecahan masalah.', 'qna'],
        ];
        $insChan = $pdo->prepare('INSERT OR IGNORE INTO conversations (type, name, description, channel_tag) VALUES (?, ?, ?, ?)');
        foreach ($channels as $c) {
            $insChan->execute($c);
        }
    },
    '007_gameplay_progression' => static function (\PDO $pdo): void {
        // 1. XP and Coins Transaction Ledger (append-only)
        $pdo->exec('CREATE TABLE IF NOT EXISTS transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            type TEXT NOT NULL, -- xp | coins
            amount INTEGER NOT NULL,
            source_type TEXT NOT NULL, -- mission | quiz | event | login | shop | admin
            source_id TEXT NOT NULL DEFAULT "",
            description TEXT NOT NULL DEFAULT "",
            idempotency_key TEXT UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tx_user ON transactions(user_id, type)');

        // 2. User Progress Cache (balances & level)
        $pdo->exec('CREATE TABLE IF NOT EXISTS user_progress (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER UNIQUE NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            level INTEGER NOT NULL DEFAULT 1,
            total_xp INTEGER NOT NULL DEFAULT 0,
            coins INTEGER NOT NULL DEFAULT 0,
            login_streak INTEGER NOT NULL DEFAULT 1,
            last_login_date TEXT,
            titles TEXT NOT NULL DEFAULT "Siswa Baru",
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

        // Initialize progress for all existing users
        $users = $pdo->query('SELECT id FROM users')->fetchAll(\PDO::FETCH_ASSOC);
        $insProg = $pdo->prepare('INSERT OR IGNORE INTO user_progress (user_id, last_login_date) VALUES (?, date("now"))');
        foreach ($users as $u) {
            $insProg->execute([(int)$u['id']]);
        }

        // 3. Missions & Quests
        $pdo->exec('CREATE TABLE IF NOT EXISTS missions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            category TEXT NOT NULL DEFAULT "orientation", -- orientation | academic | exploration | social | daily
            target_type TEXT NOT NULL DEFAULT "all",
            target_value TEXT,
            prerequisite_mission_id INTEGER REFERENCES missions(id),
            xp_reward INTEGER NOT NULL DEFAULT 50,
            coin_reward INTEGER NOT NULL DEFAULT 20,
            badge_id INTEGER,
            is_daily INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            order_num INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS mission_progress (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            mission_id INTEGER NOT NULL REFERENCES missions(id) ON DELETE CASCADE,
            status TEXT NOT NULL DEFAULT "in_progress", -- in_progress | completed
            progress_data TEXT NOT NULL DEFAULT "{}",
            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT,
            UNIQUE(user_id, mission_id))');

        // 4. Badges & Achievements
        $pdo->exec('CREATE TABLE IF NOT EXISTS badges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            icon_emoji TEXT NOT NULL DEFAULT "🎖️",
            category TEXT NOT NULL DEFAULT "exploration",
            rarity TEXT NOT NULL DEFAULT "common", -- common | uncommon | rare | epic | legendary
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS user_badges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            badge_id INTEGER NOT NULL REFERENCES badges(id) ON DELETE CASCADE,
            earned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, badge_id))');

        // 5. Cosmetic Shop & Inventory
        $pdo->exec('CREATE TABLE IF NOT EXISTS shop_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            category TEXT NOT NULL DEFAULT "title", -- title | theme | bubble | avatar
            price_coins INTEGER NOT NULL DEFAULT 50,
            rarity TEXT NOT NULL DEFAULT "common",
            is_available INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS user_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            item_id INTEGER NOT NULL REFERENCES shop_items(id) ON DELETE CASCADE,
            equipped INTEGER NOT NULL DEFAULT 0,
            acquired_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, item_id))');

        // Seed initial school badges
        $badges = [
            ['Murid Baru', 'Memulai petualangan belajar di Virtual City SMK Silber.', '🌟', 'orientation', 'common'],
            ['Penjelajah Kampus', 'Mengunjungi setidaknya 5 gedung sekolah di dunia 3D.', '🗺️', 'exploration', 'common'],
            ['Kutu Buku', 'Mempelajari 3 materi dan mengumpulkan tugas tepat waktu.', '📚', 'academic', 'uncommon'],
            ['Otak Encer', 'Lulus kuis interaktif dengan nilai sempurna 100.', '🧠', 'academic', 'rare'],
            ['Kupu-Kupu Sosial', 'Bergabung ke salah satu organisasi resmi atau ekskul.', '👥', 'social', 'common'],
            ['Atlet Siber', 'Mencetak gol atau memenangkan pertandingan di lapangan futsal.', '⚽', 'exploration', 'uncommon'],
            ['Cyber Defender', 'Menyelesaikan simulasi keamanan jaringan dan cyber security.', '🛡️', 'special', 'rare'],
            ['Disiplin Penuh', 'Mempertahankan login streak selama 7 hari berturut-turut.', '🔥', 'special', 'epic'],
            ['Legenda Silber', 'Mencapai Level 10 ke atas di platform Virtual City.', '💎', 'special', 'legendary'],
            ['Sekolah Explorer', 'Menjelajahi seluruh 28 ruangan kampus fisik.', '🏫', 'exploration', 'legendary'],
        ];
        $insBadge = $pdo->prepare('INSERT OR IGNORE INTO badges (name, description, icon_emoji, category, rarity) VALUES (?, ?, ?, ?, ?)');
        foreach ($badges as $b) {
            $insBadge->execute($b);
        }

        // Seed initial missions
        $missions = [
            ['Tur Orientasi Kampus', 'Kunjungi Gedung A, Gedung B, dan Laboratorium TIK di dunia 3D.', 'orientation', 50, 25, 1],
            ['Tugas Perdana', 'Kumpulkan tugas pertama Anda di Ruang Kelas.', 'academic', 75, 30, 0],
            ['Kuis Pengetahuan', 'Selesaikan satu kuis mata pelajaran dengan nilai kelulusan.', 'academic', 100, 40, 4],
            ['Koneksi Sahabat', 'Kirim dan jalin pertemanan dengan rekan sekelas Anda.', 'social', 40, 20, 0],
            ['Gabung Ekskul', 'Daftarkan diri ke salah satu organisasi atau ekskul pilihan.', 'social', 60, 30, 5],
            ['Jelajah Lab Komputer', 'Kunjungi Lab TIK 1 dan Lab TIK 2 untuk melihat fasilitas jaringan.', 'exploration', 50, 25, 2],
            ['Presensi Agenda Sekolah', 'Ikuti dan lakukan presensi pada event atau kompetisi kampus.', 'exploration', 80, 50, 0],
            ['Koleksi Digital ID', 'Lengkapi profil kartu pelajar digital Anda.', 'orientation', 30, 15, 0],
        ];
        $insMiss = $pdo->prepare('INSERT OR IGNORE INTO missions (title, description, category, xp_reward, coin_reward, order_num) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($missions as $m) {
            $insMiss->execute($m);
        }

        // Seed cosmetic shop items
        $shop = [
            ['Gelar: Cyber Sentinel', 'Gelar kehormatan untuk siswa peminat keamanan siber.', 'title', 50, 'common'],
            ['Gelar: Master Akuntansi', 'Gelar bergengsi bagi ahli pembukuan dan keuangan.', 'title', 50, 'common'],
            ['Gelar: Kreator Digital', 'Gelar artistik untuk desainer grafis dan multimedia.', 'title', 50, 'common'],
            ['Tema ID Card: Cyber Neon', 'Gaya visual neon futuristik menyala untuk kartu pelajar.', 'theme', 100, 'rare'],
            ['Tema ID Card: Emas Prestasi', 'Bingkai emas mewah melambangkan siswa teladan.', 'theme', 150, 'epic'],
            ['Balon Obrolan: Terminal Gelap', 'Efek bubble chat bergaya command-line hacker.', 'bubble', 75, 'uncommon'],
        ];
        $insShop = $pdo->prepare('INSERT OR IGNORE INTO shop_items (name, description, category, price_coins, rarity) VALUES (?, ?, ?, ?, ?)');
        foreach ($shop as $s) {
            $insShop->execute($s);
        }
    },
    '008_notifications' => static function (\PDO $pdo): void {
        // In-app notification center with per-category preferences and quiet hours.
        $pdo->exec('CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            category TEXT NOT NULL DEFAULT "sistem", -- tugas | jadwal | event | chat | sosial | keamanan | sistem
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT "",
            link TEXT,
            dedupe_key TEXT,
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notif_user ON notifications(user_id, is_read, created_at)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_notif_dedupe ON notifications(user_id, dedupe_key) WHERE dedupe_key IS NOT NULL');

        $pdo->exec('CREATE TABLE IF NOT EXISTS notification_prefs (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            category TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY (user_id, category))');
    },
    '009_agent3_integration' => static function (\PDO $pdo): void {
        // === Telegram account linking (owned by Agent 3 — Integration & Release) ===
        // One-time, short-lived link codes. Only the SHA-256 hash is stored, never the code itself.
        $pdo->exec('CREATE TABLE IF NOT EXISTS telegram_link_codes (
            code_hash TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            expires_at INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used_at TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tglc_user ON telegram_link_codes(user_id)');

        // Verified link between a Telegram user id and an app account. Telegram username is NOT proof.
        $pdo->exec('CREATE TABLE IF NOT EXISTS telegram_links (
            tg_user_id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            tg_username TEXT,
            linked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TEXT)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tgl_user ON telegram_links(user_id)');

        // Duplicate-update guard so a replayed Telegram webhook/poll cannot run a command twice.
        $pdo->exec('CREATE TABLE IF NOT EXISTS telegram_updates (
            update_id INTEGER PRIMARY KEY,
            processed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

        // === AI Orchestrator job queue (persistent) ===
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_jobs (
            id TEXT PRIMARY KEY,
            role TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "queued",
            task TEXT NOT NULL,
            input TEXT,
            output TEXT,
            provider TEXT,
            model TEXT,
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 3,
            dedupe_key TEXT,
            requested_by INTEGER,
            error TEXT,
            tokens_used INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at TEXT,
            finished_at TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_aijobs_status ON ai_jobs(status, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_aijobs_role ON ai_jobs(role, created_at)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_aijobs_dedupe ON ai_jobs(dedupe_key) WHERE dedupe_key IS NOT NULL');

        // Append-only execution log per job. Never contains provider secrets.
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_job_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id TEXT NOT NULL,
            ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            level TEXT NOT NULL DEFAULT "info",
            message TEXT NOT NULL,
            meta TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_aijobevents_job ON ai_job_events(job_id, id)');
    },

    '010_agent1_character_and_economy' => static function (\PDO $pdo): void {
        // 1. Character presets master table
        $pdo->exec('CREATE TABLE IF NOT EXISTS character_presets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            category TEXT NOT NULL DEFAULT "character",
            base_gender TEXT NOT NULL DEFAULT "unisex",
            price_coins INTEGER NOT NULL DEFAULT 0,
            is_default INTEGER NOT NULL DEFAULT 0,
            unlock_req TEXT DEFAULT NULL,
            default_config TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_charpresets_slug ON character_presets(slug)');

        // 2. User unlocked characters
        $pdo->exec('CREATE TABLE IF NOT EXISTS user_characters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            preset_id INTEGER NOT NULL REFERENCES character_presets(id) ON DELETE CASCADE,
            acquired_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, preset_id)
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_userchars_user ON user_characters(user_id)');

        // 3. User profile avatar configuration columns
        $cols = $pdo->query('PRAGMA table_info(user_profiles)')->fetchAll(\PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');
        if (!in_array('active_avatar', $colNames, true)) {
            $pdo->exec('ALTER TABLE user_profiles ADD COLUMN active_avatar TEXT NOT NULL DEFAULT "student_casual"');
        }
        if (!in_array('avatar_config', $colNames, true)) {
            $pdo->exec('ALTER TABLE user_profiles ADD COLUMN avatar_config TEXT NOT NULL DEFAULT "{}"');
        }

        // 4. Active players multiplayer avatar sync column
        // Instalasi baru (Docker): tabel posisi pemain untuk fallback polling belum pernah dibuat.
        $pdo->exec('CREATE TABLE IF NOT EXISTS active_players (
            user_id INTEGER PRIMARY KEY,
            username TEXT,
            full_name TEXT,
            role TEXT,
            x REAL, y REAL, z REAL, floor INTEGER,
            updated_at INTEGER)');
        $apCols = $pdo->query('PRAGMA table_info(active_players)')->fetchAll(\PDO::FETCH_ASSOC);
        $apColNames = array_column($apCols, 'name');
        if (!in_array('avatar_config', $apColNames, true)) {
            $pdo->exec('ALTER TABLE active_players ADD COLUMN avatar_config TEXT');
        }

        // 5. Seed character presets
        $presets = [
            [
                'student_casual', 'Siswa Kasual SMK Silber', 'Karakter kasual santai untuk orientasi dan pembelajaran sehari-hari di kampus.',
                'character', 'unisex', 0, 1, 'level:1',
                json_encode([
                    'hair' => 'neat_short', 'hair_color' => '#262626',
                    'outfit' => 'casual', 'outfit_color' => '#0284c7',
                    'skin_tone' => '#ffd19a', 'shoes_color' => '#1e293b',
                    'accessory' => 'none'
                ])
            ],
            [
                'student_blazer', 'Seragam Blazer Resmi Silber', 'Setelan blazer seragam akademik elegan SMK Silber dengan dasi resmi.',
                'character', 'unisex', 0, 1, 'level:1',
                json_encode([
                    'hair' => 'undercut', 'hair_color' => '#382415',
                    'outfit' => 'blazer', 'outfit_color' => '#1e3a8a',
                    'skin_tone' => '#fcd5b5', 'shoes_color' => '#0f172a',
                    'accessory' => 'tie'
                ])
            ],
            [
                'cyber_tech_tkj', 'Cyber Security Specialist TKJ', 'Gaya futuristik anak TKJ dengan cyber visor bercahaya dan jaket taktis.',
                'character', 'unisex', 100, 0, 'level:2',
                json_encode([
                    'hair' => 'undercut', 'hair_color' => '#22d3ee',
                    'outfit' => 'cyber_hoodie', 'outfit_color' => '#090d16',
                    'skin_tone' => '#ffdfba', 'shoes_color' => '#06b6d4',
                    'accessory' => 'cyber_visor'
                ])
            ],
            [
                'rpl_coder', 'Fullstack Software Engineer RPL', 'Gaya modern coder jurusan RPL lengkap dengan headphone studio dan jaket tech.',
                'character', 'unisex', 120, 0, 'level:2',
                json_encode([
                    'hair' => 'neat_short', 'hair_color' => '#451a03',
                    'outfit' => 'tech_jacket', 'outfit_color' => '#3b82f6',
                    'skin_tone' => '#ffd19a', 'shoes_color' => '#1e293b',
                    'accessory' => 'headphones'
                ])
            ],
            [
                'dkv_creative', 'Digital Multimedia Designer DKV', 'Gaya visual kreatif anak DKV dengan aksen warna cerah dan kacamata stylish.',
                'character', 'unisex', 150, 0, 'level:3',
                json_encode([
                    'hair' => 'ponytail', 'hair_color' => '#ec4899',
                    'outfit' => 'creative_vest', 'outfit_color' => '#f59e0b',
                    'skin_tone' => '#ffe0bd', 'shoes_color' => '#ec4899',
                    'accessory' => 'glasses'
                ])
            ],
            [
                'silber_varsity', 'Jaket Varsity Atlet Kampus', 'Jaket varsity juara olahraga dan ekstrakurikuler kampus SMK Silber.',
                'character', 'unisex', 200, 0, 'level:4',
                json_encode([
                    'hair' => 'hijab', 'hair_color' => '#0284c7',
                    'outfit' => 'varsity', 'outfit_color' => '#059669',
                    'skin_tone' => '#ffd19a', 'shoes_color' => '#ffffff',
                    'accessory' => 'backpack'
                ])
            ]
        ];

        $insPreset = $pdo->prepare('INSERT OR IGNORE INTO character_presets
            (slug, name, description, category, base_gender, price_coins, is_default, unlock_req, default_config)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($presets as $p) {
            $insPreset->execute($p);
        }

        // 6. Seed cosmetic items in shop_items
        $shopCosmetics = [
            ['Karakter: Cyber Security TKJ', 'Karakter spesialis keamanan siber dengan visor neon dan jaket taktis.', 'character', 100, 'rare'],
            ['Karakter: Software Engineer RPL', 'Karakter programmer RPL dengan headphone studio profesional.', 'character', 120, 'rare'],
            ['Karakter: Multimedia Designer DKV', 'Karakter artistik jurusan DKV dengan aksen warna ceria.', 'character', 150, 'epic'],
            ['Karakter: Varsity Atlet Kampus', 'Jaket varsity eksklusif kejuaraan olahraga SMK Silber.', 'character', 200, 'epic'],
            ['Gaya Rambut: Undercut Modern', 'Potongan rambut undercut modern dengan tekstur rapi.', 'hair', 30, 'common'],
            ['Gaya Rambut: Hijab Cyber Modern', 'Model hijab anggun dan modis dengan aksen warna khas Silber.', 'hair', 30, 'common'],
            ['Gaya Rambut: Anime Ponytail', 'Kuncir rambut dinamis yang memantul anggun saat berjalan.', 'hair', 40, 'uncommon'],
            ['Aksesori: Kacamata Cyber Neon', 'Kacamata pintar tembus pandang dengan bingkai glowing neon.', 'accessory', 60, 'rare'],
            ['Aksesori: Headphone Gaming RGB', 'Headphone studio nirkabel melingkar dengan pencahayaan RGB.', 'accessory', 70, 'rare'],
            ['Aksesori: Ransel Taktis Sekolah', 'Ransel ergonomis tahan air untuk laptop dan perlengkapan lab.', 'accessory', 50, 'uncommon']
        ];
        $insShop = $pdo->prepare('INSERT OR IGNORE INTO shop_items (name, description, category, price_coins, rarity) VALUES (?, ?, ?, ?, ?)');
        foreach ($shopCosmetics as $sc) {
            $chk = $pdo->prepare('SELECT id FROM shop_items WHERE name = ?');
            $chk->execute([$sc[0]]);
            if (!$chk->fetchColumn()) {
                $insShop->execute($sc);
            }
        }

        // 7. Auto-grant default free characters to all active users
        $defaultPresetIds = $pdo->query('SELECT id FROM character_presets WHERE is_default = 1')->fetchAll(\PDO::FETCH_COLUMN);
        $allUserIds = $pdo->query('SELECT id FROM users')->fetchAll(\PDO::FETCH_COLUMN);
        $insUserChar = $pdo->prepare('INSERT OR IGNORE INTO user_characters (user_id, preset_id) VALUES (?, ?)');
        foreach ($allUserIds as $uid) {
            foreach ($defaultPresetIds as $pid) {
                $insUserChar->execute([$uid, $pid]);
            }
        }
    },
    '011_mapping_missions' => static function (\PDO $pdo): void {
        // Ajen 1 — misi & lencana dari dokumen mapping Virtual City v1.0 (bagian 4).
        // Misi terikat ke lokasi kampus via target_value; lokasi adalah metadata
        // routing/tautan di klien, sedangkan hadiah tetap diproses server-side
        // di Gameplay::completeMission (idempoten). INSERT OR IGNORE: dapat
        // dijalankan ulang tanpa efek ganda.
        $badges = [
            ['Lencana Literasi', 'Jawab 3 pertanyaan literasi dengan benar di Perpustakaan.', '📖', 'academic', 'uncommon'],
            ['Lencana Teknologi', 'Susun urutan perakitan komponen komputer dengan tepat.', '🔧', 'academic', 'uncommon'],
            ['Lencana Sportif', 'Selesaikan mini-game tantangan futsal.', '⚽', 'exploration', 'uncommon'],
            ['Lencana Kolaborasi', 'Jawab skenario kerja sama di Ruang IPM/OSIS.', '🤝', 'social', 'uncommon'],
            ['Lencana Karier', 'Cocokkan minat dengan jalur karier di Ruang BK/BKK.', '🧭', 'orientation', 'uncommon'],
        ];
        $insBadge = $pdo->prepare('INSERT OR IGNORE INTO badges (name, description, icon_emoji, category, rarity) VALUES (?, ?, ?, ?, ?)');
        foreach ($badges as $b) {
            $insBadge->execute($b);
        }
        $badgeIds = [];
        foreach ($pdo->query('SELECT id, name FROM badges')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $badgeIds[$row['name']] = (int)$row['id'];
        }
        // [title, description, category, target_type, target_value, badge_name, xp, coins, order]
        $mappingMissions = [
            ['Kenali Sekolah', 'Kunjungi area masuk (Gerbang Utama), lalu jelajahi minimal 5 lokasi berbeda di Virtual City.', 'orientation', 'location', 'gate-main', null, 100, 0, 21],
            ['Pemburu Informasi', 'Jawab 3 pertanyaan literasi di Perpustakaan untuk Lencana Literasi.', 'academic', 'location', 'PERPUS', 'Lencana Literasi', 0, 0, 22],
            ['Teknisi Muda', 'Selesaikan kuis perangkat komputer di Lab. TIK (Lantai 1).', 'exploration', 'location', 'LAB_TIK_1', null, 150, 0, 23],
            ['Rakit dengan Tepat', 'Susun urutan perakitan komponen komputer di Lab. Rakitan untuk Lencana Teknologi.', 'exploration', 'location', 'LAB_RAKITAN', 'Lencana Teknologi', 0, 0, 24],
            ['Transaksi Cerdas', 'Selesaikan simulasi transaksi dan transfer koin di Bank Mini (hadiah 100 koin).', 'exploration', 'location', 'BANK_MINI', null, 0, 100, 25],
            ['Belanja Hemat', 'Pilih barang sesuai anggaran di Mini Market.', 'exploration', 'location', 'MINI_MARKET', null, 100, 0, 26],
            ['Tantangan Futsal', 'Selesaikan mini-game target di Lapangan Futsal untuk Lencana Sportif.', 'exploration', 'location', 'LAP_FUTSAL', 'Lencana Sportif', 0, 0, 27],
            ['Ide Kreatif', 'Pilih solusi kreatif dari skenario di Ruang Seni & Studio DKV.', 'academic', 'location', 'RUANG_SENI', null, 100, 0, 28],
            ['Pemimpin Siswa', 'Jawab skenario kerja sama di Ruang Organisasi IPM/OSIS untuk Lencana Kolaborasi.', 'social', 'location', 'RUANG_IPM', 'Lencana Kolaborasi', 0, 0, 29],
            ['Urus Administrasi', 'Ikuti langkah layanan dokumen siswa yang benar di Ruang Tata Usaha.', 'orientation', 'location', 'RUANG_TU', null, 100, 0, 30],
            ['Rencana Masa Depan', 'Cocokkan minat dengan jalur karier di Ruang BK & BKK untuk Lencana Karier.', 'orientation', 'location', 'RUANG_BK', 'Lencana Karier', 0, 0, 31],
            ['Simulasi Industri', 'Selesaikan tugas proyek sederhana di Ruang Silber Industries.', 'academic', 'location', 'RUANG_INDUSTRI', null, 200, 0, 32],
        ];
        $insMappingMiss = $pdo->prepare('INSERT OR IGNORE INTO missions (title, description, category, target_type, target_value, badge_id, xp_reward, coin_reward, order_num) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($mappingMissions as $m) {
            $badgeId = ($m[5] !== null && isset($badgeIds[$m[5]])) ? $badgeIds[$m[5]] : null;
            $insMappingMiss->execute([$m[0], $m[1], $m[2], $m[3], $m[4], $badgeId, $m[6], $m[7], $m[8]]);
        }
    },
    '012_location_visits' => static function (\PDO $pdo): void {
        // Agen 1 — rekam kunjungan lokasi pemain. Landasan validasi server-side
        // untuk misi location-bound: hadiah misi hanya bisa diklaim bila tabel
        // ini mencatat kunjungan lokasi target (bukan klaim klien).
        $pdo->exec('CREATE TABLE IF NOT EXISTS location_visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            location_id TEXT NOT NULL,
            visits INTEGER NOT NULL DEFAULT 0,
            first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, location_id))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lv_user ON location_visits(user_id, location_id)');
    },
    '013_totp_auth' => static function (\PDO $pdo): void {
        // 2FA / TOTP support for ROOT and staff accounts
        $cols = [];
        $stmt = $pdo->query('PRAGMA table_info(users)');
        while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $cols[] = $r['name'];
        }
        if (!in_array('totp_secret', $cols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN totp_secret TEXT DEFAULT NULL');
        }
        if (!in_array('totp_enabled', $cols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN totp_enabled INTEGER DEFAULT 0');
        }
    },
    '014_web_push' => static function (\PDO $pdo): void {
        // Web Push notification subscriptions
        $pdo->exec('CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_push_user ON push_subscriptions(user_id)');
    },

    // 015 — Agent 1: chat multiplayer in-game (global/area/private). Pesan tersimpan
    // resmi di sini; WS hanyala transport relay yang memanggil jalur PHP ini.
    '015_game_chat' => static function (\PDO $pdo): void {
        $pdo->exec('CREATE TABLE IF NOT EXISTS game_chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scope TEXT NOT NULL,               -- global | area | private
            scope_key TEXT NOT NULL DEFAULT "",  -- lokasi internal utk area; "a-b" tervalidasi utk private
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            username TEXT NOT NULL DEFAULT "",
            role TEXT NOT NULL DEFAULT "murid",
            text TEXT NOT NULL,
            created_at TEXT NOT NULL,
            deleted_at TEXT,
            deleted_by INTEGER)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gchat_scope ON game_chat_messages(scope, scope_key, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gchat_user ON game_chat_messages(user_id, created_at)');

        // Pengaturan default chat di tabel settings resmi (satu sumber kebenaran).
        $def = [
            ['chat_enabled', '1'],
            ['chat_mode', 'global_area_private'],
            ['chat_cooldown', '2'],
            ['chat_filter', '1'],
            ['chat_blocklist', 'anjing, babi, goblok, bangsat, tolol, kontol, memek'],
        ];
        $ins = $pdo->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime("now"))
            ON CONFLICT(key) DO NOTHING');
        foreach ($def as [$k, $v]) { $ins->execute([$k, $v]); }
    },

    '016_game_servers' => static function (\PDO $pdo): void {
        $pdo->exec('CREATE TABLE IF NOT EXISTS game_servers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            type TEXT NOT NULL DEFAULT "public",   -- public | class
            class_id INTEGER REFERENCES classes(id) ON DELETE SET NULL,
            owner_id INTEGER NOT NULL REFERENCES users(id),
            capacity INTEGER NOT NULL DEFAULT 30,
            access TEXT NOT NULL DEFAULT "open",   -- open | invite
            invite_code TEXT UNIQUE,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gservers_type ON game_servers(type, is_active)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS game_server_members (
            server_id INTEGER NOT NULL REFERENCES game_servers(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (server_id, user_id))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gsmember_user ON game_server_members(user_id)');
        $hasPublic = $pdo->query('SELECT COUNT(*) FROM game_servers WHERE type = "public"')->fetchColumn();
        if (!$hasPublic) {
            $rootId = (int)$pdo->query('SELECT id FROM users WHERE role = "root" ORDER BY id LIMIT 1')->fetchColumn();
            if ($rootId > 0) {
                $ins = $pdo->prepare('INSERT INTO game_servers (name, description, type, owner_id, capacity, access)
                    VALUES ("Silber Public", "Server utama Virtual City — terbuka untuk seluruh siswa & staf.", "public", ?, 150, "open")');
                $ins->execute([$rootId]);
            }
        }
    },

    // Satu server = satu dunia (3D ATAU Pixel). Server lama tetap 3D; dunia pixel
    // mendapat server publik sendiri agar tetap bisa dimainkan tanpa kode.
    '017_server_world' => static function (\PDO $pdo): void {
        $cols = array_column($pdo->query('PRAGMA table_info(game_servers)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        if (!in_array('world', $cols, true)) {
            $pdo->exec('ALTER TABLE game_servers ADD COLUMN world TEXT NOT NULL DEFAULT "3d" CHECK (world IN ("3d", "pixel"))');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gservers_world ON game_servers(world, is_active)');
        $hasPixelPublic = $pdo->query('SELECT COUNT(*) FROM game_servers WHERE type = "public" AND world = "pixel"')->fetchColumn();
        if (!$hasPixelPublic) {
            $rootId = (int)$pdo->query('SELECT id FROM users WHERE role = "root" ORDER BY id LIMIT 1')->fetchColumn();
            if ($rootId > 0) {
                $pdo->prepare('INSERT INTO game_servers (name, description, type, owner_id, capacity, access, world)
                    VALUES ("Silber Public Pixel", "Server utama Dunia Pixel — terbuka untuk seluruh siswa & staf.", "public", ?, 150, "open", "pixel")')
                    ->execute([$rootId]);
            }
        }
    },

    // Tugas bisa dikirim guru ke server/room game: semua anggota server menerimanya
    // (selain murid kelas sasaran). NULL = tugas kelas biasa.
    '018_assignment_server' => static function (\PDO $pdo): void {
        $cols = array_column($pdo->query('PRAGMA table_info(assignments)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        if (!in_array('server_id', $cols, true)) {
            $pdo->exec('ALTER TABLE assignments ADD COLUMN server_id INTEGER REFERENCES game_servers(id) ON DELETE SET NULL');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_assignments_server ON assignments(server_id)');
    },

    // Broadcast pop-up dari konsol admin ke dashboard (Domain\Broadcast).
    '019_broadcasts' => static function (\PDO $pdo): void {
        $pdo->exec('CREATE TABLE IF NOT EXISTS broadcasts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            link_url TEXT NOT NULL DEFAULT "",
            link_label TEXT NOT NULL DEFAULT "",
            audience TEXT NOT NULL DEFAULT "all",
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT)');
    },

    // Sesi 15 Okt 2026 (c): hapus chat (soft-delete, ROOT/admin tetap bisa lihat), soal tugas
    // (pilihan ganda + essay), mabar/party dashboard, jadwal kumpul per server.
    '020_chat_delete_quiz_party_gather' => static function (\PDO $pdo): void {
        $cols = static function (string $t) use ($pdo): array {
            return array_column($pdo->query('PRAGMA table_info(' . $t . ')')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        };
        if (!in_array('deleted_at', $cols('messages'), true)) {
            $pdo->exec('ALTER TABLE messages ADD COLUMN deleted_at TEXT');
            $pdo->exec('ALTER TABLE messages ADD COLUMN deleted_by INTEGER');
        }
        if (!in_array('questions', $cols('assignments'), true)) {
            $pdo->exec('ALTER TABLE assignments ADD COLUMN questions TEXT');
        }
        if (!in_array('answers', $cols('submissions'), true)) {
            $pdo->exec('ALTER TABLE submissions ADD COLUMN answers TEXT');
            $pdo->exec('ALTER TABLE submissions ADD COLUMN auto_score INTEGER');
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS parties (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            captain_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name TEXT NOT NULL DEFAULT "",
            dest_url TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS party_members (
            party_id INTEGER NOT NULL REFERENCES parties(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
            joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (party_id, user_id))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS party_invites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            party_id INTEGER NOT NULL REFERENCES parties(id) ON DELETE CASCADE,
            from_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            to_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(party_id, to_id))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS server_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            server_id INTEGER NOT NULL REFERENCES game_servers(id) ON DELETE CASCADE,
            title TEXT NOT NULL,
            room_id TEXT NOT NULL,
            day_of_week INTEGER,
            on_date TEXT,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS server_session_attendance (
            session_id INTEGER NOT NULL REFERENCES server_sessions(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            on_date TEXT NOT NULL,
            arrived_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (session_id, user_id, on_date))');
    },
    '021_presence_party_public_quest' => static function (\PDO $pdo): void {
        // Status online dari database (diperbarui tiap request login, maks. 1×/menit per sesi).
        $pdo->exec('CREATE TABLE IF NOT EXISTS user_presence (
            user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            last_seen TEXT NOT NULL,
            place TEXT NOT NULL DEFAULT "")');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_presence_seen ON user_presence(last_seen)');
        $cols = array_column($pdo->query('PRAGMA table_info(parties)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        if (!in_array('visibility', $cols, true)) {
            $pdo->exec('ALTER TABLE parties ADD COLUMN visibility TEXT NOT NULL DEFAULT "code"'); // code | public
            $pdo->exec('ALTER TABLE parties ADD COLUMN quest TEXT NOT NULL DEFAULT ""');
            $pdo->exec('ALTER TABLE parties ADD COLUMN quest_started TEXT');
        }
        // Progres misi mabar: satu baris per (party, event unik) agar tidak bisa digandakan.
        $pdo->exec('CREATE TABLE IF NOT EXISTS party_quest_log (
            party_id INTEGER NOT NULL REFERENCES parties(id) ON DELETE CASCADE,
            quest TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            ref TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (party_id, quest, user_id, ref))');
    },
    '022_gacha_spins' => static function (\PDO $pdo): void {
        // Gacha harian: 1× gratis per 24 jam, selanjutnya bayar koin (Domain/Gacha.php).
        $pdo->exec('CREATE TABLE IF NOT EXISTS gacha_spins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            kind TEXT NOT NULL,
            prize_type TEXT NOT NULL DEFAULT "",
            prize_value INTEGER NOT NULL DEFAULT 0,
            prize_label TEXT NOT NULL DEFAULT "",
            rarity TEXT NOT NULL DEFAULT "common",
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gacha_user ON gacha_spins(user_id, kind, created_at)');
    },
    '023_profile_photo' => static function (\PDO $pdo): void {
        // Foto profil (Domain/ProfilePhoto.php): path /assets/avatars/p_<hex>.jpg|png|webp
        $cols = array_column($pdo->query('PRAGMA table_info(user_profiles)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        if (!in_array('photo', $cols, true)) {
            $pdo->exec('ALTER TABLE user_profiles ADD COLUMN photo TEXT NOT NULL DEFAULT ""');
        }
    },
    '024_daily_quests' => static function (\PDO $pdo): void {
        // Misi harian (Domain/DailyQuest.php): progres unik per (user, hari, misi, ref) + klaim hadiah.
        $pdo->exec('CREATE TABLE IF NOT EXISTS daily_quest_log (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            day TEXT NOT NULL, quest TEXT NOT NULL, ref TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, day, quest, ref))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS daily_quest_claims (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            day TEXT NOT NULL, quest TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, day, quest))');
    },
    '025_inventory_notes' => static function (\PDO $pdo): void {
        // Inventaris: isi Buku Catatan (halaman 1–5) & sketsa Pensil per pengguna (Domain/Inventory.php)
        $pdo->exec('CREATE TABLE IF NOT EXISTS user_notes (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            page TEXT NOT NULL, body TEXT NOT NULL DEFAULT "",
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, page))');
    },
];

foreach ($migrations as $name => $fn) {
    $exists = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE name = ?');
    $exists->execute([$name]);
    if ($exists->fetchColumn()) {
        echo "skip  $name (already applied)\n";
        continue;
    }
    $pdo->beginTransaction();
    try {
        $fn($pdo);
        $pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, datetime("now"))')
            ->execute([$name]);
        $pdo->commit();
        echo "done  $name\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "FAILED $name: {$e->getMessage()}\n");
        exit(1);
    }
}
echo "migrations complete\n";

<?php
// AiClient: Multi-provider AI Engine for Virtual City SMK Silber Mascot & Companion NPC.
// Supports automatic failover across Groq, Mistral, OpenRouter, and customizable fallbacks.
declare(strict_types=1);

namespace VC\Ai;

final class AiClient
{
    private const SYSTEM_PROMPT = "Kamu adalah 'Si Silber', robot maskot cerdas dan ramah dari SMK Silber (Sekolah Menengah Kejuruan Cyber & Vokasi Terpadu). Kamu mendampingi siswa, guru, dan pengunjung di dalam Virtual City 3D. Karaktermu: cerdas, suportif, bersemangat, sedikit jenaka, dan fasih menjelaskan jurusan (TJKT, AKL, BD, DKV), jadwal pelajaran, misi sekolah, serta teknologi masa depan. Jawab dalam Bahasa Indonesia yang santun, ringkas, dan jelas.\n\nKEMAMPUANMU (sebutkan bila ditanya 'apa yang bisa kamu bantu?'): (1) memberi PETUNJUK misi langkah demi langkah sampai misi selesai, (2) menjelaskan DENAH & lokasi ruangan/lantai sekolah, (3) membantu TUGAS, QUIZ dan jadwal pelajaran, (4) menjelaskan fitur: Dunia 3D, Peta, Misi, Toko, Karakter, Chat, Papan Peringkat, Event, (5) tips bermain (gerak, sprint, lompat, emote, multiplayer bareng teman).\n\nATURAN MISI: bila user minta diselesaikan atau minta petunjuk, berikan langkah konkret (lokasi, lantai, tombol apa yang ditekan, jawaban bila tipe quiz) dan tutup dengan ajakan menekan tombol misi di /misi atau tombol [E] di dekat lokasi. Selalu sebut lokasi + lantai.";

    /**
     * Generate response from the best available AI provider.
     */
    public static function ask(string $prompt, ?string $context = null): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['ok' => false, 'message' => 'Pertanyaan tidak boleh kosong.'];
        }

        $system = self::SYSTEM_PROMPT;
        if ($context) {
            $system .= "\nKonteks lokasi/aktivitas pemain saat ini: " . $context;
        }

        $providers = [
            'groq' => [
                'name' => 'Groq (Ultra-Fast Llama/Qwen)',
                'key' => \vc_env('GROQ_API_KEY'),
                'url' => 'https://api.groq.com/openai/v1/chat/completions',
                'model' => 'qwen/qwen3.8-27b',
                'max_tokens' => 300,
            ],
            'mistral' => [
                'name' => 'Mistral AI',
                'key' => \vc_env('MISTRAL_API_KEY'),
                'url' => 'https://api.mistral.ai/v1/chat/completions',
                'model' => 'mistral-tiny',
                'max_tokens' => 300,
            ],
            'openrouter' => [
                'name' => 'OpenRouter',
                'key' => \vc_env('OPENROUTER_API_KEY'),
                'url' => 'https://openrouter.ai/api/v1/chat/completions',
                'model' => 'meta-llama/llama-3.2-3b-instruct',
                'max_tokens' => 250,
            ]
        ];

        foreach ($providers as $id => $cfg) {
            if (empty($cfg['key'])) {
                continue;
            }

            $reply = self::callChatCompletions($cfg['url'], $cfg['key'], $cfg['model'], $system, $prompt, $cfg['max_tokens']);
            if ($reply !== null) {
                return [
                    'ok' => true,
                    'provider' => $id,
                    'provider_name' => $cfg['name'],
                    'model' => $cfg['model'],
                    'reply' => $reply
                ];
            }
        }

        // Fallback procedural smart responder if all remote endpoints are offline or rate-limited
        return [
            'ok' => true,
            'provider' => 'silber_core_fallback',
            'provider_name' => 'Si Silber Brain Core',
            'model' => 'rule-based-v5',
            'reply' => self::proceduralFallback($prompt)
        ];
    }

    private static function callChatCompletions(string $url, string $key, string $model, string $system, string $userMsg, int $maxTokens): ?string
    {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userMsg]
            ],
            'max_tokens' => $maxTokens,
            'temperature' => 0.7
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
                'User-Agent: VirtualCity-SMKSilber/5.0'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 7,
            CURLOPT_CONNECTTIMEOUT => 4
        ]);

        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $code !== 200) {
            return null;
        }

        $data = json_decode((string)$res, true);
        $text = $data['choices'][0]['message']['content'] ?? null;
        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }

    private static function proceduralFallback(string $prompt): string
    {
        $p = strtolower($prompt);
        if (str_contains($p, 'bantu') || str_contains($p, 'bisa kamu') || str_contains($p, 'fitur')) {
            return "Aku bisa bantu 5 hal: \n1️⃣ Petunjuk & penyelesaian MISI (quiz, puzzle, checkpoint, refleksi)\n2️⃣ Cari ruangan di DENAH (gedung, lantai, lab)\n3️⃣ TUGAS, QUIZ & jadwal pelajaran\n4️⃣ Fitur bermain: Dunia 3D, Peta, Toko, Karakter, Chat, Papan Peringkat\n5️⃣ Tips: WASD/joystick untuk jalan, Shift lari, Spasi lompat, E interaksi\nTulis aja mau dibantu yang mana 🤖";
        }
        if (str_contains($p, 'misi') || str_contains($p, 'quest') || str_contains($p, 'selesaikan')) {
            return "Cara menyelesaikan misi: 1) Buka menu 🎯 Misi (atau /misi) lalu tekan 'Mulai'. 2) Pergi ke lokasi misi (lihat lantai & gedung di denah). 3) Dekati titik kuning lalu tekan E (HP: ketuk tombol interaksi). 4) Kerjakan quiz/puzzle/refleksinya. 5) Hadiah XP, koin & lencana otomatis masuk. Mau aku jelaskan misi tertentu? Sebutkan judulnya.";
        }
        if (str_contains($p, 'denah') || str_contains($p, 'peta') || str_contains($p, 'ruang') || str_contains($p, 'gedung') || str_contains($p, 'lokasi')) {
            return "Buka menu 🗺️ Peta untuk denah asli & peta interaktif. Gedung A (administrasi & kejuruan), Gedung B (kelas + Lab TIK), Gedung C (kelas + perpustakaan), Gedung D (barat). Lab Rakitan & Lab TIK 2 ada di lantai 2 (tombol LT 2). Bank Mini, Mini Market, dan Lapangan Futsal di lantai 1.";
        }
        if (str_contains($p, 'jadwal') || str_contains($p, 'pelajaran')) {
            return "Kamu bisa melihat jadwal pelajaran hari ini langsung di menu 📅 Jadwal, termasuk ruang & guru yang mengajar.";
        }
        if (str_contains($p, 'dunia') || str_contains($p, '3d') || str_contains($p, 'jalan')) {
            return "Di Dunia 3D, gunakan tombol WASD atau joystick di HP untuk jalan, tombol shift untuk lari, dan spasi untuk melompat!";
        }
        if (str_contains($p, 'koin') || str_contains($p, 'xp') || str_contains($p, 'level')) {
            return "Kerjakan tugas di kelas dan selesaikan misi harian untuk mengumpulkan XP dan Koin, lalu tukarkan di Toko Avatar!";
        }
        if (str_contains($p, 'siang') || str_contains($p, 'malam')) {
            return "Kamu bisa menekan tombol matahari/bulan di HUD atas Dunia 3D untuk beralih antara suasana siang dan malam!";
        }
        if (str_contains($p, 'teman') || str_contains($p, 'bareng') || str_contains($p, 'multiplayer') || str_contains($p, 'chat')) {
            return "Semua murid masuk ke SATU server: buka Dunia 3D bareng teman, mereka kelihatan sebagai avatar, bisa diajak chat (💬 Chat) dan suara. Jalan bareng, kerja bareng! 👥";
        }
        return "Halo! Aku Si Silber, maskot pendampingmu. Aku bisa kasih petunjuk misi, bantu selesaikan tugas, jelaskan denah sekolah, jadwal, dan fitur bermain. Mau mulai dari mana? 🤖";
    }
}

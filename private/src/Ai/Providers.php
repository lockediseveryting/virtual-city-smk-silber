<?php
// Providers: registry of AI providers actually used by the orchestrator.
// Each of the three agent roles is pinned to a distinct provider + model so the
// agents produce genuinely different work (not three personas of the same model).
// Provider/model can be overridden per role from private/config/ai.env; nothing
// is swapped silently at runtime — a missing key marks the role NOT CONFIGURED.
declare(strict_types=1);

namespace VC\Ai;

final class Providers
{
    /** Known OpenAI-compatible chat-completions providers. */
    private const CATALOG = [
        'groq'       => ['name' => 'Groq',        'url' => 'https://api.groq.com/openai/v1/chat/completions',      'key' => 'GROQ_API_KEY'],
        'nvidia'     => ['name' => 'NVIDIA NIM',  'url' => 'https://integrate.api.nvidia.com/v1/chat/completions', 'key' => 'NVIDIA_API_KEY'],
        'openrouter' => ['name' => 'OpenRouter',  'url' => 'https://openrouter.ai/api/v1/chat/completions',         'key' => 'OPENROUTER_API_KEY'],
        'mistral'    => ['name' => 'Mistral AI',  'url' => 'https://api.mistral.ai/v1/chat/completions',            'key' => 'MISTRAL_API_KEY'],
        // Verified reachable 2026-10-12 (HTTP 200, "PONG") — available as an override
        // candidate only; no role is switched to it automatically.
        'zai'        => ['name' => 'Z.ai GLM',     'url' => 'https://api.z.ai/api/paas/v4/chat/completions',        'key' => 'ZAI_API_KEY'],
    ];

    /**
     * Role -> provider + model. These three roles are the real, distinct agents:
     *   engineering          = writes a reviewable patch/plan
     *   security_qa          = independently audits that patch, returns a verdict
     *   integration_release  = only merges work that passed QA, writes release notes
     */
    public const ROLES = [
        'engineering'         => ['provider' => 'groq',       'model' => 'qwen/qwen3.8-27b'],
        'security_qa'         => ['provider' => 'nvidia',     'model' => 'meta/llama-3.2-11b-vision-instruct'],
        'integration_release' => ['provider' => 'openrouter', 'model' => 'meta-llama/llama-3.2-3b-instruct'],
    ];

    /** Distinct, role-specific system prompts. */
    private const PROMPTS = [
        'engineering' =>
            "Kamu adalah AI-1 ENGINEERING pada proyek Virtual City SMK Silber. Tugasmu menyusun patch kode "
            . "yang konkret dan bisa direview, berdasarkan task yang sudah divalidasi. Keluarkan rencana teknis ringkas: "
            . "berkas yang diubah, perubahan inti (potongan kode/diff), alasannya, dan cara mengujinya. "
            . "Jangan pernah menjalankan perintah shell sendiri. Jangan mengarang berkas yang tidak ada. "
            . "Bahasa Indonesia, ringkas, terstruktur.",
        'security_qa' =>
            "Kamu adalah AI-2 SECURITY & QA pada proyek Virtual City SMK Silber. Tugasmu mengaudit patch dari AI-1 secara "
            . "independen dan adversarial. Cari celah keamanan (injeksi, otorisasi, kebocoran secret/data pribadi), bug logika, "
            . "dan risiko regresi. Jangan menerima klaim tanpa bukti. Akhiri dengan baris verdict persis salah satu dari: "
            . "'VERDICT: PASS' atau 'VERDICT: FAIL' beserta alasannya. Bahasa Indonesia.",
        'integration_release' =>
            "Kamu adalah AI-3 INTEGRATION & RELEASE pada proyek Virtual City SMK Silber. Kamu HANYA mengintegrasikan perubahan "
            . "yang sudah lolos audit AI-2. Susun langkah integrasi, urutan deploy, daftar berkas yang berubah, dan langkah rollback. "
            . "Jika input tidak memuat verdict PASS dari AI-2, TOLAK integrasi dan tulis 'INTEGRATION: HOLD'. Bahasa Indonesia.",
    ];

    public static function isValidRole(string $role): bool
    {
        return isset(self::ROLES[$role]);
    }

    public static function roleNames(): array
    {
        return array_keys(self::ROLES);
    }

    public static function roleLabel(string $role): string
    {
        return [
            'engineering' => 'AI-1 Engineering',
            'security_qa' => 'AI-2 Security & QA',
            'integration_release' => 'AI-3 Integration & Release',
        ][$role] ?? $role;
    }

    public static function systemPrompt(string $role): string
    {
        return self::PROMPTS[$role] ?? 'Kamu adalah asisten teknis.';
    }

    /** Provider id + model for a role, honouring optional ai.env overrides. */
    public static function configFor(string $role): array
    {
        $base = self::ROLES[$role] ?? ['provider' => '', 'model' => ''];
        $envPrefix = 'AI_' . strtoupper($role);
        $provider = \vc_env($envPrefix . '_PROVIDER', $base['provider']) ?: $base['provider'];
        $model    = \vc_env($envPrefix . '_MODEL', $base['model']) ?: $base['model'];
        return ['provider' => $provider, 'model' => $model];
    }

    public static function providerName(string $provider): string
    {
        return self::CATALOG[$provider]['name'] ?? $provider;
    }

    /** Is the configured provider for this role present and keyed? */
    public static function status(string $role): array
    {
        $cfg = self::configFor($role);
        $p = $cfg['provider'];
        if (!isset(self::CATALOG[$p])) {
            return ['role' => $role, 'configured' => false, 'state' => 'NOT CONFIGURED',
                    'provider' => $p, 'model' => $cfg['model'], 'reason' => 'provider tidak dikenal'];
        }
        $key = \vc_env(self::CATALOG[$p]['key'], '');
        if ($key === '' || $key === null) {
            return ['role' => $role, 'configured' => false, 'state' => 'NOT CONFIGURED',
                    'provider' => $p, 'model' => $cfg['model'], 'reason' => 'API key kosong'];
        }
        return ['role' => $role, 'configured' => true, 'state' => 'CONFIGURED',
                'provider' => $p, 'provider_name' => self::CATALOG[$p]['name'],
                'model' => $cfg['model'], 'reason' => ''];
    }

    /** Status of all roles — used by the dashboard and docs. */
    public static function allStatus(): array
    {
        $out = [];
        foreach (array_keys(self::ROLES) as $role) {
            $out[$role] = self::status($role);
        }
        return $out;
    }

    /**
     * Single chat-completions call. Never throws. Returns a normalised result with
     * no secret material. Rate-limit / 5xx are flagged so the caller can retry.
     */
    public static function chat(string $role, string $userMessage, int $maxTokens = 700): array
    {
        $cfg = self::configFor($role);
        $provider = $cfg['provider'];
        if (!isset(self::CATALOG[$provider])) {
            return ['ok' => false, 'status' => 0, 'error' => 'provider tidak dikenal', 'retryable' => false, 'tokens' => 0];
        }
        $meta = self::CATALOG[$provider];
        $key = (string)\vc_env($meta['key'], '');
        if ($key === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'API key kosong', 'retryable' => false, 'tokens' => 0];
        }

        $payload = [
            'model' => $cfg['model'],
            'messages' => [
                ['role' => 'system', 'content' => self::systemPrompt($role)],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => 0.4,
        ];

        $ch = curl_init($meta['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
                'User-Agent: VirtualCity-SMKSilber/Orchestrator',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => (int)\vc_env('AI_REQUEST_TIMEOUT', '25'),
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            return ['ok' => false, 'status' => 0, 'error' => 'koneksi gagal: ' . substr($err, 0, 120),
                    'retryable' => true, 'tokens' => 0, 'provider' => $provider, 'model' => $cfg['model']];
        }
        $data = json_decode((string)$res, true);
        $text = $data['choices'][0]['message']['content'] ?? null;

        if ($code !== 200 || !is_string($text) || trim($text) === '') {
            $retryable = ($code === 429 || $code >= 500);
            $msg = $data['error']['message'] ?? ('HTTP ' . $code);
            return ['ok' => false, 'status' => $code, 'error' => substr((string)$msg, 0, 200),
                    'retryable' => $retryable, 'tokens' => 0, 'provider' => $provider, 'model' => $cfg['model']];
        }

        return [
            'ok' => true,
            'status' => 200,
            'text' => trim($text),
            'tokens' => (int)($data['usage']['total_tokens'] ?? 0),
            'provider' => $provider,
            'model' => $cfg['model'],
        ];
    }
}

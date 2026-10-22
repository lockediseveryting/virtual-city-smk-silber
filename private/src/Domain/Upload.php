<?php
// Secure file uploads: extension + real-MIME whitelist, size cap, random names,
// stored OUTSIDE the web root and served only through an authorized endpoint.
declare(strict_types=1);

namespace VC\Domain;

final class Upload
{
    /** Allowed real MIME types per purpose. */
    private const ALLOWED = [
        'submission' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'application/zip',
                         'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                         'text/plain'],
        'material'   => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp',
                         'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'avatar'     => ['image/jpeg', 'image/png', 'image/webp'],
        'story'      => ['image/jpeg', 'image/png', 'image/webp'],
    ];

    private const MAX_BYTES = 5_242_880; // 5 MB

    /**
     * Validate and store an uploaded file.
     * @return array{ok:bool,error?:string,path?:string,orig?:string,size?:int}
     */
    public static function store(array $file, string $purpose): array
    {
        $allowed = self::ALLOWED[$purpose] ?? null;
        if ($allowed === null) {
            return ['ok' => false, 'error' => 'Tujuan unggah tidak dikenal.'];
        }
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'Berkas tidak ditemukan.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Unggahan gagal (kode ' . (int)$file['error'] . ').'];
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'Ukuran berkas maksimal 5 MB.'];
        }

        // Trust the file's real content type, never the client-supplied name.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowed, true)) {
            return ['ok' => false, 'error' => 'Tipe berkas tidak diizinkan (' . $mime . ').'];
        }

        $dir = VC_PRIVATE . '/uploads/' . $purpose;
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Folder unggahan tidak bisa dibuat.'];
        }

        $ext = self::extFor($mime);
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'error' => 'Gagal menyimpan berkas.'];
        }
        @chmod($dest, 0640);

        return [
            'ok' => true,
            'path' => $purpose . '/' . $name,
            'orig' => self::safeName((string)($file['name'] ?? 'berkas')),
            'size' => $size,
        ];
    }

    /** Resolve a stored relative path to an absolute path (defends against traversal). */
    public static function resolve(string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            return null;
        }
        $base = realpath(VC_PRIVATE . '/uploads');
        $full = realpath(VC_PRIVATE . '/uploads/' . $relative);
        if ($base === false || $full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return is_file($full) ? $full : null;
    }

    public static function mimeOf(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return (string)$finfo->file($path);
    }

    private static function extFor(string $mime): string
    {
        return [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/zip' => 'zip',
            'text/plain' => 'txt',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        ][$mime] ?? 'bin';
    }

    private static function safeName(string $name): string
    {
        $name = basename($name);
        return mb_substr(preg_replace('/[^\w.\- ]/u', '_', $name) ?? 'berkas', 0, 120);
    }
}

<?php

declare(strict_types=1);

namespace Aicountly\Api\Import;

use Aicountly\Api\Http;

/**
 * The uploaded file, verified, or a refusal.
 *
 * Lifted out of ImportController when a second endpoint needed the same three
 * guarantees, because two copies of upload handling is how one of them quietly
 * stops checking `is_uploaded_file`.
 *
 * THE FILE IS UNTRUSTED. Its type comes from its first bytes rather than its
 * name (DocumentReader does that); the name is used for display only and never
 * to build a path; and under a web SAPI the path must be one PHP itself wrote.
 */
final class Upload
{
    /**
     * @param string $field the multipart field name
     * @return array{0: string, 1: string, 2: callable(): void} path, display name, cleanup
     */
    public static function file(string $field = 'file'): array
    {
        $file = $_FILES[$field] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Http::validationFailed(self::error(is_array($file) ? (int) ($file['error'] ?? 0) : UPLOAD_ERR_NO_FILE), ['field' => $field]);
        }

        $path = (string) ($file['tmp_name'] ?? '');
        // Under a web SAPI this is the guarantee that the path is one PHP wrote
        // and not one a caller named.
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($path)) {
            Http::validationFailed('That upload could not be verified.', ['field' => $field]);
        }

        // The name is used for DISPLAY ONLY — never to decide the format, never
        // to build a path. basename() strips any directory a caller put in it.
        $name = basename((string) ($file['name'] ?? 'upload'));
        $name = (string) preg_replace('/[^\w.\- ]/u', '', $name);

        return [$path, $name === '' ? 'upload' : $name, static function () use ($path): void {
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }];
    }

    public static function error(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than this server accepts. Export a narrower date range.',
            UPLOAD_ERR_PARTIAL => 'The upload did not finish. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'This server could not store the upload while reading it. This is a server fault, not a problem with your file.',
            default => 'That file could not be uploaded.',
        };
    }
}

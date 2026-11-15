<?php

namespace ChurchCRM\Plugins\MosGov\Security;

/**
 * Upload validation for per-church private storage (Phase 3, SEC-06).
 *
 * MOS-GOV V2.0 has NO upload endpoint of its own; ChurchCRM core owns photo
 * and attachment uploads. The deployment therefore keeps uploads OUTSIDE the
 * web root, denies direct HTTP access, and registers every file in
 * gov_tenant_file. This class is the shared validation used by the deployment
 * download endpoint and by the lab test suite: no user-supplied filename ever
 * becomes a filesystem path, and executable content is not accepted.
 */
final class TenantFilePolicy
{
    /** Conservative document/image allow-list; no script or archive types. */
    public const ALLOWED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'csv', 'docx', 'xlsx'];

    public const ALLOWED_MIME = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'text/plain',
        'text/csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * @return array{ok:bool, reason:string, extension:string}
     */
    public static function validate(string $originalName, int $byteSize, string $mimeType): array
    {
        $name = basename(str_replace('\\', '/', $originalName));
        if ($name === '' || str_contains($name, "\0") || preg_match('/[\x00-\x1F\/\\\\]/', $name) === 1) {
            return ['ok' => false, 'reason' => 'invalid filename', 'extension' => ''];
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return ['ok' => false, 'reason' => 'file type not allowed', 'extension' => $extension];
        }
        if ($byteSize <= 0 || $byteSize > self::MAX_BYTES) {
            return ['ok' => false, 'reason' => 'file size out of range', 'extension' => $extension];
        }
        if (!in_array(strtolower($mimeType), self::ALLOWED_MIME, true)) {
            return ['ok' => false, 'reason' => 'mime type not allowed', 'extension' => $extension];
        }

        return ['ok' => true, 'reason' => 'accepted', 'extension' => $extension];
    }

    /** Random, non-guessable object key; never derived from the user filename. */
    public static function objectKey(string $tenantId, string $extension): string
    {
        return $tenantId . '/' . gmdate('Y/m/d') . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
    }
}

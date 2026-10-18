<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Core\Storage\MalwareScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Decides whether a file may enter the repository, and what it actually is.
 *
 * An upload endpoint is the most attackable thing in a document module, and almost every way it
 * goes wrong comes from believing the browser. The filename, the extension and the Content-Type all
 * arrive from the client and none of them are evidence. So the type is read from the bytes, the
 * stored name is generated rather than accepted, and a file whose contents disagree with its
 * extension is refused rather than quietly renamed.
 *
 * The rules that follow are the ones that matter in practice:
 *
 *   A file is what its bytes say it is. `finfo` reads the magic number; the declared type is kept
 *   only so a mismatch can be explained to whoever uploaded it.
 *
 *   Anything a browser will execute is never allowed, whatever the tenant configures. HTML and SVG
 *   are the ones people forget: both carry script, and a document served from the tenant's own
 *   domain is same-origin with the application. That is stored cross-site scripting with a file
 *   picker attached, and no legitimate document repository needs to accept either.
 *
 *   The extension list is an allow-list. A deny-list is a promise to have thought of everything.
 */
class FileTypeGuard
{
    /**
     * Refused regardless of settings.
     *
     * Server-executable, browser-executable, or a shell. An administrator cannot switch these on,
     * because the reason to want them is never a document-management reason.
     */
    private const NEVER = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'inc',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'xsl', 'xslt',
        'js', 'mjs', 'cjs', 'jsp', 'asp', 'aspx', 'cshtml', 'cfm', 'pl', 'py', 'rb', 'cgi',
        'exe', 'dll', 'so', 'dylib', 'bat', 'cmd', 'com', 'msi', 'scr', 'hta', 'cpl',
        'sh', 'bash', 'zsh', 'ps1', 'psm1', 'vbs', 'vbe', 'wsf', 'jar', 'apk', 'app',
        'htaccess', 'htpasswd',
    ];

    /** What a document repository is actually for, when nobody has configured anything. */
    public const DEFAULT_ALLOWED = [
        'pdf',
        'doc', 'docx', 'rtf', 'odt',
        'xls', 'xlsx', 'ods',
        'ppt', 'pptx', 'odp',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic',
        'csv', 'txt', 'md',
        'zip', 'rar', '7z',
        'msg', 'eml',
    ];

    /**
     * The types a preview may be rendered for.
     *
     * Everything else downloads. A preview is an inline render, so the list is short on purpose —
     * a format is here because a browser can display it without executing anything.
     */
    private const PREVIEWABLE = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
        'text/plain', 'text/csv', 'text/markdown',
    ];

    /**
     * @return array{ok: bool, reason: ?string, mime: ?string, extension: ?string}
     */
    public function inspect(UploadedFile $file, ?DocumentSetting $settings = null): array
    {
        $settings ??= DocumentSetting::current();

        if (! $file->isValid()) {
            return $this->refuse('The file did not upload completely. Try again.');
        }

        $extension = Str::lower(trim((string) $file->getClientOriginalExtension()));
        if ($extension === '') {
            // A file with no extension cannot be matched against any list, and the guard is not
            // going to guess one on the uploader's behalf.
            return $this->refuse('The file has no extension, so its type cannot be established.');
        }

        if (in_array($extension, self::NEVER, true)) {
            return $this->refuse(
                Str::upper($extension) . ' files are never accepted. Anything a browser or a server '
                . 'can execute is a security risk in a document library, whatever it is named.',
            );
        }

        $allowed = $this->allowedFor($settings);
        if (! in_array($extension, $allowed, true)) {
            return $this->refuse(
                Str::upper($extension) . ' files are not on this organisation\'s allowed list. '
                . 'An administrator can add the type on the Document settings screen.',
            );
        }

        $maxBytes = max(1, (int) $settings->max_file_size_mb) * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            return $this->refuse(
                'The file is ' . $this->readableSize((int) $file->getSize()) . ', which is over the '
                . $settings->max_file_size_mb . ' MB limit set for this organisation.',
            );
        }

        // What the bytes say, rather than what the upload claimed.
        $mime = $this->sniff($file);
        $declared = (string) $file->getClientMimeType();

        if (! $this->typeMatchesExtension($mime, $extension)) {
            // Renaming a file does not change what it is, and a repository that accepts the
            // rename is a repository that stores an executable called report.pdf.
            return $this->refuse(
                'The contents of this file do not match its .' . $extension . ' extension '
                . '(it looks like ' . $mime . '). Rename it correctly and upload it again.',
            );
        }

        /*
         * Scanned last, on purpose.
         *
         * Everything above is a cheap check on metadata; this one reads the whole file and may
         * cross a socket to another process. Running it only on files that have already proved
         * they are an allowed type, of an allowed size, and honestly named keeps the expensive
         * check off the obviously-bad uploads.
         */
        if ($threat = app(MalwareScanner::class)->scan($file)) {
            return $this->refuse($threat);
        }

        return ['ok' => true, 'reason' => null, 'mime' => $mime, 'extension' => $extension, 'declared_mime' => $declared];
    }

    /** The type read from the file's own bytes. */
    public function sniff(UploadedFile $file): string
    {
        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file->getRealPath());

            return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
        } catch (\Throwable) {
            return 'application/octet-stream';
        }
    }

    public function canPreview(?string $mime): bool
    {
        return $mime !== null && in_array(Str::lower($mime), self::PREVIEWABLE, true);
    }

    /** @return string[] */
    public function allowedFor(?DocumentSetting $settings = null): array
    {
        $settings ??= DocumentSetting::current();
        $allowed = $settings->allowed_extensions ?: self::DEFAULT_ALLOWED;

        $allowed = array_map(fn ($e) => Str::lower(ltrim(trim((string) $e), '.')), $allowed);
        $blocked = array_map(fn ($e) => Str::lower(ltrim(trim((string) $e), '.')), $settings->blocked_extensions ?: []);

        // The permanent list wins over anything configured, in both directions.
        return array_values(array_diff(array_unique($allowed), $blocked, self::NEVER));
    }

    /** @return string[] the extensions no configuration can enable */
    public function neverAllowed(): array
    {
        return self::NEVER;
    }

    /**
     * Whether a sniffed type is plausible for the claimed extension.
     *
     * Deliberately forgiving where the ambiguity is real rather than suspicious: Office documents
     * are ZIP containers, and plain-text formats are indistinguishable from one another by magic
     * number. Being strict there would reject legitimate files all day and teach people to
     * disable the check.
     */
    private function typeMatchesExtension(string $mime, string $extension): bool
    {
        $mime = Str::lower($mime);

        $families = [
            'pdf'  => ['application/pdf'],
            'jpg'  => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
            'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
            'tif'  => ['image/tiff'], 'tiff' => ['image/tiff'], 'heic' => ['image/heic', 'image/heif'],
        ];

        if (isset($families[$extension])) {
            return in_array($mime, $families[$extension], true);
        }

        // Modern Office formats are ZIP archives; the legacy ones are OLE compound files. Both
        // sniff as something generic, so the check is that it is not something alarming.
        $containerish = ['zip', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', '7z', 'rar',
            'doc', 'xls', 'ppt', 'msg'];
        if (in_array($extension, $containerish, true)) {
            return ! Str::startsWith($mime, ['text/html', 'image/svg', 'application/x-dosexec',
                'application/x-executable', 'application/x-mach-binary', 'application/x-sharedlib']);
        }

        // Text-ish formats: anything that reads as text, and explicitly not markup.
        if (in_array($extension, ['csv', 'txt', 'md', 'rtf', 'eml'], true)) {
            return Str::startsWith($mime, 'text/')
                || in_array($mime, ['application/csv', 'application/rtf', 'message/rfc822', 'application/octet-stream'], true)
                ? ! Str::contains($mime, ['html', 'svg', 'xml'])
                : false;
        }

        return true;
    }

    private function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'mime' => null, 'extension' => null, 'declared_mime' => null];
    }

    private function readableSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, $unit === 'B' ? 0 : 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return $bytes . ' B';
    }
}

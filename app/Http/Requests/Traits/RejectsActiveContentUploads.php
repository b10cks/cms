<?php

namespace App\Http\Requests\Traits;

use Closure;
use Illuminate\Http\UploadedFile;

/**
 * Blocks uploads of file types that browsers may execute as active content
 * (HTML, XHTML, JavaScript). These are never legitimate media assets and,
 * when served inline from the delivery origin, are a stored-XSS vector.
 *
 * SVG is deliberately still allowed — it is a legitimate image format — but
 * the delivery layer serves it under a restrictive CSP + `nosniff` so any
 * embedded script cannot execute.
 */
trait RejectsActiveContentUploads
{
    /**
     * Extensions that must never be accepted as an asset upload.
     *
     * @var array<int, string>
     */
    private array $blockedExtensions = [
        'html', 'htm', 'xhtml', 'shtml', 'js', 'mjs', 'xml', 'xht', 'phtml', 'php', 'phar',
        // Every spelling a mis-configured web server might still hand to PHP.
        'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phtm', 'inc',
        // Server config that could re-enable execution for a whole directory.
        'htaccess', 'htpasswd', 'user.ini',
        // Compressed SVG: same active content, and the extension sidesteps any
        // check that only looks at `svg`.
        'svgz',
    ];

    /**
     * Detected (finfo) MIME types that must never be accepted as an asset upload.
     *
     * @var array<int, string>
     */
    private array $blockedMimeTypes = [
        'text/html',
        'application/xhtml+xml',
        'application/javascript',
        'text/javascript',
        'application/xml',
        'text/xml',
        'application/x-httpd-php',
    ];

    protected function rejectActiveContentRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $extension = strtolower((string) $value->getClientOriginalExtension());
            // getMimeType() inspects the file contents (finfo), so it cannot be
            // spoofed by renaming the file.
            $mimeType = strtolower((string) $value->getMimeType());

            if (in_array($extension, $this->blockedExtensions, true)
                || in_array($mimeType, $this->blockedMimeTypes, true)) {
                $fail('The :attribute may not be an HTML, XML or script file.');
            }
        };
    }
}

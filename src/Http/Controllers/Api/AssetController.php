<?php

namespace Gmammadov\SupportChat\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the widget's JS/CSS straight from the package, so there is nothing to publish and
 * upgrades never leave stale copies in public/. Only the two known files can be requested.
 */
class AssetController extends Controller
{
    public const FILES = [
        'support-chat.js' => 'application/javascript; charset=utf-8',
        'support-chat.css' => 'text/css; charset=utf-8',
    ];

    public function __invoke(string $file): BinaryFileResponse
    {
        abort_unless(array_key_exists($file, self::FILES), 404);

        return response()->file(static::path($file), [
            'Content-Type' => self::FILES[$file],
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            // Lets other origins (a separate frontend) load the script.
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }

    public static function path(string $file): string
    {
        return dirname(__DIR__, 4) . '/resources/dist/' . $file;
    }

    public static function version(): string
    {
        return substr(md5((string) @filemtime(static::path('support-chat.js')) . '|' . (string) @filemtime(static::path('support-chat.css'))), 0, 10);
    }
}

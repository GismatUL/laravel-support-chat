<?php

namespace Gismatul\SupportChat\Support;

class TextSanitizer
{
    /**
     * Normalises plain chat text. Messages are stored as plain text (never HTML) and must be escaped
     * wherever they are rendered; this only removes invisible/control characters and invalid UTF-8.
     */
    public static function clean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);

        // Control characters (except tab/newline), and bidi overrides that can disguise text.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value) ?? '';

        // Collapse excessive blank lines.
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';

        return trim($value);
    }
}

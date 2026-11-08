<?php

namespace App\Services\Search;

use Illuminate\Support\Str;

/**
 * One way of turning text into comparable tokens, used for the documents being indexed and for the query being run,
 * so both sides always meet in the same form: lowercase, accents removed (Möller and Moller are one word, done here
 * in PHP so no database extension is needed), punctuation to spaces, unit spellings unified, and plurals folded
 * (phones/phone, batteries/battery). The folding is crude on purpose; it only has to be the same on both sides.
 */
final class Text
{
    /** Words that carry no search meaning in a typed request. */
    private const STOP_WORDS = [
        'a', 'an', 'the', 'and', 'for', 'with', 'of', 'in', 'on', 'to', 'at', 'by', 'or', 'my', 'me', 'i', 'im', 'need', 'want',
        'looking', 'find', 'show', 'some', 'any', 'that', 'is', 'are', 'please', 'something', 'one',
    ];

    public static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        // 55" and 55 inches both mean 55 inch.
        $text = preg_replace('/(\d)\s*["″”“]/u', '$1 inch ', $text) ?? $text;
        $text = Str::ascii($text);
        $text = str_replace('&', ' and ', $text);
        // 1,000 is 1000.
        $text = preg_replace('/(?<=\d),(?=\d{3}(?!\d))/', '', $text) ?? $text;
        $text = preg_replace('/[^a-z0-9.]+/', ' ', $text) ?? $text;
        // A dot survives only between two digits (5.5), anywhere else it is punctuation.
        $text = preg_replace('/(?<!\d)\.|\.(?!\d)/', ' ', $text) ?? $text;
        $text = preg_replace('/\b(inches|inchs|in\.)\b/', 'inch', $text) ?? $text;
        $text = preg_replace('/\b(litres|liters|liter|ltr|ltrs)\b/', 'litre', $text) ?? $text;
        // 55inch -> 55 inch, 256gb -> 256 gb; model numbers such as s23 or a54 are left alone.
        $text = preg_replace('/(\d)(inch|gb|tb|mb|kg|ml|mah|hz|cm|mm|litre|w)\b/', '$1 $2', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /** Normalised and plural-folded: the form that is stored and searched. */
    public static function index(string $text): string
    {
        return implode(' ', self::tokens($text));
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $text, bool $dropStopWords = false): array
    {
        $out = [];

        foreach (explode(' ', self::normalise($text)) as $token) {
            if ($token === '' || ($dropStopWords && in_array($token, self::STOP_WORDS, true))) {
                continue;
            }

            $out[] = self::fold($token);
        }

        return $out;
    }

    public static function fold(string $token): string
    {
        $length = strlen($token);

        if ($length <= 3 || ctype_digit($token) || preg_match('/\d/', $token) === 1) {
            return $token;
        }

        if (str_ends_with($token, 'ies') && $length > 4) {
            return substr($token, 0, -3).'y';
        }

        if (preg_match('/(ches|shes|xes|zes|sses)$/', $token) === 1) {
            return substr($token, 0, -2);
        }

        if (str_ends_with($token, 's') && ! preg_match('/(ss|us|is)$/', $token)) {
            return substr($token, 0, -1);
        }

        return $token;
    }

    /** True when $needle (a folded token) starts a word of the folded text. */
    public static function hasWord(string $foldedText, string $needle): bool
    {
        return $needle !== '' && preg_match('/(^| )'.preg_quote($needle, '/').'/', $foldedText) === 1;
    }

    public static function slugify(string $text): string
    {
        return Str::slug($text);
    }
}

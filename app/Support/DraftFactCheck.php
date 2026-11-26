<?php

namespace App\Support;

/**
 * Finds numbers in an AI draft that do not appear in the facts the publisher
 * gave, such as an invented parking count or distance, so the publisher can
 * check them before saving.
 */
class DraftFactCheck
{
    /**
     * @param  array<int|string, string>  $sources  every text the AI was given
     * @return list<string> the unsupported numbers, as written in the draft
     */
    public static function unsupportedNumbers(string $draft, array $sources): array
    {
        $allowed = [];
        foreach ($sources as $source) {
            foreach (self::numbersIn((string) $source) as $number) {
                $allowed[self::normalize($number)] = true;
                $value = (float) self::normalize($number);
                // Allow the usual shorthand for amounts: 18,500,000 → 18.5 (million), 45,000 → 45 (thousand).
                if ($value >= 1000) {
                    $allowed[self::normalize((string) round($value / 1000, 1))] = true;
                }
                if ($value >= 1000000) {
                    $allowed[self::normalize((string) round($value / 1000000, 2))] = true;
                    $allowed[self::normalize((string) round($value / 1000000, 1))] = true;
                }
            }
        }

        $unsupported = [];
        foreach (self::numbersIn($draft) as $number) {
            $key = self::normalize($number);
            if (! isset($allowed[$key]) && ! in_array($number, $unsupported, true)) {
                $unsupported[] = $number;
            }
        }

        return $unsupported;
    }

    /**
     * @return list<string>
     */
    private static function numbersIn(string $text): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $text, $matches);

        return array_values(array_map(fn (string $n): string => rtrim($n, ',.'), $matches[0]));
    }

    private static function normalize(string $number): string
    {
        $plain = str_replace(',', '', $number);
        if (str_contains($plain, '.')) {
            $plain = rtrim(rtrim($plain, '0'), '.');
        }

        return ltrim($plain, '0') === '' ? '0' : ltrim($plain, '0');
    }
}

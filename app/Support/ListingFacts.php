<?php

namespace App\Support;

/**
 * Turns the raw listing form values into clearly labelled facts for the AI,
 * so it cannot mistake a monthly rent for a sale price or a lot area for a
 * floor area.
 */
class ListingFacts
{
    /**
     * @param  array<string, mixed>  $input  title, size, location, price, type, listing_type, features
     * @return array<string, string>
     */
    public static function from(array $input): array
    {
        $type = trim((string) ($input['type'] ?? ''));
        $listingType = trim((string) ($input['listing_type'] ?? ''));
        $price = isset($input['price']) && is_numeric($input['price']) && $input['price'] > 0 ? (float) $input['price'] : null;
        $size = isset($input['size']) && is_numeric($input['size']) && $input['size'] > 0 ? (float) $input['size'] : null;
        $isLot = stripos($type, 'lot') !== false;
        $kind = self::listingKind($listingType);

        $facts = array_filter([
            'title' => trim((string) ($input['title'] ?? '')),
            'property_type' => $type,
            'listing_type' => $listingType,
            'location' => trim((string) ($input['location'] ?? '')),
            'price' => $price === null ? '' : self::peso($price).match ($kind) {
                'rent' => ' per month',
                'lease' => ' lease price (as listed)',
                'sale' => ' total asking price',
                default => '',
            },
            'area' => $size === null ? '' : self::number($size).' sqm '.($isLot ? 'lot area' : 'floor area'),
            'price_per_sqm' => $price !== null && $size !== null
                ? self::peso(round($price / $size)).' per sqm'.($kind === 'rent' ? ' per month' : '')
                : '',
            'key_features' => trim((string) ($input['features'] ?? '')),
        ], fn (string $value): bool => $value !== '');

        return $facts;
    }

    public static function listingKind(string $listingType): string
    {
        $value = strtolower($listingType);

        return match (true) {
            str_contains($value, 'lease') => 'lease',
            str_contains($value, 'rent') => 'rent',
            str_contains($value, 'sale') => 'sale',
            default => '',
        };
    }

    private static function peso(float $amount): string
    {
        return '₱'.self::number($amount);
    }

    private static function number(float $value): string
    {
        return floor($value) == $value ? number_format($value) : number_format($value, 2);
    }
}

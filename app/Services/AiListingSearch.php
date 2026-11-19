<?php

namespace App\Services;

use App\Models\Property;

/**
 * Turns the filters the AI extracts from a natural-language query into a
 * database search over publicly visible listings.
 *
 * Structured filters (location, type, listing type, price, size) narrow the
 * results. Keywords only rank them: requiring every keyword to appear
 * verbatim excluded genuine matches (e.g. "for rent" or "small" rarely
 * appear in a listing's text).
 */
class AiListingSearch
{
    public const LIMIT = 30;

    /** Words that describe the request rather than the property. */
    private const IGNORED_KEYWORDS = [
        'for', 'rent', 'for rent', 'lease', 'for lease', 'sale', 'for sale', 'small', 'big', 'large', 'cheap',
        'affordable', 'near', 'space', 'spaces', 'commercial', 'property', 'properties', 'unit', 'butuan', 'butuan city',
        'office', 'offices', 'retail', 'warehouse', 'warehouses', 'lot', 'lots', 'commercial lot', 'office space',
        'retail space', 'storefront', 'sqm', 'square meters',
    ];

    private const COLUMNS = [
        'id', 'owner_id', 'broker_id', 'title', 'slug', 'property_type',
        'listing_type', 'description', 'price', 'currency', 'address_line',
        'barangay', 'city', 'province', 'postal_code', 'latitude', 'longitude',
        'bedrooms', 'bathrooms', 'floor_area', 'lot_area', 'parking_spaces',
        'status', 'published_at', 'created_at', 'updated_at',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function find(array $filters): array
    {
        $query = Property::query()
            ->select(self::COLUMNS)
            ->with(['images', 'owner:id,name', 'broker:id,name'])
            ->onMarket();

        $location = $this->text($filters['location'] ?? null);
        if ($location !== '') {
            $term = '%'.mb_strtolower($location).'%';
            $query->where(function ($builder) use ($term): void {
                foreach (['address_line', 'barangay', 'city', 'province'] as $field) {
                    $builder->orWhereRaw('LOWER('.$field.') LIKE ?', [$term]);
                }
            });
        }

        $propertyType = $this->text($filters['property_type'] ?? null);
        if ($propertyType !== '') {
            $query->whereRaw('LOWER(property_type) LIKE ?', ['%'.mb_strtolower($propertyType).'%']);
        }

        $listingType = mb_strtolower($this->text($filters['listing_type'] ?? null));
        if (in_array($listingType, ['rent', 'lease', 'sale'], true)) {
            $query->whereRaw('LOWER(listing_type) LIKE ?', ['%'.$listingType.'%']);
        }

        $maxPrice = $filters['max_price'] ?? null;
        if (is_numeric($maxPrice) && (float) $maxPrice > 0) {
            $query->where('price', '<=', $maxPrice);
        }

        $minSize = $filters['min_size'] ?? null;
        if (is_numeric($minSize) && (float) $minSize > 0) {
            $query->where(function ($builder) use ($minSize): void {
                $builder->where('floor_area', '>=', $minSize)->orWhere('lot_area', '>=', $minSize);
            });
        }

        $candidates = $query->orderByDesc('published_at')->orderByDesc('id')->limit(200)->get();
        $keywords = $this->keywords($filters['keywords'] ?? []);

        if ($keywords !== []) {
            // Stable sort: more keyword matches first, otherwise keep newest first.
            $candidates = $candidates
                ->values()
                ->map(fn (Property $property, int $index) => [$property, $this->score($property, $keywords), $index])
                ->sort(fn (array $a, array $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]])
                ->map(fn (array $row) => $row[0]);
        }

        return $candidates->take(self::LIMIT)->values()->toArray();
    }

    /**
     * @return array<int, string>
     */
    private function keywords(mixed $keywords): array
    {
        if (! is_array($keywords)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($keyword) => mb_strtolower($this->text($keyword)), $keywords),
            fn (string $keyword) => $keyword !== '' && mb_strlen($keyword) > 2 && ! in_array($keyword, self::IGNORED_KEYWORDS, true)
        )));
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function score(Property $property, array $keywords): int
    {
        $haystack = mb_strtolower(implode(' ', [$property->title, $property->description, $property->address_line, $property->barangay]));

        return count(array_filter($keywords, fn (string $keyword) => str_contains($haystack, $keyword)));
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}

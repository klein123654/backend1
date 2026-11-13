<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedSearch extends Model
{
    /** Filters a saved search may hold; mirrors the public property search. */
    public const FILTER_KEYS = ['search', 'property_type', 'listing_type', 'barangay', 'min_price', 'max_price'];

    protected $fillable = ['user_id', 'name', 'filters', 'last_alerted_at'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'last_alerted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether a listing satisfies every filter in this search.
     */
    public function matches(Property $property): bool
    {
        $filters = $this->filters ?? [];

        foreach (['property_type', 'listing_type'] as $field) {
            if (! empty($filters[$field]) && $property->{$field} !== $filters[$field]) {
                return false;
            }
        }

        if (! empty($filters['barangay'])
            && mb_strtolower(trim((string) $property->barangay)) !== mb_strtolower(trim($filters['barangay']))) {
            return false;
        }

        if (isset($filters['min_price']) && (float) $property->price < (float) $filters['min_price']) {
            return false;
        }

        if (isset($filters['max_price']) && (float) $property->price > (float) $filters['max_price']) {
            return false;
        }

        if (! empty($filters['search'])) {
            $haystack = mb_strtolower(implode(' ', [
                $property->title, $property->description, $property->address_line, $property->barangay, $property->city,
            ]));
            if (! str_contains($haystack, mb_strtolower(trim($filters['search'])))) {
                return false;
            }
        }

        return true;
    }
}

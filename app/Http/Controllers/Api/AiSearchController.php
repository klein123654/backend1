<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\DeepSeekService;
use Illuminate\Http\Request;

class AiSearchController extends Controller
{
    /**
     * Interpret a natural-language query and return matching public listings.
     */
    public function __invoke(Request $request, DeepSeekService $deepSeek): mixed
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $result = $deepSeek->search($validated['query'], function (array $filters): array {
            $properties = Property::query()
                ->select([
                    'id', 'owner_id', 'broker_id', 'title', 'slug', 'property_type',
                    'listing_type', 'description', 'price', 'currency', 'address_line',
                    'barangay', 'city', 'province', 'postal_code', 'latitude', 'longitude',
                    'bedrooms', 'bathrooms', 'floor_area', 'lot_area', 'parking_spaces',
                    'status', 'published_at', 'created_at', 'updated_at',
                ])
                ->with(['images', 'owner:id,name', 'broker:id,name'])
                ->where('status', 'published')
                ->whereNotNull('published_at');

            $location = is_string($filters['location'] ?? null)
                ? trim($filters['location'])
                : '';

            if ($location !== '') {
                $term = '%'.strtolower($location).'%';
                $properties->where(function ($builder) use ($term): void {
                    foreach (['address_line', 'barangay', 'city', 'province'] as $field) {
                        $builder->orWhereRaw('LOWER('.$field.') LIKE ?', [$term]);
                    }
                });
            }

            $propertyType = is_string($filters['property_type'] ?? null)
                ? trim($filters['property_type'])
                : '';

            if ($propertyType !== '') {
                $properties->whereRaw('LOWER(property_type) LIKE ?', ['%'.strtolower($propertyType).'%']);
            }

            $maxPrice = $filters['max_price'] ?? null;
            if (is_numeric($maxPrice) && (float) $maxPrice > 0) {
                $properties->where('price', '<=', $maxPrice);
            }

            $minSize = $filters['min_size'] ?? null;
            if (is_numeric($minSize) && (float) $minSize > 0) {
                $properties->where(function ($builder) use ($minSize): void {
                    $builder->where('floor_area', '>=', $minSize)
                        ->orWhere('lot_area', '>=', $minSize);
                });
            }

            $keywords = array_values(array_filter(
                $filters['keywords'] ?? [],
                static fn (mixed $keyword): bool => is_string($keyword) && trim($keyword) !== ''
            ));

            foreach ($keywords as $keyword) {
                $term = '%'.strtolower(trim($keyword)).'%';
                $properties->where(function ($builder) use ($term): void {
                    $builder->whereRaw('LOWER(title) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(description) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(address_line) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(barangay) LIKE ?', [$term]);
                });
            }

            return $properties
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(30)
                ->get()
                ->toArray();
        });

        if (! $result['success']) {
            return response()->json($result, 200);
        }

        return response()->json($result);
    }
}

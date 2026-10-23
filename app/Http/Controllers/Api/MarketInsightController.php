<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\DeepSeekService;
use Illuminate\Http\JsonResponse;

class MarketInsightController extends Controller
{
    public function __invoke(DeepSeekService $deepSeek): JsonResponse
    {
        $averagePriceByType = Property::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->selectRaw('property_type, AVG(price) AS average_price, COUNT(*) AS listing_count')
            ->groupBy('property_type')
            ->orderBy('property_type')
            ->get()
            ->map(static fn (Property $property): array => [
                'property_type' => $property->property_type,
                'average_price' => (float) $property->getAttribute('average_price'),
                'listing_count' => (int) $property->getAttribute('listing_count'),
            ])
            ->all();

        $countByLocation = Property::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->selectRaw('city, province, COUNT(*) AS listing_count')
            ->groupBy('city', 'province')
            ->orderBy('city')
            ->orderBy('province')
            ->get()
            ->map(static fn (Property $property): array => [
                'city' => $property->city,
                'province' => $property->province,
                'listing_count' => (int) $property->getAttribute('listing_count'),
            ])
            ->all();

        $aggregates = [
            'average_price_by_type' => $averagePriceByType,
            'count_by_location' => $countByLocation,
        ];
        $insight = $deepSeek->marketInsight($aggregates);

        return response()->json([
            'success' => $insight['success'],
            'aggregates' => $aggregates,
            'summary' => $insight['summary'],
            ...isset($insight['message']) ? ['message' => $insight['message']] : [],
        ]);
    }
}

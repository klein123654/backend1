<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\DeepSeekService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class MarketInsightController extends Controller
{
    /**
     * The AI summary only depends on the aggregates, so reuse it until the
     * numbers change. Without this every dashboard visit waits on DeepSeek.
     */
    public const CACHE_TTL_SECONDS = 6 * 60 * 60;

    public function __invoke(DeepSeekService $deepSeek): JsonResponse
    {
        $averagePriceByType = Property::query()
            ->publiclyVisible()
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
            ->publiclyVisible()
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
        $cacheKey = 'market-insight:'.md5(json_encode($aggregates, JSON_THROW_ON_ERROR));
        $insight = Cache::get($cacheKey);

        if (! is_array($insight)) {
            $insight = $deepSeek->marketInsight($aggregates);

            // Only cache real summaries so a temporary AI outage is retried.
            if ($insight['success']) {
                Cache::put($cacheKey, $insight, self::CACHE_TTL_SECONDS);
            }
        }

        return response()->json([
            'success' => $insight['success'],
            'aggregates' => $aggregates,
            'summary' => $insight['summary'],
            ...isset($insight['message']) ? ['message' => $insight['message']] : [],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeepSeekService;
use App\Services\MarketInsightData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class MarketInsightController extends Controller
{
    /**
     * The AI summary only depends on the aggregates, so reuse it until the
     * numbers change. Without this every dashboard visit waits on DeepSeek.
     */
    public const CACHE_TTL_SECONDS = 6 * 60 * 60;

    public function __invoke(DeepSeekService $deepSeek, MarketInsightData $marketData): JsonResponse
    {
        $aggregates = $marketData->build();

        if ($aggregates['total_listings'] < MarketInsightData::MIN_LISTINGS_FOR_SUMMARY) {
            return response()->json([
                'success' => true,
                'aggregates' => $aggregates,
                'summary' => null,
                'ai_written' => false,
                'message' => 'A market summary appears once at least '.MarketInsightData::MIN_LISTINGS_FOR_SUMMARY.' listings are published.',
            ]);
        }

        $cacheKey = 'market-insight:v2:'.md5(json_encode($aggregates, JSON_THROW_ON_ERROR));
        $insight = Cache::get($cacheKey);

        if (! is_array($insight)) {
            $insight = $deepSeek->marketInsight($aggregates);

            // Only cache real summaries so a temporary AI outage is retried.
            if ($insight['success']) {
                Cache::put($cacheKey, $insight, self::CACHE_TTL_SECONDS);
            }
        }

        // Without the AI, still give a correct summary written from the figures.
        $aiWritten = $insight['success'];

        return response()->json([
            'success' => true,
            'aggregates' => $aggregates,
            'summary' => $aiWritten ? $insight['summary'] : $marketData->fallbackSummary($aggregates),
            'ai_written' => $aiWritten,
        ]);
    }
}

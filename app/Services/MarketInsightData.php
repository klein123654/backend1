<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * Builds the numbers behind the market insight. Every figure and ranking is
 * computed here, in code; the AI only puts these facts into words.
 */
class MarketInsightData
{
    /** Below this many public listings a written summary would mislead. */
    public const MIN_LISTINGS_FOR_SUMMARY = 3;

    private const LISTING_TYPES = ['rent' => 'For rent', 'lease' => 'For lease', 'sale' => 'For sale'];

    public function build(): array
    {
        $listings = Property::query()
            ->publiclyVisible()
            ->get(['property_type', 'listing_type', 'price', 'floor_area', 'barangay']);

        $byType = $listings->groupBy('property_type')
            ->map(fn (Collection $group, string $type): array => [
                'property_type' => $type,
                'listing_count' => $group->count(),
                'prices' => collect(self::LISTING_TYPES)
                    ->map(fn (string $label, string $key): ?array => $this->priceStats($group->where('listing_type', $key), $key, $label))
                    ->filter()
                    ->values()
                    ->all(),
            ])
            ->sortBy([['listing_count', 'desc'], ['property_type', 'asc']])
            ->values()
            ->all();

        $byBarangay = $listings->filter(fn (Property $p): bool => filled($p->barangay))
            ->countBy('barangay')
            ->map(fn (int $count, string $barangay): array => ['barangay' => $barangay, 'listing_count' => $count])
            ->sortBy([['listing_count', 'desc'], ['barangay', 'asc']])
            ->values()
            ->take(5)
            ->all();

        return [
            'total_listings' => $listings->count(),
            'by_type' => $byType,
            'top_barangays' => $byBarangay,
            'highlights' => $this->highlights($byType, $byBarangay),
        ];
    }

    /** A plain summary written from the figures, used when the AI is unavailable. */
    public function fallbackSummary(array $data): string
    {
        $h = $data['highlights'];
        $sentences = ["There are {$data['total_listings']} published commercial listings in Butuan City."];
        $sentences[] = count($h['most_listed_types']) > 1
            ? 'The most listed property types are '.$this->joinNames($h['most_listed_types'])." ({$h['most_listed_count']} listings each)."
            : "{$h['most_listed_types'][0]} have the most listings ({$h['most_listed_count']}).";
        if ($h['top_barangays']) {
            $sentences[] = count($h['top_barangays']) > 1
                ? $this->joinNames($h['top_barangays'])." have the most listings of any barangay ({$h['top_barangay_count']} each)."
                : "{$h['top_barangays'][0]} has the most listings of any barangay ({$h['top_barangay_count']}).";
        }

        return implode(' ', $sentences);
    }

    private function priceStats(Collection $listings, string $key, string $label): ?array
    {
        if ($listings->isEmpty()) {
            return null;
        }

        $perSqm = $listings->filter(fn (Property $p): bool => (float) $p->floor_area > 0)
            ->map(fn (Property $p): float => (float) $p->price / (float) $p->floor_area);

        return [
            'listing_type' => $key,
            'label' => $label,
            'listing_count' => $listings->count(),
            'median_price' => round($this->median($listings->map(fn (Property $p): float => (float) $p->price)), 2),
            'median_price_per_sqm' => $perSqm->isEmpty() ? null : round($this->median($perSqm), 2),
            'price_unit' => $key === 'rent' ? 'per month' : ($key === 'lease' ? 'lease price as listed' : 'total asking price'),
        ];
    }

    private function highlights(array $byType, array $byBarangay): array
    {
        $top = $byType[0]['listing_count'] ?? 0;
        $topBarangay = $byBarangay[0]['listing_count'] ?? 0;

        // Ties are listed together, so nothing is called "the most" when it isn't.
        // With one listing per barangay, no barangay stands out.
        return [
            'most_listed_types' => collect($byType)->where('listing_count', $top)->pluck('property_type')->values()->all(),
            'most_listed_count' => $top,
            'top_barangays' => $topBarangay < 2 ? [] : collect($byBarangay)->where('listing_count', $topBarangay)->pluck('barangay')->values()->all(),
            'top_barangay_count' => $topBarangay,
        ];
    }

    private function median(Collection $values): float
    {
        $sorted = $values->sort()->values();
        $count = $sorted->count();
        $middle = intdiv($count, 2);

        return $count % 2 ? (float) $sorted[$middle] : ((float) $sorted[$middle - 1] + (float) $sorted[$middle]) / 2;
    }

    private function joinNames(array $names): string
    {
        $last = array_pop($names);

        return $names ? implode(', ', $names).' and '.$last : $last;
    }
}

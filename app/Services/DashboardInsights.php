<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Short insights for a dashboard card. The facts are picked from the computed
 * dashboard figures; the AI only words them, and a plain version written from
 * the same facts is used when the AI is unavailable.
 */
class DashboardInsights
{
    public const PUBLISHER_METRICS = ['listings', 'inquiries', 'views', 'rate'];

    public const ADMIN_METRICS = ['listings', 'users', 'inquiries', 'queue'];

    private const CACHE_TTL_SECONDS = 3 * 3600;

    public function __construct(private DeepSeekService $deepSeek) {}

    /**
     * @param  array<string, mixed>  $data  output of DashboardData::publisher() or ::admin()
     * @return array{bullets: list<string>, ai_written: bool}
     */
    public function for(string $scope, string $metric, array $data, string $cacheOwner): array
    {
        $facts = $scope === 'admin' ? $this->adminFacts($metric, $data) : $this->publisherFacts($metric, $data);
        $fallback = array_values(array_filter($facts['plain']));
        if ($facts['figures'] === []) {
            return ['bullets' => $fallback, 'ai_written' => false];
        }

        $key = 'dashboard-insight:v1:'.$cacheOwner.':'.$metric.':'.md5(json_encode($facts['figures'], JSON_THROW_ON_ERROR));
        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $result = $this->deepSeek->dashboardInsight($facts['figures'], $scope === 'admin' ? 'site administrator' : 'property publisher');
        if (! ($result['success'] ?? false)) {
            return ['bullets' => $fallback, 'ai_written' => false];
        }

        $insight = ['bullets' => $result['bullets'], 'ai_written' => true];
        Cache::put($key, $insight, self::CACHE_TTL_SECONDS);

        return $insight;
    }

    /**
     * @return array{figures: array<string, mixed>, plain: list<string|null>}
     */
    private function publisherFacts(string $metric, array $d): array
    {
        $k = $d['kpis'];
        $days = $d['days'];
        $byViews = collect($d['by_listing']);

        return match ($metric) {
            'inquiries' => $this->inquiryFacts($d, $k, $days, $byViews),
            'views' => $this->viewFacts($k, $days, $byViews),
            'rate' => $this->rateFacts($k, $byViews),
            default => $this->listingFacts($d, $k),
        };
    }

    private function inquiryFacts(array $d, array $k, int $days, $byListing): array
    {
        $weeks = collect($d['inquiries_per_week']);
        $thisWeek = (int) ($weeks->last()['value'] ?? 0);
        $best = $weeks->max('value');
        $top = $byListing->sortByDesc('inquiries')->first();
        $figures = [
            "inquiries_last_{$days}_days" => $k['inquiries'],
            "inquiries_previous_{$days}_days" => $k['previous_inquiries'],
            'inquiries_this_calendar_week' => $thisWeek,
            'this_week_is_highest_of_8_weeks' => $thisWeek > 0 && $thisWeek === $best,
            'inquiries_waiting_for_reply' => $d['attention']['unanswered_inquiries'],
        ];
        if ($top && $top['inquiries'] > 0) {
            $figures['listing_with_most_inquiries'] = $top['title'];
            $figures['that_listing_inquiries'] = $top['inquiries'];
        }

        return ['figures' => $k['inquiries'] + $thisWeek === 0 ? [] : $figures, 'plain' => [
            "{$k['inquiries']} inquiries in the last {$days} days ({$k['previous_inquiries']} in the {$days} days before).",
            $top && $top['inquiries'] > 0 ? "{$top['title']} received the most ({$top['inquiries']})." : null,
            $d['attention']['unanswered_inquiries'] > 0 ? "{$d['attention']['unanswered_inquiries']} inquiries are waiting for a reply." : 'Every inquiry has a reply.',
        ]];
    }

    private function viewFacts(array $k, int $days, $byListing): array
    {
        $change = $k['previous_views'] > 0 ? (int) round(($k['views'] - $k['previous_views']) / $k['previous_views'] * 100) : null;
        $top = $byListing->first();
        $quiet = $byListing->filter(fn ($l) => $l['views'] >= 20 && $l['inquiries'] === 0)->first();
        $figures = ["views_last_{$days}_days" => $k['views'], "views_previous_{$days}_days" => $k['previous_views'], 'percent_change' => $change];
        if ($top && $top['views'] > 0) {
            $figures += ['most_viewed_listing' => $top['title'], 'its_views' => $top['views'], 'its_inquiries' => $top['inquiries']];
        }
        if ($quiet) {
            $figures += ['listing_with_views_but_no_inquiries' => $quiet['title'], 'its_views_without_inquiries' => $quiet['views']];
        }

        return ['figures' => $k['views'] === 0 ? [] : $figures, 'plain' => [
            "{$k['views']} page views in the last {$days} days".($change !== null ? sprintf(' (%s%d%% vs the %d days before).', $change >= 0 ? '+' : '', $change, $days) : '.'),
            $top && $top['views'] > 0 ? "{$top['title']} has the most views ({$top['views']})." : null,
            $quiet ? "{$quiet['title']} has {$quiet['views']} views but no inquiries yet." : null,
        ]];
    }

    private function rateFacts(array $k, $byListing): array
    {
        $rated = $byListing->filter(fn ($l) => $l['views'] >= 20)
            ->map(fn ($l) => $l + ['rate' => round($l['inquiries'] / $l['views'] * 100, 1)])->sortByDesc('rate')->values();
        $figures = ['inquiries_per_100_views_overall' => $k['inquiries_per_100_views']];
        if ($rated->count() >= 2) {
            $figures += ['best_listing' => $rated->first()['title'], 'best_rate' => $rated->first()['rate'], 'lowest_listing' => $rated->last()['title'], 'lowest_rate' => $rated->last()['rate']];
        }

        return ['figures' => $k['inquiries_per_100_views'] === null ? [] : $figures, 'plain' => [
            $k['inquiries_per_100_views'] === null ? 'No page views yet in this period.' : "Visitors sent {$k['inquiries_per_100_views']} inquiries per 100 page views.",
            $rated->count() >= 2 ? "{$rated->first()['title']} converts best ({$rated->first()['rate']} per 100 views); {$rated->last()['title']} the least ({$rated->last()['rate']})." : null,
        ]];
    }

    private function listingFacts(array $d, array $k): array
    {
        $s = $d['status_counts'];
        $a = $d['availability_counts'];
        $figures = ['live' => $k['live_listings'], 'total' => $k['total_listings'], 'pending_review' => $s['pending'], 'drafts' => $s['draft'], 'rejected' => $s['rejected'],
            'need_availability_confirmation' => $d['attention']['needs_confirmation'], 'reserved' => $a['reserved'], 'rented' => $a['rented'], 'sold' => $a['sold']];

        return ['figures' => $k['total_listings'] === 0 ? [] : $figures, 'plain' => [
            "{$k['live_listings']} of your {$k['total_listings']} listings are live; {$s['pending']} are waiting for review.",
            $d['attention']['needs_confirmation'] > 0 ? "{$d['attention']['needs_confirmation']} need you to confirm they are still available." : null,
            $s['draft'] > 0 ? "{$s['draft']} drafts have not been submitted yet." : null,
        ]];
    }

    /**
     * @return array{figures: array<string, mixed>, plain: list<string|null>}
     */
    private function adminFacts(string $metric, array $d): array
    {
        $k = $d['kpis'];
        $days = $d['days'];
        $weekly = fn (string $key) => collect($d[$key]);

        switch ($metric) {
            case 'users':
                $roles = $d['role_counts'];
                $figures = ['users' => $k['users'], "new_users_last_{$days}_days" => $k['new_users'], 'buyers' => $roles['buyer'], 'owners' => $roles['owner'], 'brokers' => $roles['broker'],
                    'new_users_this_week' => (int) ($weekly('new_users_per_week')->last()['value'] ?? 0)];

                return ['figures' => $figures, 'plain' => ["{$k['users']} accounts, {$k['new_users']} new in the last {$days} days.", "{$roles['buyer']} buyers, {$roles['owner']} owners and {$roles['broker']} brokers."]];
            case 'inquiries':
                $figures = ["inquiries_last_{$days}_days" => $k['inquiries'], "inquiries_previous_{$days}_days" => $k['previous_inquiries'], 'inquiries_this_week' => (int) ($weekly('inquiries_per_week')->last()['value'] ?? 0)];

                return ['figures' => $figures, 'plain' => ["{$k['inquiries']} inquiries in the last {$days} days ({$k['previous_inquiries']} in the {$days} days before)."]];
            case 'queue':
                $oldest = $d['attention']['oldest_pending_at'] ? Carbon::parse($d['attention']['oldest_pending_at'])->diffForHumans(null, true) : null;
                $figures = ['listings_waiting' => $k['pending_listings'], 'oldest_waiting' => $oldest, 'broker_verifications_waiting' => $d['attention']['pending_verifications'], 'publisher_applications_waiting' => $d['attention']['pending_applications'], 'open_reports' => $d['attention']['open_reports']];

                return ['figures' => $figures, 'plain' => [
                    $k['pending_listings'] > 0 ? "{$k['pending_listings']} listings are waiting for review; the oldest for {$oldest}." : 'No listings are waiting for review.',
                    "{$d['attention']['pending_verifications']} broker verifications and {$d['attention']['pending_applications']} publisher applications are waiting.",
                ]];
            default:
                $weeks = $weekly('new_listings_per_week');
                $thisWeek = (int) ($weeks->last()['value'] ?? 0);
                $figures = ['live_listings' => $k['live_listings'], 'submitted_this_week' => $thisWeek, 'this_week_is_highest_of_8_weeks' => $thisWeek > 0 && $thisWeek === $weeks->max('value'),
                    'published_last_7_days' => $k['published_this_week'], 'waiting_for_review' => $k['pending_listings']];
                if ($top = collect($d['live_by_type'])->first()) {
                    $figures += ['most_common_live_type' => $top['property_type'], 'its_count' => $top['value']];
                }

                return ['figures' => $figures, 'plain' => ["{$k['live_listings']} listings are live; {$thisWeek} were submitted this week.", "{$k['pending_listings']} are waiting for review."]];
        }
    }
}

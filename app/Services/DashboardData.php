<?php

namespace App\Services;

use App\Models\BrokerVerification;
use App\Models\Inquiry;
use App\Models\Property;
use App\Models\PropertyReport;
use App\Models\PublisherApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the owner, broker and administrator dashboards. Every number
 * and ranking is computed here; the AI only puts selected figures into words.
 */
class DashboardData
{
    public const PERIODS = [7, 30, 90];

    private const WEEKS = 8;

    /** Fewest comparable listings for a price comparison to mean anything. */
    private const MIN_COMPARABLES = 3;

    /**
     * @return array<string, mixed>
     */
    public function publisher(User $user, int $days): array
    {
        [$start, $previousStart] = $this->period($days);
        $listings = $user->ownedProperties()->get(['id', 'title', 'status', 'availability', 'availability_confirmed_at', 'published_at', 'property_type', 'listing_type', 'price', 'floor_area', 'lot_area']);
        $ids = $listings->pluck('id')->all();
        $live = Property::query()->whereIn('id', $ids)->publiclyVisible()->get(['id', 'availability']);

        $inquiries = $this->publisherInquiries($user);
        $inPeriod = (clone $inquiries)->where('created_at', '>=', $start)->get(['id', 'property_id', 'status', 'created_at']);
        $previous = (clone $inquiries)->whereBetween('created_at', [$previousStart, $start])->count();
        $lastWeeks = (clone $inquiries)->where('created_at', '>=', $this->weeksStart())->get(['created_at']);

        $viewsByDay = $this->viewsByDay($ids, $start);
        $views = array_sum($viewsByDay);
        $previousViews = (int) DB::table('property_view_days')->whereIn('property_id', $ids)
            ->whereBetween('day', [$previousStart->toDateString(), $start->copy()->subDay()->toDateString()])->sum('views');
        $viewsByListing = DB::table('property_view_days')->whereIn('property_id', $ids)
            ->where('day', '>=', $start->toDateString())->groupBy('property_id')
            ->selectRaw('property_id, SUM(views) AS total')->pluck('total', 'property_id')->map(fn ($v) => (int) $v);
        $inquiriesByListing = $inPeriod->countBy('property_id');

        $byListing = $listings->map(fn (Property $p): array => [
            'id' => $p->id,
            'title' => $p->title,
            'views' => (int) ($viewsByListing[$p->id] ?? 0),
            'inquiries' => (int) ($inquiriesByListing[$p->id] ?? 0),
        ])->sortBy([['views', 'desc'], ['inquiries', 'desc'], ['title', 'asc']])->values()->all();

        return [
            'days' => $days,
            'attention' => [
                'unanswered_inquiries' => (clone $inquiries)->where('status', 'new')->count(),
                'needs_confirmation' => Property::query()->whereIn('id', $ids)->onMarket()
                    ->where('availability_confirmed_at', '<=', now()->subDays(Property::REMIND_AFTER_DAYS))->count(),
                'drafts' => $listings->where('status', 'draft')->count(),
                'rejected' => $listings->where('status', 'rejected')->count(),
            ],
            'kpis' => [
                'live_listings' => $live->count(),
                'total_listings' => $listings->count(),
                'pending_listings' => $listings->where('status', 'pending')->count(),
                'inquiries' => $inPeriod->count(),
                'previous_inquiries' => $previous,
                'inquiries_this_week' => $inPeriod->where('created_at', '>=', now()->subDays(7))->count(),
                'views' => $views,
                'previous_views' => $previousViews,
                'inquiries_per_100_views' => $views > 0 ? round($inPeriod->count() / $views * 100, 1) : null,
            ],
            'inquiries_per_week' => $this->perWeek($lastWeeks->pluck('created_at')),
            'views_per_day' => collect($viewsByDay)->map(fn (int $v, string $day): array => ['day' => $day, 'value' => $v])->values()->all(),
            'by_listing' => $byListing,
            'status_counts' => $this->counts($listings->pluck('status'), ['published', 'pending', 'draft', 'rejected']),
            'availability_counts' => $this->counts($live->pluck('availability'), Property::AVAILABILITY_OPTIONS),
            'price_vs_market' => $this->priceVsMarket($listings->whereIn('id', $live->pluck('id'))),
            'credentials' => $user->role === 'broker' ? $this->credentials($user) : null,
        ];
    }

    /**
     * Market-wide figures shown to every workspace.
     *
     * @return array<string, mixed>
     */
    public function market(int $days): array
    {
        [$start] = $this->period($days);
        $live = Property::query()->onMarket()->get(['id', 'property_type', 'listing_type', 'price', 'floor_area', 'barangay', 'published_at']);
        $periodInquiries = Inquiry::query()->where('created_at', '>=', $start)
            ->with('property:id,property_type,barangay')->get(['id', 'property_id']);

        $byType = $live->groupBy('property_type');
        $inquiriesByType = $periodInquiries->filter(fn (Inquiry $i) => $i->property)->countBy(fn (Inquiry $i) => $i->property->property_type);
        $topBarangays = $live->filter(fn (Property $p) => filled($p->barangay))->countBy('barangay')
            ->sortDesc()->take(6);
        $inquiriesByBarangay = $periodInquiries->filter(fn (Inquiry $i) => $i->property && filled($i->property->barangay))
            ->countBy(fn (Inquiry $i) => $i->property->barangay);

        return [
            'days' => $days,
            'live_listings' => $live->count(),
            'median_rent_per_sqm' => $byType->map(function (Collection $group, string $type) {
                $perSqm = $group->where('listing_type', 'rent')->filter(fn (Property $p) => (float) $p->floor_area > 0)
                    ->map(fn (Property $p) => (float) $p->price / (float) $p->floor_area);

                return $perSqm->isEmpty() ? null : ['property_type' => $type, 'value' => (int) round($this->median($perSqm)), 'listings' => $perSqm->count()];
            })->filter()->sortByDesc('value')->values()->all(),
            'inquiries_per_listing_by_type' => $byType->map(fn (Collection $group, string $type) => [
                'property_type' => $type,
                'value' => round(($inquiriesByType[$type] ?? 0) / $group->count(), 1),
                'listings' => $group->count(),
            ])->sortByDesc('value')->values()->all(),
            'listings_by_barangay' => $topBarangays->map(fn (int $n, string $b) => ['barangay' => $b, 'value' => $n])->values()->all(),
            'inquiries_by_barangay' => $topBarangays->keys()->map(fn (string $b) => ['barangay' => $b, 'value' => (int) ($inquiriesByBarangay[$b] ?? 0)])->values()->all(),
            'new_listings_per_week' => $this->perWeek(Property::query()->whereNotNull('published_at')
                ->where('published_at', '>=', $this->weeksStart())->pluck('published_at')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function admin(int $days): array
    {
        [$start, $previousStart] = $this->period($days);
        $pending = Property::query()->where('status', 'pending')->with('owner:id,name,role')
            ->orderBy('updated_at')->get(['id', 'title', 'owner_id', 'property_type', 'listing_type', 'updated_at']);
        $live = Property::query()->publiclyVisible()->get(['id', 'availability', 'property_type']);

        return [
            'days' => $days,
            'attention' => [
                'pending_listings' => $pending->count(),
                'oldest_pending_at' => $pending->first()?->updated_at?->toIso8601String(),
                'pending_verifications' => BrokerVerification::where('status', 'pending')->count(),
                'pending_applications' => PublisherApplication::where('status', 'pending')->count(),
                'open_reports' => PropertyReport::where('status', 'open')->count(),
                'dhsud_checks_due' => BrokerVerification::where('status', 'verified')
                    ->where(fn (Builder $q) => $q->whereNull('dhsud_checked_at')
                        ->orWhere('dhsud_checked_at', '<=', now()->subDays(BrokerVerification::DHSUD_CHECK_INTERVAL_DAYS)))->count(),
            ],
            'kpis' => [
                'live_listings' => $live->count(),
                'published_this_week' => Property::query()->where('published_at', '>=', now()->subDays(7))->count(),
                'users' => User::count(),
                'new_users' => User::where('created_at', '>=', $start)->count(),
                'inquiries' => Inquiry::where('created_at', '>=', $start)->count(),
                'previous_inquiries' => Inquiry::whereBetween('created_at', [$previousStart, $start])->count(),
                'pending_listings' => $pending->count(),
            ],
            'new_listings_per_week' => $this->perWeek(Property::query()->where('created_at', '>=', $this->weeksStart())->pluck('created_at')),
            'new_users_per_week' => $this->perWeek(User::query()->where('created_at', '>=', $this->weeksStart())->pluck('created_at')),
            'inquiries_per_week' => $this->perWeek(Inquiry::query()->where('created_at', '>=', $this->weeksStart())->pluck('created_at')),
            'status_counts' => $this->counts(Property::query()->pluck('status'), ['published', 'pending', 'draft', 'rejected']),
            'availability_counts' => $this->counts($live->pluck('availability'), Property::AVAILABILITY_OPTIONS),
            'role_counts' => $this->counts(User::query()->pluck('role'), ['buyer', 'owner', 'broker', 'admin']),
            'live_by_type' => $live->countBy('property_type')->sortDesc()->map(fn (int $n, string $t) => ['property_type' => $t, 'value' => $n])->values()->all(),
            'review_queue' => $pending->take(5)->map(fn (Property $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'publisher' => $p->owner?->name,
                'publisher_role' => $p->owner?->role,
                'property_type' => $p->property_type,
                'listing_type' => $p->listing_type,
                'waiting_since' => $p->updated_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Inquiries a publisher handles: on listings they own, or assigned to them as broker.
     */
    public function publisherInquiries(User $user): Builder
    {
        return $user->role === 'broker'
            ? Inquiry::query()->where('assigned_broker_id', $user->id)
            : Inquiry::query()->whereHas('property', fn (Builder $q) => $q->where('owner_id', $user->id));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(int $days): array
    {
        $start = today()->subDays($days - 1);

        return [$start, $start->copy()->subDays($days)];
    }

    private function weeksStart(): Carbon
    {
        return today()->startOfWeek()->subWeeks(self::WEEKS - 1);
    }

    /**
     * Counts per week (weeks start on Monday) for the last WEEKS weeks, oldest first.
     *
     * @param  Collection<int, mixed>  $dates
     * @return list<array{week: string, value: int}>
     */
    private function perWeek(Collection $dates): array
    {
        $counts = $dates->filter()->countBy(fn ($d) => Carbon::parse($d)->startOfWeek()->toDateString());
        $out = [];
        for ($i = 0; $i < self::WEEKS; $i++) {
            $week = $this->weeksStart()->addWeeks($i)->toDateString();
            $out[] = ['week' => $week, 'value' => (int) ($counts[$week] ?? 0)];
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, int> day => views, every day of the period
     */
    private function viewsByDay(array $ids, Carbon $start): array
    {
        $rows = $ids === [] ? collect() : DB::table('property_view_days')->whereIn('property_id', $ids)
            ->where('day', '>=', $start->toDateString())->groupBy('day')
            ->selectRaw('day, SUM(views) AS total')->pluck('total', 'day');
        $days = [];
        for ($d = $start->copy(); $d->lte(today()); $d->addDay()) {
            $key = $d->toDateString();
            $days[$key] = (int) ($rows[$key] ?? 0);
        }

        return $days;
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function counts(Collection $values, array $keys): array
    {
        $counts = $values->countBy();

        return collect($keys)->mapWithKeys(fn (string $k) => [$k => (int) ($counts[$k] ?? 0)])->all();
    }

    /**
     * Each live rental or lease listing's asking price per sqm against live
     * listings of the same property type and listing type.
     *
     * @param  Collection<int, Property>  $mine
     * @return list<array<string, mixed>>
     */
    private function priceVsMarket(Collection $mine): array
    {
        $market = Property::query()->onMarket()->whereIn('listing_type', ['rent', 'lease'])
            ->where('floor_area', '>', 0)->get(['id', 'property_type', 'listing_type', 'price', 'floor_area']);

        return $mine->filter(fn (Property $p) => in_array($p->listing_type, ['rent', 'lease'], true) && (float) $p->floor_area > 0)
            ->map(function (Property $p) use ($market): ?array {
                $peers = $market->where('property_type', $p->property_type)->where('listing_type', $p->listing_type)
                    ->map(fn (Property $m) => (float) $m->price / (float) $m->floor_area)->values();
                if ($peers->count() < self::MIN_COMPARABLES) {
                    return null;
                }

                return [
                    'id' => $p->id,
                    'title' => $p->title,
                    'property_type' => $p->property_type,
                    'listing_type' => $p->listing_type,
                    'value' => (int) round((float) $p->price / (float) $p->floor_area),
                    'min' => (int) round($peers->min()),
                    'median' => (int) round($this->median($peers)),
                    'max' => (int) round($peers->max()),
                    'comparables' => $peers->count(),
                ];
            })->filter()->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function credentials(User $user): ?array
    {
        $v = $user->brokerVerifications()->first();
        if (! $v) {
            return null;
        }

        return [
            'status' => $v->status,
            'credential_status' => $v->credential_status,
            'license_number' => $v->license_number,
            'prc_valid_until' => $v->prc_valid_until?->toDateString(),
            'prc_days_left' => $v->prc_valid_until ? (int) today()->diffInDays($v->prc_valid_until, false) : null,
            'dhsud_registration_number' => $v->dhsud_registration_number,
            'dhsud_valid_until' => $v->dhsud_valid_until?->toDateString(),
            'dhsud_days_left' => $v->dhsud_valid_until ? (int) today()->diffInDays($v->dhsud_valid_until, false) : null,
            'firm_name' => $v->firm_name,
            'affiliations' => $v->affiliations ?? [],
        ];
    }

    /**
     * @param  Collection<int, float>  $values
     */
    private function median(Collection $values): float
    {
        $sorted = $values->sort()->values();
        $n = $sorted->count();

        return $n % 2 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2;
    }
}

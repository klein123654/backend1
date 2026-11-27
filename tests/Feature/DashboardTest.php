<?php

namespace Tests\Feature;

use App\Models\BrokerVerification;
use App\Models\Inquiry;
use App\Models\Property;
use App\Models\PropertyReport;
use App\Services\DeepSeekService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesListings;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use MakesListings, RefreshDatabase;

    private function inquiry(Property $listing, array $attributes = []): Inquiry
    {
        $buyer = $this->makeUser('buyer');

        return Inquiry::create([
            'property_id' => $listing->id, 'buyer_id' => $buyer->id, 'assigned_broker_id' => $listing->broker_id,
            'name' => $buyer->name, 'email' => $buyer->email, 'message' => 'Is this still available?',
            'status' => 'new', 'ai_priority' => 'unscored', ...$attributes,
        ]);
    }

    private function views(Property $listing, int $daysAgo, int $views): void
    {
        DB::table('property_view_days')->insert(['property_id' => $listing->id, 'day' => today()->subDays($daysAgo)->toDateString(), 'views' => $views]);
    }

    public function test_owner_dashboard_counts_listings_inquiries_and_views_for_the_period(): void
    {
        $owner = $this->makeUser('owner');
        $warehouse = $this->makeListing($owner, ['title' => 'Bancasi warehouse', 'floor_area' => 900, 'price' => 90000]);
        $this->makeListing($owner, ['title' => 'Draft unit', 'status' => 'draft', 'published_at' => null]);
        $this->makeListing($owner, ['title' => 'Reserved shop', 'availability' => 'reserved']);
        $this->inquiry($warehouse);
        $this->inquiry($warehouse, ['status' => 'resolved']);
        $old = $this->inquiry($warehouse);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        $this->views($warehouse, 0, 30);
        $this->views($warehouse, 10, 20);
        $this->views($warehouse, 45, 25);
        $this->inquiry($this->makeListing($this->makeUser('owner')));
        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/my/dashboard?days=30')->assertOk();

        $response->assertJsonPath('data.kpis.live_listings', 2)
            ->assertJsonPath('data.kpis.total_listings', 3)
            ->assertJsonPath('data.kpis.inquiries', 2)
            ->assertJsonPath('data.kpis.previous_inquiries', 1)
            ->assertJsonPath('data.kpis.views', 50)
            ->assertJsonPath('data.kpis.previous_views', 25)
            ->assertJsonPath('data.kpis.inquiries_per_100_views', 4)
            ->assertJsonPath('data.attention.unanswered_inquiries', 2)
            ->assertJsonPath('data.attention.drafts', 1)
            ->assertJsonPath('data.status_counts.draft', 1)
            ->assertJsonPath('data.availability_counts.reserved', 1)
            ->assertJsonPath('data.by_listing.0.title', 'Bancasi warehouse')
            ->assertJsonPath('data.by_listing.0.views', 50)
            ->assertJsonCount(8, 'data.inquiries_per_week')
            ->assertJsonCount(30, 'data.views_per_day')
            ->assertJsonPath('data.credentials', null);
    }

    public function test_price_comparison_needs_three_comparable_listings(): void
    {
        $owner = $this->makeUser('owner');
        $mine = $this->makeListing($owner, ['title' => 'My warehouse', 'floor_area' => 1000, 'price' => 100000]);
        $other = $this->makeUser('owner');
        $this->makeListing($other, ['floor_area' => 1000, 'price' => 80000]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/my/dashboard')->assertJsonPath('data.price_vs_market', []);

        $this->makeListing($other, ['floor_area' => 1000, 'price' => 140000]);
        $this->getJson('/api/my/dashboard')
            ->assertJsonPath('data.price_vs_market.0.title', 'My warehouse')
            ->assertJsonPath('data.price_vs_market.0.value', 100)
            ->assertJsonPath('data.price_vs_market.0.min', 80)
            ->assertJsonPath('data.price_vs_market.0.median', 100)
            ->assertJsonPath('data.price_vs_market.0.max', 140)
            ->assertJsonPath('data.price_vs_market.0.comparables', 3);
        $this->assertSame(1, $mine->owner_id === $owner->id ? 1 : 0);
    }

    public function test_broker_dashboard_includes_credential_dates_and_assigned_inquiries(): void
    {
        $broker = $this->makeUser('broker');
        BrokerVerification::create([
            'broker_id' => $broker->id, 'status' => 'verified', 'license_number' => 'PRC-1',
            'prc_valid_until' => today()->addDays(400)->toDateString(), 'dhsud_registration_number' => 'D-1',
            'dhsud_valid_until' => today()->addDays(20)->toDateString(), 'firm_name' => 'Agusan Prime Realty',
        ]);
        $this->inquiry($this->makeListing($broker));
        Sanctum::actingAs($broker);

        $this->getJson('/api/my/dashboard')->assertOk()
            ->assertJsonPath('data.kpis.inquiries', 1)
            ->assertJsonPath('data.credentials.dhsud_days_left', 20)
            ->assertJsonPath('data.credentials.credential_status', 'expiring')
            ->assertJsonPath('data.credentials.firm_name', 'Agusan Prime Realty');
    }

    public function test_buyers_cannot_open_publisher_dashboards_and_periods_are_limited(): void
    {
        Sanctum::actingAs($this->makeUser('buyer'));
        $this->getJson('/api/my/dashboard')->assertForbidden();

        Sanctum::actingAs($this->makeUser('owner'));
        $this->getJson('/api/my/dashboard?days=45')->assertUnprocessable();
    }

    public function test_market_figures_cover_rent_medians_demand_and_barangays(): void
    {
        $owner = $this->makeUser('owner');
        $a = $this->makeListing($owner, ['property_type' => 'Retail Spaces', 'floor_area' => 100, 'price' => 40000, 'barangay' => 'Libertad']);
        $this->makeListing($owner, ['property_type' => 'Retail Spaces', 'floor_area' => 100, 'price' => 46000, 'barangay' => 'Libertad']);
        $this->makeListing($owner, ['property_type' => 'Warehouses', 'floor_area' => 1000, 'price' => 100000, 'barangay' => 'Bancasi']);
        $this->inquiry($a);
        $this->inquiry($a);
        Sanctum::actingAs($owner);

        $this->getJson('/api/dashboard/market')->assertOk()
            ->assertJsonPath('data.median_rent_per_sqm.0.property_type', 'Retail Spaces')
            ->assertJsonPath('data.median_rent_per_sqm.0.value', 430)
            ->assertJsonPath('data.inquiries_per_listing_by_type.0.property_type', 'Retail Spaces')
            ->assertJsonPath('data.inquiries_per_listing_by_type.0.value', 1)
            ->assertJsonPath('data.listings_by_barangay.0.barangay', 'Libertad')
            ->assertJsonPath('data.inquiries_by_barangay.0.value', 2)
            ->assertJsonCount(8, 'data.new_listings_per_week');
    }

    public function test_admin_overview_lists_what_is_waiting_oldest_first(): void
    {
        $owner = $this->makeUser('owner');
        $newer = $this->makeListing($owner, ['title' => 'Newer', 'status' => 'pending', 'published_at' => null]);
        $older = $this->makeListing($owner, ['title' => 'Older', 'status' => 'pending', 'published_at' => null]);
        Property::whereKey($older->id)->update(['updated_at' => now()->subDays(2)]);
        PropertyReport::create(['property_id' => $newer->id, 'reason' => 'Wrong price', 'status' => 'open']);
        Sanctum::actingAs($this->makeUser('admin'));

        $this->getJson('/api/admin/dashboard/overview')->assertOk()
            ->assertJsonPath('data.attention.pending_listings', 2)
            ->assertJsonPath('data.attention.open_reports', 1)
            ->assertJsonPath('data.review_queue.0.title', 'Older')
            ->assertJsonPath('data.role_counts.owner', 1)
            ->assertJsonCount(8, 'data.new_users_per_week');

        Sanctum::actingAs($owner);
        $this->getJson('/api/admin/dashboard/overview')->assertForbidden();
    }

    public function test_card_insight_falls_back_to_plain_points_when_ai_is_unavailable(): void
    {
        config(['services.deepseek.api_key' => null]);
        $owner = $this->makeUser('owner');
        $listing = $this->makeListing($owner, ['title' => 'Bancasi warehouse']);
        $this->inquiry($listing);
        Sanctum::actingAs($owner);

        $this->postJson('/api/my/dashboard/insight', ['metric' => 'inquiries'])->assertOk()
            ->assertJsonPath('data.ai_written', false)
            ->assertJsonPath('data.bullets.0', '1 inquiries in the last 30 days (0 in the 30 days before).')
            ->assertJsonPath('data.bullets.1', 'Bancasi warehouse received the most (1).');

        $this->postJson('/api/my/dashboard/insight', ['metric' => 'profit'])->assertUnprocessable();
    }

    public function test_card_insight_is_worded_by_ai_from_computed_figures_and_cached(): void
    {
        $owner = $this->makeUser('owner');
        $this->inquiry($this->makeListing($owner, ['title' => 'Bancasi warehouse']));
        $this->mock(DeepSeekService::class)->shouldReceive('dashboardInsight')->once()
            ->withArgs(fn (array $figures, string $audience) => $figures['inquiries_last_30_days'] === 1
                && $figures['listing_with_most_inquiries'] === 'Bancasi warehouse' && $audience === 'property publisher')
            ->andReturn(['success' => true, 'bullets' => ['1 inquiry this month, all for the Bancasi warehouse.']]);
        Sanctum::actingAs($owner);

        $this->postJson('/api/my/dashboard/insight', ['metric' => 'inquiries'])->assertJsonPath('data.ai_written', true)
            ->assertJsonPath('data.bullets.0', '1 inquiry this month, all for the Bancasi warehouse.');
        $this->postJson('/api/my/dashboard/insight', ['metric' => 'inquiries'])->assertJsonPath('data.ai_written', true);
    }

    public function test_admin_card_insight_uses_site_figures(): void
    {
        config(['services.deepseek.api_key' => null]);
        Sanctum::actingAs($this->makeUser('admin'));

        $this->postJson('/api/admin/dashboard/insight', ['metric' => 'users'])->assertOk()
            ->assertJsonPath('data.bullets.0', '1 accounts, 1 new in the last 30 days.');
        $this->postJson('/api/admin/dashboard/insight', ['metric' => 'views'])->assertUnprocessable();
    }
}

<?php

namespace Tests\Feature;

use App\Services\DeepSeekService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesListings;
use Tests\TestCase;

class AiDescriptionApiTest extends TestCase
{
    use MakesListings, RefreshDatabase;

    private function details(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Ground-floor office along J.C. Aquino Avenue',
            'size' => 120,
            'location' => 'J.C. Aquino Avenue, Imadejas Pob., Butuan City, Agusan del Norte',
            'price' => 45000,
            'type' => 'Office Spaces',
            'listing_type' => 'For Rent',
            'features' => '2 parking slots, fiber internet ready',
        ], $overrides);
    }

    public function test_returns_a_friendly_http_200_response_when_deepseek_is_not_configured(): void
    {
        config(['services.deepseek.api_key' => null]);

        $this->postJson('/api/ai/describe', $this->details())
            ->assertOk()
            ->assertExactJson([
                'success' => false,
                'message' => 'AI features are not available right now.',
            ]);
    }

    public function test_the_ai_gets_labelled_price_area_and_the_publishers_key_features(): void
    {
        $this->mock(DeepSeekService::class)->shouldReceive('describe')->once()
            ->withArgs(function (array $facts, array $options): bool {
                return $facts['price'] === '₱45,000 per month'
                    && $facts['area'] === '120 sqm floor area'
                    && $facts['price_per_sqm'] === '₱375 per sqm per month'
                    && $facts['key_features'] === '2 parking slots, fiber internet ready'
                    && $options === ['style' => 'shorter', 'language' => 'ceb', 'current_description' => null];
            })
            ->andReturn(['success' => true, 'description' => 'A 120 sqm office with 2 parking slots for ₱45,000 per month.']);

        $this->postJson('/api/ai/describe', $this->details(['style' => 'shorter', 'language' => 'ceb']))
            ->assertOk()
            ->assertJsonPath('description', 'A 120 sqm office with 2 parking slots for ₱45,000 per month.')
            ->assertJsonPath('unsupported_numbers', []);
    }

    public function test_sale_lots_are_labelled_as_total_price_and_lot_area(): void
    {
        $this->mock(DeepSeekService::class)->shouldReceive('describe')->once()
            ->withArgs(fn (array $facts): bool => $facts['price'] === '₱18,500,000 total asking price'
                && $facts['area'] === '2,400 sqm lot area')
            ->andReturn(['success' => true, 'description' => 'A lot for ₱18.5 million.']);

        $this->postJson('/api/ai/describe', $this->details(['type' => 'Commercial Lots', 'listing_type' => 'For Sale', 'price' => 18500000, 'size' => 2400, 'features' => null]))
            ->assertOk()
            ->assertJsonPath('unsupported_numbers', []);
    }

    public function test_numbers_the_publisher_never_gave_are_flagged(): void
    {
        $this->mock(DeepSeekService::class)->shouldReceive('describe')->once()
            ->andReturn(['success' => true, 'description' => 'A 120 sqm office with 15 parking slots, 300 meters from the mall.']);

        $this->postJson('/api/ai/describe', $this->details())
            ->assertOk()
            ->assertJsonPath('unsupported_numbers', ['15', '300']);
    }

    public function test_polishing_sends_the_publishers_own_text(): void
    {
        $this->mock(DeepSeekService::class)->shouldReceive('describe')->once()
            ->withArgs(fn (array $facts, array $options): bool => $options['current_description'] === 'nice office near city hall 3 rooms')
            ->andReturn(['success' => true, 'description' => 'A well-kept office near city hall with 3 rooms.']);

        $this->postJson('/api/ai/describe', $this->details(['current_description' => 'nice office near city hall 3 rooms']))
            ->assertOk()
            ->assertJsonPath('unsupported_numbers', []);
    }

    public function test_unknown_style_or_language_is_rejected(): void
    {
        $this->postJson('/api/ai/describe', $this->details(['style' => 'poem']))->assertUnprocessable()->assertJsonValidationErrors('style');
        $this->postJson('/api/ai/describe', $this->details(['language' => 'fr']))->assertUnprocessable()->assertJsonValidationErrors('language');
    }

    public function test_title_suggestions_use_the_same_facts(): void
    {
        $this->mock(DeepSeekService::class)->shouldReceive('suggestTitles')->once()
            ->withArgs(fn (array $facts): bool => $facts['property_type'] === 'Office Spaces' && $facts['key_features'] === '2 parking slots, fiber internet ready')
            ->andReturn(['success' => true, 'titles' => ['Office with Parking on J.C. Aquino Ave', 'Fiber-Ready Office in Imadejas']]);

        $this->postJson('/api/ai/suggest-titles', $this->details())
            ->assertOk()
            ->assertJsonPath('titles.1', 'Fiber-Ready Office in Imadejas');
    }

    public function test_title_suggestions_are_friendly_when_ai_is_not_configured(): void
    {
        config(['services.deepseek.api_key' => null]);

        $this->postJson('/api/ai/suggest-titles', $this->details())
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('titles', []);
    }

    public function test_editing_the_text_refreshes_the_ai_tags(): void
    {
        $owner = $this->makeUser('owner');
        $listing = $this->makeListing($owner, ['status' => 'draft', 'ai_tags' => ['old tag', 'stale', 'outdated']]);
        $this->mock(DeepSeekService::class)->shouldReceive('tagProperty')->once()
            ->with('Corner office near port', \Mockery::any())
            ->andReturn(['success' => true, 'tags' => ['near port', 'corner unit', 'office']]);
        Sanctum::actingAs($owner);

        $this->patchJson('/api/my/properties/'.$listing->id, ['title' => 'Corner office near port'])->assertOk();

        $this->assertSame(['near port', 'corner unit', 'office'], $listing->fresh()->ai_tags);
    }

    public function test_price_only_edits_keep_the_tags_without_calling_the_ai(): void
    {
        $owner = $this->makeUser('owner');
        $listing = $this->makeListing($owner, ['status' => 'draft', 'ai_tags' => ['near port', 'office', 'parking']]);
        $this->mock(DeepSeekService::class)->shouldNotReceive('tagProperty');
        Sanctum::actingAs($owner);

        $this->patchJson('/api/my/properties/'.$listing->id, ['price' => 99000])->assertOk();

        $this->assertSame(['near port', 'office', 'parking'], $listing->fresh()->ai_tags);
    }
}

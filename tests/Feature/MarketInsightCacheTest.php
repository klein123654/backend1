<?php

namespace Tests\Feature;

use App\Services\DeepSeekService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketInsightCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_insight_is_reused_instead_of_calling_ai_again(): void
    {
        $this->mock(DeepSeekService::class)
            ->shouldReceive('marketInsight')
            ->once()
            ->andReturn(['success' => true, 'summary' => 'Cached summary.']);

        $this->getJson('/api/ai/market-insight')->assertOk()->assertJsonPath('summary', 'Cached summary.');
        $this->getJson('/api/ai/market-insight')->assertOk()->assertJsonPath('summary', 'Cached summary.');
    }

    public function test_failed_insight_is_not_cached(): void
    {
        $this->mock(DeepSeekService::class)
            ->shouldReceive('marketInsight')
            ->twice()
            ->andReturn(['success' => false, 'summary' => null, 'message' => 'AI service error. Please try again.']);

        $this->getJson('/api/ai/market-insight')->assertOk()->assertJsonPath('success', false);
        $this->getJson('/api/ai/market-insight')->assertOk()->assertJsonPath('success', false);
    }
}

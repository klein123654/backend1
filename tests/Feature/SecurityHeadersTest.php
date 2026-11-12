<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_carry_security_headers(): void
    {
        $response = $this->getJson('/api/barangays')->assertOk();

        foreach (SecurityHeaders::HEADERS as $name => $value) {
            $response->assertHeader($name, $value);
        }
        $response->assertHeaderMissing('X-Powered-By');
    }

    public function test_root_returns_json_without_cookies(): void
    {
        $response = $this->get('/')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertHeader('Content-Security-Policy', SecurityHeaders::HEADERS['Content-Security-Policy']);

        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_health_check_carries_security_headers(): void
    {
        $this->get('/up')->assertOk()->assertHeader('X-Frame-Options', 'DENY');
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    public function test_buyer_receives_403_from_admin_dashboard(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'buyer',
            'account_status' => 'active',
        ]));

        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }

    public function test_active_administrator_can_view_admin_dashboard(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
        ]));

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'users',
                'listings',
                'pending_listings',
                'open_inquiries',
                'open_reports',
                'pending_verifications',
            ]]);
    }
}

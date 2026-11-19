<?php

namespace Tests\Feature;

use App\Models\PublisherApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeveloperRoleRemovalTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => 'Test '.Str::random(6),
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('TestPassword123!'),
            'role' => $role,
            'account_status' => 'active',
        ]);
    }

    public function test_developer_applications_are_refused(): void
    {
        Sanctum::actingAs($this->makeUser('buyer'));

        $this->postJson('/api/my/publisher-application', ['requested_role' => 'developer', 'business_name' => 'Agusan Land'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('requested_role');
    }

    public function test_owners_may_give_a_registered_business_name(): void
    {
        Sanctum::actingAs($this->makeUser('buyer'));

        $this->postJson('/api/my/publisher-application', [
            'requested_role' => 'owner',
            'ownership_type' => 'company',
            'business_name' => 'Agusan Land Development Corp.',
            'license_number' => 'ignored-for-owners',
        ])
            ->assertCreated()
            ->assertJsonPath('data.business_name', 'Agusan Land Development Corp.')
            ->assertJsonPath('data.license_number', null);
    }

    public function test_a_leftover_developer_account_cannot_publish(): void
    {
        $user = $this->makeUser('buyer');
        DB::table('users')->where('id', $user->id)->update(['role' => 'developer']);
        Sanctum::actingAs($user->fresh());

        $this->postJson('/api/my/properties', [
            'title' => 'Lot', 'property_type' => 'lot', 'listing_type' => 'sale',
            'description' => 'Commercial lot.', 'price' => 100, 'address_line' => 'Ampayon',
            'city' => 'Butuan City', 'province' => 'Agusan del Norte',
        ])->assertForbidden();
    }

    public function test_migration_converts_developer_accounts_and_applications_to_owner(): void
    {
        $user = $this->makeUser('buyer');
        DB::table('users')->where('id', $user->id)->update(['role' => 'developer']);
        $application = PublisherApplication::create(['user_id' => $user->id, 'requested_role' => 'developer', 'status' => 'pending']);

        $migration = require database_path('migrations/2026_11_19_090000_convert_developer_accounts_to_owner.php');
        $migration->up();

        $this->assertSame('owner', $user->fresh()->role);
        $this->assertSame('owner', $application->fresh()->requested_role);
        $this->assertSame('company', $application->fresh()->ownership_type);
    }
}

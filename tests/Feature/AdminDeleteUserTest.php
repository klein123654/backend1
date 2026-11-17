<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\User;
use App\Notifications\Account\AccountRemovedByAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesListings;
use Tests\TestCase;

class AdminDeleteUserTest extends TestCase
{
    use MakesListings, RefreshDatabase;

    public function test_admin_can_delete_an_account_with_its_listings(): void
    {
        Notification::fake();
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('owner');
        $listing = $this->makeListing($owner);
        $buyer = $this->makeUser('buyer');
        $sent = Inquiry::create([
            'property_id' => $this->makeListing($this->makeUser('owner'))->id, 'buyer_id' => $buyer->id,
            'name' => $buyer->name, 'email' => $buyer->email, 'message' => 'Is this still available?', 'status' => 'new',
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$owner->id}", ['password' => 'CurrentPass123!'])
            ->assertOk()->assertJsonPath('message', "The account for {$owner->name} was deleted.");
        $this->assertModelMissing($owner);
        $this->assertModelMissing($listing);

        $this->deleteJson("/api/admin/users/{$buyer->id}", ['password' => 'CurrentPass123!'])->assertOk();
        $this->assertSame('Deleted account', $sent->fresh()->name, 'Messages the person sent are anonymised, not removed');
        Notification::assertNothingSent();
    }

    public function test_admin_must_confirm_with_their_own_password(): void
    {
        $user = $this->makeUser('buyer');
        Sanctum::actingAs($this->makeUser('admin'));

        $this->deleteJson("/api/admin/users/{$user->id}", ['password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertModelExists($user);
    }

    public function test_admin_accounts_cannot_be_deleted(): void
    {
        $admin = $this->makeUser('admin');
        $otherAdmin = $this->makeUser('admin');
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$otherAdmin->id}", ['password' => 'CurrentPass123!'])->assertForbidden();
        $this->deleteJson("/api/admin/users/{$admin->id}", ['password' => 'CurrentPass123!'])->assertForbidden();
        $this->assertSame(2, User::where('role', 'admin')->count());
    }

    public function test_only_admins_can_delete_accounts(): void
    {
        $victim = $this->makeUser('buyer');
        Sanctum::actingAs($this->makeUser('owner'));

        $this->deleteJson("/api/admin/users/{$victim->id}", ['password' => 'CurrentPass123!'])->assertForbidden();
        $this->assertModelExists($victim);
    }

    public function test_the_person_can_be_emailed_with_a_reason(): void
    {
        Notification::fake();
        $user = $this->makeUser('broker', ['email' => 'spammer@example.test']);
        Sanctum::actingAs($this->makeUser('admin'));

        $this->deleteJson("/api/admin/users/{$user->id}", [
            'password' => 'CurrentPass123!', 'reason' => 'Fake listings', 'notify_user' => true,
        ])->assertOk();

        Notification::assertSentOnDemand(AccountRemovedByAdmin::class, function (AccountRemovedByAdmin $notification, array $channels, AnonymousNotifiable $notifiable) {
            return $notifiable->routes['mail'] === 'spammer@example.test' && $notification->reason === 'Fake listings';
        });
    }
}

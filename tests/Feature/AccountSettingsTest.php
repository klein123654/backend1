<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AccountController;
use App\Models\Inquiry;
use App\Models\User;
use App\Notifications\Account\AccountSecurityNotice;
use App\Notifications\Account\ConfirmEmailChange;
use App\Notifications\Account\VerifyEmailAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesListings;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use MakesListings, RefreshDatabase;

    public function test_user_can_update_name_and_phone(): void
    {
        Sanctum::actingAs($user = $this->makeUser());

        $this->patchJson('/api/user', ['name' => 'New Name', 'phone' => '09170000000'])
            ->assertOk()
            ->assertJsonPath('user.name', 'New Name')
            ->assertJsonPath('user.phone', '09170000000')
            ->assertJsonPath('user.email_verified', false);
    }

    public function test_password_change_requires_current_password(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->putJson('/api/user/password', [
            'current_password' => 'wrong-password',
            'password' => 'BrandNewPass1!',
            'password_confirmation' => 'BrandNewPass1!',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
    }

    public function test_password_change_signs_out_other_devices_and_sends_notice(): void
    {
        Notification::fake();
        $user = $this->makeUser();
        $user->createToken('laptop');
        $current = $user->createToken('phone')->plainTextToken;

        $this->withToken($current)->putJson('/api/user/password', [
            'current_password' => 'CurrentPass123!',
            'password' => 'BrandNewPass1!',
            'password_confirmation' => 'BrandNewPass1!',
        ])->assertOk();

        $this->assertTrue(Hash::check('BrandNewPass1!', $user->fresh()->password));
        $this->assertSame(['phone'], $user->tokens()->pluck('name')->all());
        Notification::assertSentTo($user, AccountSecurityNotice::class);
    }

    public function test_email_change_is_confirmed_through_a_link_sent_to_the_new_address(): void
    {
        Notification::fake();
        Sanctum::actingAs($user = $this->makeUser());
        $oldEmail = $user->email;

        $this->postJson('/api/user/email', ['email' => 'new@example.test', 'password' => 'CurrentPass123!'])
            ->assertOk();

        $this->assertSame($oldEmail, $user->fresh()->email, 'Email must not change before confirmation');
        Notification::assertSentOnDemand(ConfirmEmailChange::class, fn ($n, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'new@example.test');

        $link = URL::temporarySignedRoute('account.confirm-email-change', now()->addHour(), ['user' => $user->id, 'email' => 'new@example.test']);
        $this->get($link)->assertRedirectContains('/account?email=changed');

        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
        Notification::assertSentOnDemand(AccountSecurityNotice::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === $oldEmail);
    }

    public function test_email_change_rejects_wrong_password_and_taken_address(): void
    {
        Sanctum::actingAs($this->makeUser());
        $other = $this->makeUser();

        $this->postJson('/api/user/email', ['email' => 'free@example.test', 'password' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/user/email', ['email' => $other->email, 'password' => 'CurrentPass123!'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_unsigned_email_change_link_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->get('/account/confirm-email/'.$user->id.'?email=evil@example.test')->assertForbidden();
        $this->assertNotSame('evil@example.test', $user->fresh()->email);
    }

    public function test_registration_sends_verification_email_and_link_verifies(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'New Person',
            'email' => 'person@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated()->assertJsonPath('user.email_verified', false);

        $user = User::where('email', 'person@example.test')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailAddress::class);

        $link = URL::temporarySignedRoute('account.verify-email', now()->addHour(), ['user' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($link)->assertRedirectContains('email_verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_verification_can_be_resent(): void
    {
        Notification::fake();
        Sanctum::actingAs($user = $this->makeUser());

        $this->postJson('/api/email/verification-notification')->assertOk();
        Notification::assertSentTo($user, VerifyEmailAddress::class);
    }

    public function test_account_deletion_removes_listings_and_wipes_inquiry_contact_details(): void
    {
        $owner = $this->makeUser('owner');
        $listing = $this->makeListing($owner);
        $buyer = $this->makeUser();
        $inquiry = Inquiry::create([
            'property_id' => $listing->id, 'buyer_id' => $buyer->id, 'name' => $buyer->name,
            'email' => $buyer->email, 'phone' => '09170000000', 'message' => 'Is this still available?', 'status' => 'new',
        ]);

        Sanctum::actingAs($buyer);
        $this->deleteJson('/api/user', ['password' => 'wrong'])->assertStatus(422);
        $this->deleteJson('/api/user', ['password' => 'CurrentPass123!'])->assertOk();

        $this->assertModelMissing($buyer);
        $inquiry->refresh();
        $this->assertNull($inquiry->buyer_id);
        $this->assertSame(AccountController::DELETED_EMAIL, $inquiry->email);
        $this->assertNull($inquiry->phone);

        Sanctum::actingAs($owner);
        $this->deleteJson('/api/user', ['password' => 'CurrentPass123!'])->assertOk();
        $this->assertModelMissing($listing);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        Sanctum::actingAs($admin = $this->makeUser('admin'));

        $this->deleteJson('/api/user', ['password' => 'CurrentPass123!'])->assertForbidden();
        $this->assertModelExists($admin);
    }
}

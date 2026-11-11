<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use App\Notifications\ListingAvailabilityReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::create([
            'name' => 'Owner '.Str::random(6),
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('TestPassword123!'),
            'role' => 'owner',
            'account_status' => 'active',
        ]);
    }

    private function makeListing(User $owner, int $confirmedDaysAgo = 0): Property
    {
        $title = 'Listing '.Str::random(6);

        return Property::create([
            'owner_id' => $owner->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'property_type' => 'warehouse',
            'listing_type' => 'rent',
            'description' => 'Warehouse.',
            'price' => 80000,
            'currency' => 'PHP',
            'status' => 'published',
            'published_at' => now()->subDays($confirmedDaysAgo),
            'availability' => 'available',
            'availability_confirmed_at' => now()->subDays($confirmedDaysAgo),
            'address_line' => 'Port area',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
        ]);
    }

    public function test_marking_a_listing_rented_hides_it_from_the_public(): void
    {
        $owner = $this->makeOwner();
        $listing = $this->makeListing($owner);
        Sanctum::actingAs($owner);

        $this->patchJson('/api/my/properties/'.$listing->id.'/availability', ['availability' => 'rented'])
            ->assertOk()
            ->assertJsonPath('data.availability', 'rented');

        $this->getJson('/api/properties/'.$listing->id)->assertNotFound();
    }

    public function test_only_the_owner_can_change_availability(): void
    {
        $listing = $this->makeListing($this->makeOwner());
        Sanctum::actingAs($this->makeOwner());

        $this->patchJson('/api/my/properties/'.$listing->id.'/availability', ['availability' => 'sold'])->assertForbidden();
    }

    public function test_listings_unconfirmed_for_sixty_days_are_hidden(): void
    {
        $listing = $this->makeListing($this->makeOwner(), Property::HIDE_AFTER_DAYS + 1);

        $this->getJson('/api/properties/'.$listing->id)->assertNotFound();
        $this->getJson('/api/properties')->assertJsonCount(0, 'data');
    }

    public function test_reminders_are_sent_once_for_listings_due_for_confirmation(): void
    {
        Notification::fake();
        $owner = $this->makeOwner();
        $due = $this->makeListing($owner, Property::REMIND_AFTER_DAYS + 1);
        $this->makeListing($owner, 10);

        $this->artisan('listings:send-availability-reminders')->assertSuccessful();
        $this->artisan('listings:send-availability-reminders')->assertSuccessful();

        Notification::assertSentToTimes($owner, ListingAvailabilityReminder::class, 1);
        Notification::assertSentTo($owner, ListingAvailabilityReminder::class, fn ($notification) => $notification->property->is($due));
    }

    public function test_signed_email_link_confirms_the_listing_and_redirects_to_the_workspace(): void
    {
        $owner = $this->makeOwner();
        $listing = $this->makeListing($owner, Property::REMIND_AFTER_DAYS + 5);
        $listing->forceFill(['availability_reminder_sent_at' => now()])->save();

        $url = URL::temporarySignedRoute('listings.confirm-availability', now()->addDay(), ['property' => $listing->id]);

        $this->get($url)->assertRedirectContains('/owner/properties/'.$listing->id.'?availability=confirmed');

        $listing->refresh();
        $this->assertTrue($listing->availability_confirmed_at->isToday());
        $this->assertNull($listing->availability_reminder_sent_at);
    }

    public function test_unsigned_confirmation_links_are_rejected(): void
    {
        $listing = $this->makeListing($this->makeOwner());

        $this->get('/listings/'.$listing->id.'/still-available')->assertForbidden();
    }

    public function test_reminder_trigger_requires_the_shared_secret(): void
    {
        config(['services.cron.secret' => 'top-secret']);

        $this->postJson('/api/internal/availability-reminders')->assertForbidden();
        $this->postJson('/api/internal/availability-reminders', [], ['X-Cron-Secret' => 'wrong'])->assertForbidden();
        $this->postJson('/api/internal/availability-reminders', [], ['X-Cron-Secret' => 'top-secret'])
            ->assertOk()
            ->assertJsonPath('reminders_sent', 0);
    }

    public function test_reminder_trigger_is_disabled_without_a_configured_secret(): void
    {
        config(['services.cron.secret' => null]);

        $this->postJson('/api/internal/availability-reminders', [], ['X-Cron-Secret' => ''])->assertForbidden();
    }
}

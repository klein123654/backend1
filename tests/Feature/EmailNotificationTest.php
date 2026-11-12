<?php

namespace Tests\Feature;

use App\Models\BrokerVerification;
use App\Models\Property;
use App\Models\PublisherApplication;
use App\Models\User;
use App\Notifications\BrokerVerificationReviewed;
use App\Notifications\InquiryReceived;
use App\Notifications\ListingReviewed;
use App\Notifications\PublisherApplicationReviewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class EmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => 'Test '.Str::random(8),
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('TestPassword123!'),
            'role' => $role,
            'account_status' => 'active',
        ]);
    }

    private function makeProperty(User $owner, array $attributes = []): Property
    {
        $title = 'Notification listing '.Str::random(6);

        return Property::create(array_merge([
            'owner_id' => $owner->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'property_type' => 'house',
            'listing_type' => 'sale',
            'description' => 'Automated test listing.',
            'price' => 1500000,
            'currency' => 'PHP',
            'status' => 'published',
            'published_at' => now(),
            'address_line' => 'Test address',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
        ], $attributes));
    }

    public function test_listing_owner_is_emailed_when_a_buyer_sends_an_inquiry(): void
    {
        Notification::fake();
        $owner = $this->makeUser('owner');
        $property = $this->makeProperty($owner);
        Sanctum::actingAs($this->makeUser('buyer'));

        $this->postJson('/api/properties/'.$property->id.'/inquiries', [
            'name' => 'Interested Buyer',
            'email' => 'buyer@example.test',
            'message' => 'Is this property still available for viewing?',
        ])->assertCreated();

        Notification::assertSentTo($owner, InquiryReceived::class);
    }

    public function test_inquiry_is_still_created_when_mail_delivery_fails(): void
    {
        Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP is down'));
        $property = $this->makeProperty($this->makeUser('owner'));
        Sanctum::actingAs($this->makeUser('buyer'));

        $this->postJson('/api/properties/'.$property->id.'/inquiries', [
            'name' => 'Interested Buyer',
            'email' => 'buyer@example.test',
            'message' => 'Is this property still available for viewing?',
        ])->assertCreated();

        $this->assertDatabaseCount('inquiries', 1);
    }

    public function test_owner_is_emailed_when_an_administrator_reviews_a_listing(): void
    {
        Notification::fake();
        $owner = $this->makeUser('owner');
        $property = $this->makeProperty($owner, ['status' => 'pending', 'published_at' => null]);
        Sanctum::actingAs($this->makeUser('admin'));

        $this->patchJson('/api/admin/properties/'.$property->id, ['status' => 'published'])->assertOk();

        Notification::assertSentTo($owner, ListingReviewed::class, fn (ListingReviewed $notification) => $notification->property->status === 'published');
    }

    public function test_broker_is_emailed_when_verification_is_reviewed(): void
    {
        Notification::fake();
        $broker = $this->makeUser('broker');
        $verification = BrokerVerification::create([
            'broker_id' => $broker->id,
            'license_number' => 'PRC-1234567',
            'status' => 'pending',
        ]);
        Sanctum::actingAs($this->makeUser('admin'));

        $this->patchJson('/api/admin/verifications/'.$verification->id, ['status' => 'verified'])->assertOk();

        Notification::assertSentTo($broker, BrokerVerificationReviewed::class);
    }

    public function test_applicant_is_emailed_when_publisher_application_is_reviewed(): void
    {
        Notification::fake();
        $applicant = $this->makeUser('buyer');
        $application = PublisherApplication::create([
            'user_id' => $applicant->id,
            'requested_role' => 'owner',
            'status' => 'pending',
        ]);
        Sanctum::actingAs($this->makeUser('admin'));

        $this->patchJson('/api/admin/publisher-applications/'.$application->id, ['status' => 'approved'])->assertOk();

        Notification::assertSentTo($applicant, PublisherApplicationReviewed::class);
    }
}

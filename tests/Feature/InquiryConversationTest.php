<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InquiryConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_inquiry_stores_exactly_one_initial_message(): void
    {
        config(['services.deepseek.api_key' => null]);
        $buyer = $this->createUser('buyer');
        $property = $this->createProperty($this->createUser('owner'));
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/properties/'.$property->id.'/inquiries', [
            'name' => $buyer->name,
            'email' => $buyer->email,
            'message' => 'I would like to discuss availability for this commercial unit.',
        ]);

        $response->assertCreated();
        $inquiry = Inquiry::query()->firstOrFail();
        $this->assertSame(1, $inquiry->messages()->count());
        $this->assertDatabaseHas('inquiry_messages', [
            'inquiry_id' => $inquiry->id,
            'sender_id' => $buyer->id,
            'body' => 'I would like to discuss availability for this commercial unit.',
        ]);
        $this->assertNotNull($inquiry->last_message_at);

        $this->getJson('/api/inquiries/'.$inquiry->id.'/messages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'I would like to discuss availability for this commercial unit.')
            ->assertJsonPath('data.0.sender.id', $buyer->id);
    }

    public function test_buyer_and_publisher_can_read_messages_in_chronological_order(): void
    {
        [$buyer, $owner, $inquiry] = $this->createConversation();
        $first = $inquiry->messages()->create([
            'sender_id' => $buyer->id,
            'body' => 'I am interested in the property.',
        ]);
        $second = $inquiry->messages()->create([
            'sender_id' => $owner->id,
            'body' => 'It is available for viewing.',
        ]);

        Sanctum::actingAs($buyer);
        $buyerResponse = $this->getJson('/api/inquiries/'.$inquiry->id.'/messages');
        $buyerResponse->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.body', 'I am interested in the property.')
            ->assertJsonPath('data.0.sender.name', $buyer->name)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.1.body', 'It is available for viewing.');

        Sanctum::actingAs($owner);
        $this->getJson('/api/inquiries/'.$inquiry->id.'/messages')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_unrelated_buyer_gets_forbidden_for_reading_and_replying(): void
    {
        [, , $inquiry] = $this->createConversation();
        $otherBuyer = $this->createUser('buyer');
        Sanctum::actingAs($otherBuyer);

        $this->getJson('/api/inquiries/'.$inquiry->id.'/messages')->assertForbidden();
        $this->postJson('/api/inquiries/'.$inquiry->id.'/messages', ['body' => 'Unauthorized reply'])
            ->assertForbidden();

        $this->assertSame(0, $inquiry->messages()->count());
    }

    public function test_publisher_reply_is_saved_and_updates_last_message_timestamp(): void
    {
        [, $owner, $inquiry] = $this->createConversation();
        Sanctum::actingAs($owner);
        RateLimiter::clear('inquiry-messages:user:'.$owner->id);

        $response = $this->postJson('/api/inquiries/'.$inquiry->id.'/messages', [
            'body' => 'The property is available next week.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.body', 'The property is available next week.')
            ->assertJsonPath('data.sender.id', $owner->id);
        $this->assertDatabaseHas('inquiry_messages', [
            'inquiry_id' => $inquiry->id,
            'sender_id' => $owner->id,
            'body' => 'The property is available next week.',
        ]);
        $this->assertNotNull($inquiry->fresh()->last_message_at);
    }

    public function test_empty_reply_returns_validation_error_without_saving_message(): void
    {
        [, $owner, $inquiry] = $this->createConversation();
        Sanctum::actingAs($owner);

        $this->postJson('/api/inquiries/'.$inquiry->id.'/messages', ['body' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, $inquiry->messages()->count());
    }

    public function test_user_cannot_send_more_than_twenty_messages_per_minute(): void
    {
        [, $owner, $inquiry] = $this->createConversation();
        Sanctum::actingAs($owner);
        RateLimiter::clear('inquiry-messages:user:'.$owner->id);

        for ($messageNumber = 1; $messageNumber <= 20; $messageNumber++) {
            $this->postJson('/api/inquiries/'.$inquiry->id.'/messages', [
                'body' => 'Reply number '.$messageNumber,
            ])->assertCreated();
        }

        $this->postJson('/api/inquiries/'.$inquiry->id.'/messages', ['body' => 'One more reply'])
            ->assertTooManyRequests();
        $this->assertSame(20, $inquiry->messages()->count());
    }

    private function createConversation(): array
    {
        $buyer = $this->createUser('buyer');
        $owner = $this->createUser('owner');
        $property = $this->createProperty($owner);
        $inquiry = Inquiry::create([
            'property_id' => $property->id,
            'buyer_id' => $buyer->id,
            'name' => $buyer->name,
            'email' => $buyer->email,
            'message' => 'Original inquiry message for the property.',
            'status' => 'new',
        ]);

        return [$buyer, $owner, $inquiry];
    }

    private function createUser(string $role): User
    {
        return User::create([
            'name' => Str::title($role).' Test User',
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('TestPassword123!'),
            'role' => $role,
            'account_status' => 'active',
        ]);
    }

    private function createProperty(User $owner): Property
    {
        return Property::create([
            'owner_id' => $owner->id,
            'title' => 'Test Commercial Property',
            'slug' => Str::uuid()->toString(),
            'property_type' => 'Office Spaces',
            'listing_type' => 'For Rent',
            'description' => 'Test property for conversation feature coverage.',
            'price' => 25000,
            'currency' => 'PHP',
            'status' => 'published',
            'address_line' => 'Butuan City',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
            'published_at' => now(),
        ]);
    }
}

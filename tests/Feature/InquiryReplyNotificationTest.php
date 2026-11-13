<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Notifications\InquiryReplyReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesListings;
use Tests\TestCase;

class InquiryReplyNotificationTest extends TestCase
{
    use MakesListings, RefreshDatabase;

    private function conversation(): array
    {
        $owner = $this->makeUser('owner');
        $buyer = $this->makeUser();
        $listing = $this->makeListing($owner);
        $inquiry = Inquiry::create([
            'property_id' => $listing->id, 'buyer_id' => $buyer->id, 'name' => $buyer->name,
            'email' => $buyer->email, 'message' => 'Is it available?', 'status' => 'new',
        ]);

        return [$owner, $buyer, $inquiry];
    }

    public function test_publisher_reply_emails_the_buyer_and_marks_inquiry_in_progress(): void
    {
        Notification::fake();
        [$owner, $buyer, $inquiry] = $this->conversation();

        Sanctum::actingAs($owner);
        $this->postJson("/api/inquiries/{$inquiry->id}/messages", ['body' => 'Yes, it is available.'])->assertCreated();

        Notification::assertSentTo($buyer, InquiryReplyReceived::class);
        Notification::assertNotSentTo($owner, InquiryReplyReceived::class);
        $this->assertSame('in_progress', $inquiry->fresh()->status);
    }

    public function test_buyer_reply_emails_the_publisher(): void
    {
        Notification::fake();
        [$owner, $buyer, $inquiry] = $this->conversation();

        Sanctum::actingAs($buyer);
        $this->postJson("/api/inquiries/{$inquiry->id}/messages", ['body' => 'Can I visit on Friday?'])->assertCreated();

        Notification::assertSentTo($owner, InquiryReplyReceived::class);
        $this->assertSame('new', $inquiry->fresh()->status);
    }

    public function test_rapid_replies_send_only_one_email_per_recipient(): void
    {
        Notification::fake();
        [$owner, $buyer, $inquiry] = $this->conversation();

        Sanctum::actingAs($owner);
        foreach (['One', 'Two', 'Three'] as $body) {
            $this->postJson("/api/inquiries/{$inquiry->id}/messages", ['body' => $body])->assertCreated();
        }

        Notification::assertSentToTimes($buyer, InquiryReplyReceived::class, 1);
    }
}

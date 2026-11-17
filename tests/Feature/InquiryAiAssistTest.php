<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\User;
use App\Services\DeepSeekService;
use App\Services\MessageSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesListings;
use Tests\TestCase;

class InquiryAiAssistTest extends TestCase
{
    use MakesListings, RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Inquiry} */
    private function conversation(): array
    {
        $owner = $this->makeUser('owner');
        $buyer = $this->makeUser('buyer', ['name' => 'Bea Buyer']);
        $listing = $this->makeListing($owner, ['title' => 'Corner retail space', 'price' => 25000, 'floor_area' => 80]);
        $inquiry = Inquiry::create([
            'property_id' => $listing->id, 'buyer_id' => $buyer->id, 'name' => $buyer->name, 'email' => $buyer->email,
            'message' => 'Is there parking, and can I view it this week?', 'status' => 'new', 'ai_priority' => 'unscored',
        ]);
        $inquiry->messages()->create(['sender_id' => $buyer->id, 'body' => $inquiry->message]);

        return [$owner, $buyer, $inquiry];
    }

    public function test_publisher_gets_a_draft_built_from_listing_facts_and_the_conversation(): void
    {
        [$owner, , $inquiry] = $this->conversation();
        $this->mock(DeepSeekService::class)->shouldReceive('suggestReply')->once()
            ->withArgs(function (array $context) use ($owner): bool {
                return $context['publisher_name'] === $owner->name
                    && $context['listing']['title'] === 'Corner retail space'
                    && $context['listing']['price_note'] === 'per month'
                    && $context['listing']['floor_area_sqm'] == 80
                    && $context['conversation'] === [['from' => 'inquirer', 'text' => 'Is there parking, and can I view it this week?']];
            })
            ->andReturn(['success' => true, 'reply' => 'Hi Bea, thanks for your interest. [confirm parking] Would Thursday work for a viewing?']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/inquiries/{$inquiry->id}/suggest-reply")->assertOk()
            ->assertJsonPath('reply', 'Hi Bea, thanks for your interest. [confirm parking] Would Thursday work for a viewing?');
        $this->assertSame(1, $inquiry->messages()->count(), 'Drafting never sends a message');
    }

    public function test_only_the_publisher_can_draft_replies(): void
    {
        [, $buyer, $inquiry] = $this->conversation();
        $this->mock(DeepSeekService::class)->shouldNotReceive('suggestReply');

        Sanctum::actingAs($buyer);
        $this->postJson("/api/inquiries/{$inquiry->id}/suggest-reply")->assertForbidden();
        Sanctum::actingAs($this->makeUser('owner'));
        $this->postJson("/api/inquiries/{$inquiry->id}/suggest-reply")->assertForbidden();
        Sanctum::actingAs($this->makeUser('admin'));
        $this->postJson("/api/inquiries/{$inquiry->id}/suggest-reply")->assertForbidden();
    }

    public function test_draft_reports_when_ai_is_unavailable(): void
    {
        config(['services.deepseek.api_key' => null]);
        [$owner, , $inquiry] = $this->conversation();
        Sanctum::actingAs($owner);

        $this->postJson("/api/inquiries/{$inquiry->id}/suggest-reply")->assertStatus(503)
            ->assertJsonPath('message', 'AI features are not available right now.');
    }

    public function test_payment_request_is_flagged_for_the_recipient(): void
    {
        Notification::fake();
        [$owner, $buyer, $inquiry] = $this->conversation();
        $this->mock(DeepSeekService::class)->shouldReceive('assessPaymentRisk')->once()
            ->andReturn(['risky' => true, 'reason' => 'Asks for a reservation fee before any viewing.']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/inquiries/{$inquiry->id}/messages", ['body' => 'Send ₱5,000 reservation fee via GCash first to hold it.'])
            ->assertCreated()->assertJsonPath('data.safety_warning', 'Asks for a reservation fee before any viewing.');

        Sanctum::actingAs($buyer);
        $this->getJson("/api/inquiries/{$inquiry->id}/messages")->assertOk()
            ->assertJsonPath('data.1.safety_warning', 'Asks for a reservation fee before any viewing.')
            ->assertJsonPath('data.0.safety_warning', null);
    }

    public function test_honest_questions_about_deposits_are_not_flagged(): void
    {
        Notification::fake();
        [, $buyer, $inquiry] = $this->conversation();
        $this->mock(DeepSeekService::class)->shouldReceive('assessPaymentRisk')->once()
            ->andReturn(['risky' => false, 'reason' => '']);
        Sanctum::actingAs($buyer);

        $this->postJson("/api/inquiries/{$inquiry->id}/messages", ['body' => 'How many months of deposit do you usually require?'])
            ->assertCreated()->assertJsonPath('data.safety_warning', null);
    }

    public function test_ordinary_messages_skip_the_ai_check(): void
    {
        Notification::fake();
        [$owner, , $inquiry] = $this->conversation();
        $this->mock(DeepSeekService::class)->shouldNotReceive('assessPaymentRisk');
        Sanctum::actingAs($owner);

        $this->postJson("/api/inquiries/{$inquiry->id}/messages", ['body' => 'Yes, there are two parking slots. Thursday at 10 works.'])
            ->assertCreated()->assertJsonPath('data.safety_warning', null);
    }

    public function test_rule_based_warning_stands_when_ai_is_unavailable(): void
    {
        config(['services.deepseek.api_key' => null]);
        $safety = app(MessageSafety::class);

        $this->assertSame(MessageSafety::GENERIC_WARNING, $safety->check('Pakisend lang ang bayad sa GCash 0917 para ma-reserve.'));
        $this->assertSame(MessageSafety::GENERIC_WARNING, $safety->check('Please transfer the advance rent to my BDO account number.'));
        $this->assertNull($safety->check('The space has two parking slots and a loading bay.'));
    }
}

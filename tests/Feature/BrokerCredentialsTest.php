<?php

namespace Tests\Feature;

use App\Models\BrokerVerification;
use App\Models\Property;
use App\Models\PublisherApplication;
use App\Models\User;
use App\Notifications\BrokerCredentialsExpiring;
use App\Notifications\BrokerVerificationReviewed;
use App\Support\BrokerCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrokerCredentialsTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function credentials(array $overrides = []): array
    {
        return array_merge([
            'license_number' => 'PRC-0004567',
            'prc_valid_until' => now()->addYears(2)->toDateString(),
            'dhsud_registration_number' => 'DHSUD-CRG-1234',
            'dhsud_valid_until' => now()->addMonths(10)->toDateString(),
            'firm_name' => 'Agusan Prime Realty',
            'firm_position' => 'Associate Broker',
            'firm_address' => 'J.C. Aquino Ave, Butuan City',
            'firm_registration_number' => 'DTI-889900',
            'affiliations' => ['Butuan Realty Board', ' ', 'PAREB'],
        ], $overrides);
    }

    private function makeBroker(array $credentialOverrides = [], string $status = 'verified'): User
    {
        $broker = $this->makeUser('broker');
        $data = $this->credentials($credentialOverrides);
        $data['affiliations'] = ['Butuan Realty Board'];
        BrokerVerification::create([...$data, 'broker_id' => $broker->id, 'status' => $status]);

        return $broker;
    }

    private function listingPayload(): array
    {
        return [
            'title' => 'Broker Office '.Str::random(4),
            'property_type' => 'office',
            'listing_type' => 'rent',
            'description' => 'Ground floor office near the city hall.',
            'price' => 25000,
            'address_line' => 'Montilla Blvd',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
        ];
    }

    public function test_broker_application_requires_both_credentials_and_the_firm(): void
    {
        Sanctum::actingAs($this->makeUser('buyer'));

        $this->postJson('/api/my/publisher-application', ['requested_role' => 'broker', 'license_number' => 'PRC-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prc_valid_until', 'dhsud_registration_number', 'dhsud_valid_until', 'firm_name']);
    }

    public function test_expired_credentials_are_rejected_on_application(): void
    {
        Sanctum::actingAs($this->makeUser('buyer'));

        $this->postJson('/api/my/publisher-application', [
            'requested_role' => 'broker',
            ...$this->credentials(['dhsud_valid_until' => now()->subDay()->toDateString()]),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.dhsud_valid_until.0', 'Your DHSUD registration has expired. Renew it with DHSUD first.');
    }

    public function test_approving_a_broker_application_copies_credentials_and_records_the_dhsud_check(): void
    {
        $applicant = $this->makeUser('buyer');
        Sanctum::actingAs($applicant);
        $this->postJson('/api/my/publisher-application', ['requested_role' => 'broker', ...$this->credentials()])
            ->assertCreated()
            ->assertJsonPath('data.affiliations', ['Butuan Realty Board', 'PAREB']);

        Notification::fake();
        Sanctum::actingAs($this->makeUser('admin'));
        $application = PublisherApplication::firstOrFail();
        $this->patchJson('/api/admin/publisher-applications/'.$application->id, ['status' => 'approved'])->assertOk();

        $verification = BrokerVerification::where('broker_id', $applicant->id)->firstOrFail();
        $this->assertSame('verified', $verification->status);
        $this->assertSame('DHSUD-CRG-1234', $verification->dhsud_registration_number);
        $this->assertSame('Agusan Prime Realty', $verification->firm_name);
        $this->assertNotNull($verification->dhsud_checked_at);
        $this->assertSame('current', $verification->credential_status);
    }

    public function test_application_and_profile_responses_include_the_dhsud_notice(): void
    {
        Sanctum::actingAs($this->makeUser('buyer'));
        $this->getJson('/api/my/publisher-application')->assertOk()->assertJsonPath('meta.dhsud_notice', BrokerCredentials::DHSUD_NOTICE);

        Sanctum::actingAs($this->makeBroker());
        $this->getJson('/api/broker/verification')->assertOk()->assertJsonPath('meta.dhsud_notice', BrokerCredentials::DHSUD_NOTICE);
    }

    public function test_broker_with_current_credentials_can_add_listings(): void
    {
        Sanctum::actingAs($this->makeBroker());

        $this->postJson('/api/my/properties', $this->listingPayload())->assertCreated();
    }

    public function test_expired_credentials_block_new_listings_but_keep_existing_ones_public(): void
    {
        $broker = $this->makeBroker();
        $title = 'Existing Broker Listing';
        $listing = Property::create([
            ...$this->listingPayload(), 'title' => $title, 'slug' => Str::slug($title),
            'owner_id' => $broker->id, 'broker_id' => $broker->id, 'currency' => 'PHP',
            'status' => 'published', 'published_at' => now(), 'availability' => 'available',
        ]);
        BrokerVerification::where('broker_id', $broker->id)->update(['dhsud_valid_until' => now()->subDay()->toDateString()]);
        Sanctum::actingAs($broker);

        $this->postJson('/api/my/properties', $this->listingPayload())
            ->assertForbidden()
            ->assertJsonPath('message', 'Your PRC license or DHSUD registration has expired. Submit your renewed credentials in your broker profile to manage listings again.');

        $this->getJson('/api/properties/'.$listing->id)
            ->assertOk()
            ->assertJsonPath('data.publisher.verified', false)
            ->assertJsonPath('data.publisher.broker.credential_status', 'expired');
    }

    public function test_verified_broker_without_validity_dates_must_add_them(): void
    {
        $broker = $this->makeBroker(['prc_valid_until' => null, 'dhsud_valid_until' => null]);
        Sanctum::actingAs($broker);

        $this->postJson('/api/my/properties', $this->listingPayload())->assertForbidden();
    }

    public function test_renewal_goes_back_to_admin_review(): void
    {
        $broker = $this->makeBroker(['dhsud_valid_until' => now()->addDays(5)->toDateString()]);
        Sanctum::actingAs($broker);

        $this->postJson('/api/broker/verification', $this->credentials(['dhsud_valid_until' => now()->addYear()->toDateString()]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->postJson('/api/my/properties', $this->listingPayload())->assertForbidden();
    }

    public function test_brokers_are_reminded_once_before_a_credential_expires(): void
    {
        Notification::fake();
        $expiring = $this->makeBroker(['dhsud_valid_until' => now()->addDays(20)->toDateString()]);
        $current = $this->makeBroker();
        config(['services.cron.secret' => 'cron-secret']);

        $this->postJson('/api/internal/availability-reminders', [], ['X-Cron-Secret' => 'cron-secret'])
            ->assertOk()
            ->assertJsonPath('credential_reminders_sent', 1);
        $this->postJson('/api/internal/availability-reminders', [], ['X-Cron-Secret' => 'cron-secret'])
            ->assertJsonPath('credential_reminders_sent', 0);

        Notification::assertSentToTimes($expiring, BrokerCredentialsExpiring::class, 1);
        Notification::assertNothingSentTo($current);
    }

    public function test_admin_records_the_yearly_dhsud_check(): void
    {
        Notification::fake();
        $active = $this->makeBroker();
        $lapsed = $this->makeBroker();
        Sanctum::actingAs($this->makeUser('admin'));

        $activeRecord = BrokerVerification::where('broker_id', $active->id)->firstOrFail();
        $this->assertTrue($activeRecord->dhsud_check_due);
        $this->postJson('/api/admin/verifications/'.$activeRecord->id.'/dhsud-check', ['result' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.dhsud_check_due', false);

        $lapsedRecord = BrokerVerification::where('broker_id', $lapsed->id)->firstOrFail();
        $this->postJson('/api/admin/verifications/'.$lapsedRecord->id.'/dhsud-check', ['result' => 'inactive'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_notes');
        $this->postJson('/api/admin/verifications/'.$lapsedRecord->id.'/dhsud-check', [
            'result' => 'inactive', 'review_notes' => 'DHSUD lists this registration as lapsed.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        Notification::assertSentTo($lapsed, BrokerVerificationReviewed::class);
        Notification::assertNotSentTo($active, BrokerVerificationReviewed::class);
    }

    public function test_dhsud_check_is_due_again_after_a_year(): void
    {
        $broker = $this->makeBroker();
        $record = BrokerVerification::where('broker_id', $broker->id)->firstOrFail();

        $record->update(['dhsud_checked_at' => now()->subDays(100)]);
        $this->assertFalse($record->fresh()->dhsud_check_due);

        $record->update(['dhsud_checked_at' => now()->subDays(BrokerVerification::DHSUD_CHECK_INTERVAL_DAYS + 1)]);
        $this->assertTrue($record->fresh()->dhsud_check_due);
    }
}

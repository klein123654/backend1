<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class BrokerVerification extends Model
{
    use HasFactory;

    /** Days before a credential expires when the broker is reminded to renew. */
    public const RENEWAL_REMINDER_DAYS = 30;

    /** Days after an administrator's last DHSUD check when the next yearly check is due. */
    public const DHSUD_CHECK_INTERVAL_DAYS = 365;

    /** Credential fields a broker gives, and an administrator checks. */
    public const CREDENTIAL_FIELDS = [
        'license_number', 'prc_valid_until',
        'dhsud_registration_number', 'dhsud_valid_until',
        'firm_name', 'firm_position', 'firm_address', 'firm_registration_number',
        'affiliations',
    ];

    protected $fillable = [
        'broker_id', 'document_path', 'status', 'reviewed_by', 'reviewed_at',
        'review_notes', 'dhsud_checked_at', 'expiry_reminder_sent_at',
        ...self::CREDENTIAL_FIELDS,
    ];

    protected $appends = ['credential_status', 'dhsud_check_due'];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'dhsud_checked_at' => 'datetime',
            'expiry_reminder_sent_at' => 'datetime',
            'prc_valid_until' => 'date:Y-m-d',
            'dhsud_valid_until' => 'date:Y-m-d',
            'affiliations' => 'array',
        ];
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'broker_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The earlier of the two credential expiry dates, or null when either is missing.
     */
    public function earliestExpiry(): ?Carbon
    {
        if (! $this->prc_valid_until || ! $this->dhsud_valid_until) {
            return null;
        }

        return $this->prc_valid_until->lt($this->dhsud_valid_until)
            ? $this->prc_valid_until->copy()
            : $this->dhsud_valid_until->copy();
    }

    /**
     * missing: dates were never given (records made before credentials were tracked);
     * expired: the PRC licence or the DHSUD registration has lapsed;
     * expiring: one of them lapses within RENEWAL_REMINDER_DAYS;
     * current: both are valid.
     */
    public function getCredentialStatusAttribute(): string
    {
        $expiry = $this->earliestExpiry();

        return match (true) {
            $expiry === null => 'missing',
            $expiry->lt(today()) => 'expired',
            $expiry->lte(today()->addDays(self::RENEWAL_REMINDER_DAYS)) => 'expiring',
            default => 'current',
        };
    }

    /**
     * Verified by an administrator and both credentials still valid.
     */
    public function credentialsCurrent(): bool
    {
        return $this->status === 'verified' && in_array($this->credential_status, ['current', 'expiring'], true);
    }

    /**
     * Whether an administrator is due to re-check the broker's DHSUD registration.
     */
    public function getDhsudCheckDueAttribute(): bool
    {
        return $this->status === 'verified'
            && ($this->dhsud_checked_at === null
                || $this->dhsud_checked_at->lte(now()->subDays(self::DHSUD_CHECK_INTERVAL_DAYS)));
    }
}

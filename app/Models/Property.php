<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id', 'broker_id', 'title', 'slug', 'property_type',
        'listing_type', 'description', 'price', 'currency', 'status',
        'address_line', 'barangay', 'city', 'province', 'postal_code',
        'latitude', 'longitude', 'bedrooms', 'bathrooms', 'floor_area',
        'lot_area', 'parking_spaces', 'published_at',
        'reviewed_by', 'reviewed_at', 'review_notes',
        'ai_tags',
        'availability', 'availability_confirmed_at', 'availability_reminder_sent_at',
    ];

    /** Days after the last confirmation when the publisher is asked if the listing is still available. */
    public const REMIND_AFTER_DAYS = 45;

    /** Days after the last confirmation when an unconfirmed listing is hidden from the public. */
    public const HIDE_AFTER_DAYS = 60;

    /** Days a rented or sold listing stays public, marked unavailable, before it is hidden. */
    public const UNAVAILABLE_VISIBLE_DAYS = 30;

    /** reserved: an offer is being negotiated; the listing still accepts inquiries. */
    public const AVAILABILITY_OPTIONS = ['available', 'reserved', 'rented', 'sold'];

    /** Availability values that still accept inquiries and need periodic confirmation. */
    public const OPEN_AVAILABILITY = ['available', 'reserved'];

    /** Availability values of a listing that is no longer on the market. */
    public const CLOSED_AVAILABILITY = ['rented', 'sold'];

    protected static function booted(): void
    {
        // A newly created listing counts as freshly confirmed by its publisher.
        static::creating(function (Property $property): void {
            $property->availability_confirmed_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'availability_confirmed_at' => 'datetime',
            'availability_reminder_sent_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'ai_tags' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Listings the public can see: approved, and either still on the market and
     * confirmed within HIDE_AFTER_DAYS, or rented/sold within UNAVAILABLE_VISIBLE_DAYS
     * (shown marked as unavailable so visitors know it was taken).
     *
     * @param  Builder<Property>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where(fn (Builder $visible) => $visible
                ->where(fn (Builder $open) => $open
                    ->whereIn('availability', self::OPEN_AVAILABILITY)
                    ->where('availability_confirmed_at', '>=', now()->subDays(self::HIDE_AFTER_DAYS)))
                ->orWhere(fn (Builder $closed) => $closed
                    ->whereIn('availability', self::CLOSED_AVAILABILITY)
                    ->where('availability_confirmed_at', '>=', now()->subDays(self::UNAVAILABLE_VISIBLE_DAYS))));
    }

    /**
     * Publicly visible listings that are still on the market (available or reserved).
     *
     * @param  Builder<Property>  $query
     */
    public function scopeOnMarket(Builder $query): void
    {
        $query->publiclyVisible()->whereIn('availability', self::OPEN_AVAILABILITY);
    }

    public function isPubliclyVisible(): bool
    {
        if ($this->status !== 'published' || $this->published_at === null || $this->availability_confirmed_at === null) {
            return false;
        }

        $days = in_array($this->availability, self::CLOSED_AVAILABILITY, true)
            ? self::UNAVAILABLE_VISIBLE_DAYS
            : self::HIDE_AFTER_DAYS;

        return in_array($this->availability, self::AVAILABILITY_OPTIONS, true)
            && $this->availability_confirmed_at->gte(now()->subDays($days));
    }

    /**
     * Visible and still accepting inquiries.
     */
    public function isOnMarket(): bool
    {
        return $this->isPubliclyVisible() && in_array($this->availability, self::OPEN_AVAILABILITY, true);
    }

    /**
     * Record that the publisher confirmed the listing's availability just now.
     */
    public function markAvailabilityConfirmed(string $availability = 'available'): void
    {
        $this->availability = $availability;
        $this->availability_confirmed_at = now();
        $this->availability_reminder_sent_at = null;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'broker_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PropertyImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(PropertyReport::class);
    }
}

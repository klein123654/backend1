<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'phone', 'role', 'account_status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function ownedProperties(): HasMany
    {
        return $this->hasMany(Property::class, 'owner_id');
    }

    public function brokerProperties(): HasMany
    {
        return $this->hasMany(Property::class, 'broker_id');
    }

    public function brokerVerifications(): HasMany
    {
        return $this->hasMany(BrokerVerification::class, 'broker_id');
    }

    public function buyerInquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class, 'buyer_id');
    }

    public function assignedInquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class, 'assigned_broker_id');
    }

    public function inquiryMessages(): HasMany
    {
        return $this->hasMany(InquiryMessage::class, 'sender_id');
    }

    public function propertyReports(): HasMany
    {
        return $this->hasMany(PropertyReport::class, 'reporter_id');
    }

}

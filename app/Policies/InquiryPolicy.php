<?php

namespace App\Policies;

use App\Models\Inquiry;
use App\Models\User;

class InquiryPolicy
{
    /**
     * Determine whether the user may read and reply to the inquiry conversation.
     */
    public function view(User $user, Inquiry $inquiry): bool
    {
        if ($user->account_status !== 'active') {
            return false;
        }

        $property = $inquiry->property;

        return match ($user->role) {
            'admin' => true,
            'buyer' => (int) $inquiry->buyer_id === (int) $user->id,
            'owner', 'developer' => (int) $property?->owner_id === (int) $user->id,
            'broker' => (int) $inquiry->assigned_broker_id === (int) $user->id
                || (int) $property?->broker_id === (int) $user->id,
            default => false,
        };
    }

    /**
     * Determine whether the user may change the inquiry status.
     */
    public function update(User $user, Inquiry $inquiry): bool
    {
        if ($user->account_status !== 'active') {
            return false;
        }

        return match ($user->role) {
            'admin' => true,
            'owner', 'developer' => (int) $inquiry->property()->value('owner_id') === (int) $user->id,
            'broker' => (int) $inquiry->assigned_broker_id === (int) $user->id,
            default => false,
        };
    }
}

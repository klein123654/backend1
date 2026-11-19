<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PropertyPolicy
{
    /**
     * Determine whether the user may create listings (owners and brokers whose PRC licence and DHSUD registration are verified and current).
     */
    public function create(User $user): Response
    {
        if ($user->account_status !== 'active') {
            return Response::deny('Your account is not active.');
        }

        if (! in_array($user->role, ['owner', 'broker'], true)) {
            return Response::deny('A publisher account is required to manage property listings.');
        }

        if ($user->role === 'broker') {
            $verification = $user->brokerVerifications()->first();

            if (! $verification || $verification->status !== 'verified') {
                return Response::deny('Broker verification is required before managing listings.');
            }

            if (! $verification->credentialsCurrent()) {
                return Response::deny($verification->credential_status === 'missing'
                    ? 'Add your PRC license and DHSUD registration validity dates in your broker profile before managing listings.'
                    : 'Your PRC license or DHSUD registration has expired. Submit your renewed credentials in your broker profile to manage listings again.');
            }
        }

        return Response::allow();
    }

    /**
     * Determine whether the user may edit the listing.
     */
    public function update(User $user, Property $property): Response
    {
        $canPublish = $this->create($user);

        if ($canPublish->denied()) {
            return $canPublish;
        }

        return (int) $property->owner_id === (int) $user->id
            ? Response::allow()
            : Response::deny('You cannot edit another owner’s property.');
    }

    /**
     * Determine whether the user may delete the listing.
     */
    public function delete(User $user, Property $property): Response
    {
        return $this->update($user, $property);
    }
}

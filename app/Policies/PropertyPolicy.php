<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PropertyPolicy
{
    /**
     * Determine whether the user may create listings (owners, developers and verified brokers).
     */
    public function create(User $user): Response
    {
        if ($user->account_status !== 'active') {
            return Response::deny('Your account is not active.');
        }

        if (! in_array($user->role, ['owner', 'broker', 'developer'], true)) {
            return Response::deny('A publisher account is required to manage property listings.');
        }

        if ($user->role === 'broker' && ! $user->brokerVerifications()->where('status', 'verified')->exists()) {
            return Response::deny('Broker verification is required before managing listings.');
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

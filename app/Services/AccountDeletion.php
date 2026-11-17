<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Permanently deletes an account: its listings and their photos, saved items,
 * and sign-in tokens. Messages the person sent stay with the recipients, but
 * their name and contact details are removed.
 */
class AccountDeletion
{
    public const DELETED_EMAIL = 'deleted-account@butuanspaces.invalid';

    public function __construct(private PropertyImageStorage $imageStorage) {}

    public function delete(User $user): void
    {
        foreach ($user->ownedProperties()->with('images')->get() as $property) {
            $this->imageStorage->deleteListing($property);
        }

        DB::transaction(function () use ($user): void {
            Inquiry::where('buyer_id', $user->id)->update([
                'name' => 'Deleted account',
                'email' => self::DELETED_EMAIL,
                'phone' => null,
            ]);
            $user->tokens()->delete();
            $user->delete();
        });
    }
}

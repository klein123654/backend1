<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\User;
use App\Notifications\Account\AccountSecurityNotice;
use App\Notifications\Account\ConfirmEmailChange;
use App\Notifications\Account\VerifyEmailAddress;
use App\Services\PropertyImageStorage;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Laravel\Sanctum\PersonalAccessToken;

class AccountController extends Controller
{
    /** Placeholder written over a deleted buyer's contact details on past inquiries. */
    public const DELETED_EMAIL = 'deleted-account@butuanspaces.invalid';

    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->activeUser($request);
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8), 'different:current_password'],
        ]);

        $this->assertPassword($user, $data['current_password'], 'current_password');

        $user->forceFill(['password' => $data['password']])->save();

        // Sign out every other device; keep the session that made the change.
        $current = $user->currentAccessToken();
        $user->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($query) => $query->where('id', '!=', $current->id))
            ->delete();

        $this->notifySafely($user, new AccountSecurityNotice(
            'Your ButuanSpaces password was changed',
            'The password for your account was just changed, and other devices were signed out.'
        ));

        return response()->json(['message' => 'Password updated. Other devices have been signed out.']);
    }

    public function requestEmailChange(Request $request): JsonResponse
    {
        $user = $this->activeUser($request);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string'],
        ], [
            'email.unique' => 'Another account already uses that email address.',
        ]);

        $this->assertPassword($user, $data['password'], 'password');

        $this->notifySafely(
            Notification::route('mail', $data['email']),
            new ConfirmEmailChange($user, $data['email'])
        );

        return response()->json([
            'message' => 'We sent a confirmation link to '.$data['email'].'. Your email changes once you open it.',
        ]);
    }

    /** Signed link from the confirmation email sent to the new address. */
    public function confirmEmailChange(Request $request, User $user): RedirectResponse
    {
        $email = (string) $request->query('email');

        if ($email === '' || User::where('email', $email)->where('id', '!=', $user->id)->exists()) {
            return redirect()->away(FrontendUrl::to('/account?email=taken'));
        }

        $oldEmail = $user->email;
        if ($oldEmail !== $email) {
            $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();

            $this->notifySafely(
                Notification::route('mail', $oldEmail),
                new AccountSecurityNotice(
                    'Your ButuanSpaces email address was changed',
                    'The email address on your account was changed from '.$oldEmail.' to '.$email.'.'
                )
            );
        }

        return redirect()->away(FrontendUrl::to('/account?email=changed'));
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $this->activeUser($request);

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your email address is already confirmed.']);
        }

        $this->notifySafely($user, new VerifyEmailAddress);

        return response()->json(['message' => 'We sent a new confirmation link to '.$user->email.'.']);
    }

    /** Signed link from the welcome email. */
    public function verifyEmail(User $user, string $hash): RedirectResponse
    {
        if (! hash_equals(sha1($user->email), $hash)) {
            return redirect()->away(FrontendUrl::to('/?email_verified=invalid'));
        }

        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return redirect()->away(FrontendUrl::to('/?email_verified=1'));
    }

    /**
     * Permanently delete the signed-in account. Listings (and their photos)
     * are removed; past inquiries the person sent stay with the publisher but
     * their contact details are wiped.
     */
    public function destroy(Request $request, PropertyImageStorage $imageStorage): JsonResponse
    {
        $user = $request->user();
        abort_if($user->role === 'admin', 403, 'Administrator accounts cannot be deleted from the account page.');

        $data = $request->validate(['password' => ['required', 'string']]);
        $this->assertPassword($user, $data['password'], 'password');

        foreach ($user->ownedProperties()->with('images')->get() as $property) {
            $imageStorage->deleteListing($property);
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

        return response()->json(['message' => 'Your account has been deleted.']);
    }

    private function activeUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->account_status === 'active', 403, 'Account suspended.');

        return $user;
    }

    private function assertPassword(User $user, string $password, string $field): void
    {
        if (! Hash::check($password, $user->password)) {
            abort(response()->json([
                'message' => 'That password is incorrect.',
                'errors' => [$field => ['That password is incorrect.']],
            ], 422));
        }
    }
}

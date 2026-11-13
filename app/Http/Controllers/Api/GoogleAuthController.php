<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Sign in with Google": the frontend sends the ID token from Google Identity
 * Services; we check it with Google and sign the person in (creating a buyer
 * account on first use). Disabled until GOOGLE_CLIENT_ID is set.
 */
class GoogleAuthController extends Controller
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __invoke(Request $request): JsonResponse
    {
        $clientId = (string) config('services.google.client_id');
        abort_if($clientId === '', 404, 'Google sign-in is not enabled.');

        $data = $request->validate(['credential' => ['required', 'string', 'max:4096']]);

        try {
            $response = Http::timeout(8)->get(self::TOKENINFO_URL, ['id_token' => $data['credential']]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Google sign-in is temporarily unavailable. Please try again.'], 503);
        }

        $claims = $response->successful() ? $response->json() : null;
        $valid = is_array($claims)
            && ($claims['aud'] ?? null) === $clientId
            && in_array($claims['iss'] ?? null, self::ISSUERS, true)
            && (int) ($claims['exp'] ?? 0) > time()
            && in_array($claims['email_verified'] ?? null, [true, 'true'], true)
            && filter_var($claims['email'] ?? null, FILTER_VALIDATE_EMAIL);

        if (! $valid) {
            return response()->json(['message' => 'Google could not confirm your sign-in. Please try again.'], 422);
        }

        $email = mb_strtolower($claims['email']);
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user && $user->account_status !== 'active') {
            return response()->json(['message' => 'This account is not active.'], 403);
        }

        $created = false;
        if (! $user) {
            $user = User::create([
                'name' => Str::limit(trim((string) ($claims['name'] ?? '')) ?: Str::before($email, '@'), 255, ''),
                'email' => $email,
                // Random password: the person can set one later with "Forgot password".
                'password' => Str::password(40),
                'role' => 'buyer',
                'account_status' => 'active',
            ]);
            $created = true;
        }

        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return response()->json([
            'message' => 'Signed in with Google.',
            'token' => $user->createToken('butuanspaces-vue')->plainTextToken,
            'user' => AuthController::publicUserData($user->fresh()),
            'created' => $created,
        ], $created ? 201 : 200);
    }
}

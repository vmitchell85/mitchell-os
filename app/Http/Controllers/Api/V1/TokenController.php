<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTokenRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Signs a household member in from a native app and signs them out again.
 */
class TokenController extends Controller
{
    /**
     * Trade an email and password (plus a two-factor code when enabled) for an API token.
     */
    public function store(StoreTokenRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (
            ! $user
            || ! Hash::check($request->validated('password'), $user->password)
            || ! $user->hasVerifiedEmail()
        ) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($this->needsTwoFactorCode($request, $user)) {
            return response()->json([
                'message' => 'A two-factor authentication code is required.',
                'errors' => ['code' => ['A two-factor authentication code is required.']],
                'two_factor_required' => true,
            ], 422);
        }

        if (! $this->passesTwoFactorChallenge($request, $user)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        return response()->json([
            'token' => $user->createToken($request->validated('device_name'))->plainTextToken,
            'user' => (new UserResource($user))->resolve($request),
        ]);
    }

    /**
     * Revoke the token the request was made with.
     */
    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * Only called once the password is correct, so it reveals nothing to a guesser.
     */
    private function needsTwoFactorCode(StoreTokenRequest $request, User $user): bool
    {
        return $user->two_factor_secret
            && $user->two_factor_confirmed_at
            && ! $request->filled('code')
            && ! $request->filled('recovery_code');
    }

    private function passesTwoFactorChallenge(StoreTokenRequest $request, User $user): bool
    {
        if (! $user->two_factor_secret || ! $user->two_factor_confirmed_at) {
            return true;
        }

        if ($request->filled('code')) {
            return app(TwoFactorAuthenticationProvider::class)->verify(
                Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
                $request->validated('code'),
            );
        }

        if ($request->filled('recovery_code')) {
            $recoveryCode = collect($user->recoveryCodes())->first(
                fn (string $code): bool => hash_equals($code, $request->validated('recovery_code')),
            );

            if ($recoveryCode) {
                $user->replaceRecoveryCode($recoveryCode);

                return true;
            }
        }

        return false;
    }
}

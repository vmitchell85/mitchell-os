<?php

namespace App\NativeComponents\Auth;

use App\Services\Api\Session;
use App\Services\Api\TokenStorageException;
use App\Services\Tabs\TabPreferences;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Network;

/**
 * Trades an email and password (plus a two-factor code when needed) for an API token.
 */
class SignIn extends NativeComponent
{
    public string $serverUrl = '';

    public string $email = '';

    public string $password = '';

    public string $code = '';

    public bool $needsCode = false;

    public bool $loading = false;

    public string $error = '';

    public function mount(Session $session): void
    {
        $this->serverUrl = $session->serverUrl();
    }

    public function navTitle(): string
    {
        return 'Sign in';
    }

    public function showCodeField(): void
    {
        $this->needsCode = true;
    }

    public function signIn(Session $session, TabPreferences $tabs): void
    {
        $this->error = '';

        if (trim($this->serverUrl) === '' || trim($this->email) === '' || $this->password === '') {
            $this->error = 'Enter the server address, your email and your password.';

            return;
        }

        if (Network::status()?->connected === false) {
            $this->error = 'You are offline. Connect to your home Wi-Fi and try again.';

            return;
        }

        $this->loading = true;

        try {
            $session->setServerUrl($this->serverUrl);

            $response = Http::acceptJson()
                ->timeout(30)
                ->post($session->serverUrl().'/api/v1/tokens', array_filter([
                    'email' => trim($this->email),
                    'password' => $this->password,
                    'device_name' => 'Mitchell OS for iPhone',
                    'code' => trim($this->code) !== '' ? trim($this->code) : null,
                ]));
        } catch (ConnectionException) {
            $this->error = 'Could not reach the server. Check the address and that you are on the same network.';

            return;
        } finally {
            $this->loading = false;
        }

        if ($response->successful() && is_string($response->json('token'))) {
            try {
                $session->signIn($response->json('token'));
            } catch (TokenStorageException) {
                $this->error = 'Could not save your sign-in on this device.';

                return;
            }

            $this->password = '';
            $this->code = '';

            $this->replace($tabs->homeRoute());

            return;
        }

        $this->error = $this->errorFor($response->status(), $response->json());
    }

    public function render(): View
    {
        return view('native.auth.sign-in');
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function errorFor(int $status, ?array $body): string
    {
        if ($status === 422) {
            $this->needsCode = true;

            if (($body['two_factor_required'] ?? false) === true) {
                return 'Enter the code from your authenticator app.';
            }

            return (string) ($body['errors']['email'][0] ?? $body['message'] ?? 'Those details were not accepted.');
        }

        return match (true) {
            $status === 429 => 'Too many attempts. Wait a minute and try again.',
            $status >= 500 => 'The server had a problem. Try again in a moment.',
            default => 'Sign in failed. Check the server address and try again.',
        };
    }
}

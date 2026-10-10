<?php

namespace App\Services\Api;

use Native\Mobile\SecureStorage;

/**
 * The device's sign-in state: the API token and the server URL, both kept in
 * the iOS keychain through SecureStorage.
 */
class Session
{
    public const TOKEN_KEY = 'mitchell.token';

    public const SERVER_URL_KEY = 'mitchell.server_url';

    public function __construct(protected SecureStorage $storage) {}

    public function token(): ?string
    {
        $token = $this->storage->get(self::TOKEN_KEY);

        return $token === null || $token === '' ? null : $token;
    }

    public function isSignedIn(): bool
    {
        return $this->token() !== null;
    }

    /**
     * @throws TokenStorageException When secure storage refuses the token.
     */
    public function signIn(string $token): void
    {
        if ($this->storage->set(self::TOKEN_KEY, $token) !== true) {
            throw new TokenStorageException;
        }
    }

    public function signOut(): void
    {
        $this->storage->delete(self::TOKEN_KEY);
    }

    /**
     * The saved server URL, or the `services.mitchell.url` default.
     */
    public function serverUrl(): string
    {
        $saved = $this->storage->get(self::SERVER_URL_KEY);

        return $this->normalizeUrl($saved !== null && $saved !== '' ? $saved : (string) config('services.mitchell.url'));
    }

    /**
     * Saves the server URL; a blank value goes back to the config default.
     */
    public function setServerUrl(string $url): void
    {
        $url = trim($url);

        if ($url === '') {
            $this->storage->delete(self::SERVER_URL_KEY);

            return;
        }

        $this->storage->set(self::SERVER_URL_KEY, $this->normalizeUrl($url));
    }

    protected function normalizeUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = 'http://'.$url;
        }

        return $url;
    }
}

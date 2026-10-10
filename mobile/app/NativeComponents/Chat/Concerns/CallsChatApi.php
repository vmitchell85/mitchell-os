<?php

namespace App\NativeComponents\Chat\Concerns;

use App\Services\Api\ApiClient;
use App\Services\Api\Session;
use App\Services\Api\UnauthenticatedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * API plumbing shared by the chat screens.
 */
trait CallsChatApi
{
    /**
     * Runs an API call, sending the user to sign in if the token was rejected.
     * Returns null when the server cannot be reached.
     *
     * @param  callable(ApiClient): Response  $call
     */
    protected function request(callable $call): ?Response
    {
        try {
            return $call(app(ApiClient::class));
        } catch (UnauthenticatedException) {
            app(Session::class)->signOut();
            $this->replace('/sign-in');
        } catch (Throwable) {
            // Connection failures and timeouts fall through to the caller.
        }

        return null;
    }

    protected function errorMessage(?Response $response, string $fallback): string
    {
        if ($response === null) {
            return 'Could not reach the server. Check your connection and try again.';
        }

        $first = collect($response->json('errors', []))->flatten()->first();

        if (is_string($first)) {
            return $first;
        }

        $message = $response->json('message');

        return is_string($message) && $message !== '' ? $message : $fallback;
    }

    /**
     * The API sends UTC; people read Eastern time.
     */
    protected function easternTime(?string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return '';
        }

        return CarbonImmutable::parse($timestamp)->setTimezone('America/New_York')->format('M j, g:i A');
    }
}

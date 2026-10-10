<?php

namespace App\Services\Api;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the Mitchell OS server's /api/v1 with the saved bearer token.
 * Paths are relative to /api/v1, e.g. `get('/meals')`.
 */
class ApiClient
{
    public function __construct(protected Session $session) {}

    /**
     * @param  array<string, mixed>  $data  Query string parameters.
     *
     * @throws UnauthenticatedException
     */
    public function get(string $path, array $data = []): Response
    {
        return $this->send('get', $path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws UnauthenticatedException
     */
    public function post(string $path, array $data = []): Response
    {
        return $this->send('post', $path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws UnauthenticatedException
     */
    public function put(string $path, array $data = []): Response
    {
        return $this->send('put', $path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws UnauthenticatedException
     */
    public function patch(string $path, array $data = []): Response
    {
        return $this->send('patch', $path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws UnauthenticatedException
     */
    public function delete(string $path, array $data = []): Response
    {
        return $this->send('delete', $path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(string $method, string $path, array $data): Response
    {
        $response = $this->request()->{$method}($this->url($path), $data);

        if ($response->status() === 401) {
            throw new UnauthenticatedException;
        }

        return $response;
    }

    protected function request(): PendingRequest
    {
        $request = Http::acceptJson()->timeout(30);

        $token = $this->session->token();

        return $token === null ? $request : $request->withToken($token);
    }

    protected function url(string $path): string
    {
        return $this->session->serverUrl().'/api/v1/'.ltrim($path, '/');
    }
}

<?php

use App\Services\Api\ApiClient;
use App\Services\Api\Session;
use App\Services\Api\UnauthenticatedException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->session = Mockery::mock(Session::class);
    $this->session->shouldReceive('serverUrl')->andReturn('http://10.0.0.5:8000');
    $this->session->shouldReceive('token')->andReturn('secret-token');
    $this->app->instance(Session::class, $this->session);
});

it('sends the bearer token to the versioned API url', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    $response = app(ApiClient::class)->get('/meals', ['week' => '2026-10-05']);

    expect($response->json('data'))->toBe([]);

    Http::assertSent(fn (Request $request) => $request->url() === 'http://10.0.0.5:8000/api/v1/meals?week=2026-10-05'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request->hasHeader('Accept', 'application/json'));
});

it('sends request bodies for write methods', function (string $method) {
    Http::fake(['*' => Http::response(['ok' => true])]);

    app(ApiClient::class)->{$method}('recipes/1', ['title' => 'Soup']);

    Http::assertSent(fn (Request $request) => $request->method() === strtoupper($method)
        && $request->url() === 'http://10.0.0.5:8000/api/v1/recipes/1'
        && $request['title'] === 'Soup');
})->with(['post', 'put', 'patch', 'delete']);

it('throws on a 401', function () {
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    app(ApiClient::class)->get('/meals');
})->throws(UnauthenticatedException::class);

it('returns other error responses without throwing', function () {
    Http::fake(['*' => Http::response(['message' => 'Nope'], 422)]);

    $response = app(ApiClient::class)->post('/meals', []);

    expect($response->status())->toBe(422);
});

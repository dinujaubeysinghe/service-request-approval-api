<?php

declare(strict_types=1);

use App\Models\User;

it('logs in with valid credentials and returns a token', function () {
    $user = User::factory()->manager()->create(['email' => 'boss@example.com']);

    $this->postJson('/api/login', ['email' => 'boss@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.role', 'manager')
        ->assertJsonMissingPath('user.password');
});

it('lets a token from login access protected routes', function () {
    User::factory()->create(['email' => 'a@example.com']);

    $token = $this->postJson('/api/login', ['email' => 'a@example.com', 'password' => 'password'])
        ->json('token');

    $this->withToken($token)->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('user', null)
        ->assertJsonPath('data.email', 'a@example.com');
});

it('gives the same error for a wrong password and an unknown email', function () {
    User::factory()->create(['email' => 'a@example.com']);

    $wrongPassword = $this->postJson('/api/login', ['email' => 'a@example.com', 'password' => 'nope']);
    $unknownEmail = $this->postJson('/api/login', ['email' => 'ghost@example.com', 'password' => 'password']);

    $wrongPassword->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
    expect($unknownEmail->json())->toBe($wrongPassword->json());
});

it('validates the login payload', function () {
    $this->postJson('/api/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors(['email', 'password']);
});

it('throttles repeated login attempts', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', ['email' => 'a@example.com', 'password' => 'x']);
    }

    $this->postJson('/api/login', ['email' => 'a@example.com', 'password' => 'x'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests');
});

it('returns 401 JSON for protected routes without a token', function () {
    $this->get('/api/me')
        ->assertUnauthorized()
        ->assertJson(['code' => 'unauthenticated', 'errors' => []]);
});

it('returns 404 JSON for unknown API routes', function () {
    $this->getJson('/api/does-not-exist')
        ->assertNotFound()
        ->assertJson(['code' => 'not_found']);
});

<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('registers a user and returns a token', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.email', 'ada@example.com')
        ->assertJsonPath('user.is_guest', false)
        ->assertJsonStructure(['token']);

    $this->withToken($response->json('token'))
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.name', 'Ada');
});

it('validates registration input', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/auth/register', [
        'name' => '',
        'email' => 'taken@example.com',
        'password' => 'short',
        'password_confirmation' => 'different',
    ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
});

it('logs in with valid credentials', function () {
    User::factory()->create(['email' => 'ada@example.com', 'password' => 'secret-password']);

    $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'secret-password'])
        ->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'email']]);
});

it('rejects invalid credentials', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('revokes the token on logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('web')->plainTextToken;

    $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});

it('requires authentication for protected routes', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/auth/me'],
    ['GET', '/api/documents'],
    ['POST', '/api/documents'],
    ['GET', '/api/conversations'],
    ['POST', '/api/chat'],
]);

it('does not expose sensitive user fields', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/auth/me')->assertOk()->assertJsonMissingPath('data.password');
});

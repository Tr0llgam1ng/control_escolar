<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_requests_without_service_key_are_rejected(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'a@a.com', 'password' => 'x'])->assertUnauthorized();
    }

    public function test_login_validate_and_logout_flow(): void
    {
        $user = User::factory()->create(['email' => 'ana@escolar.test', 'role' => 'teacher']);

        $login = $this->asGateway(null, null)->postJson('/api/auth/login', ['email' => 'ANA@escolar.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'email', 'role']]);

        $token = $login->json('token');
        $this->assertDatabaseMissing('access_tokens', ['token_hash' => $token]); // stored hashed

        $this->asGateway(null, null)->postJson('/api/auth/validate', ['token' => $token])
            ->assertOk()->assertJsonPath('user.id', $user->id);

        $this->asGateway(null, null)->postJson('/api/auth/logout', ['token' => $token])->assertJsonPath('revoked', true);
        $this->asGateway(null, null)->postJson('/api/auth/validate', ['token' => $token])->assertUnauthorized();
    }

    public function test_wrong_password_and_inactive_users_cannot_log_in(): void
    {
        User::factory()->create(['email' => 'x@escolar.test']);
        User::factory()->create(['email' => 'off@escolar.test', 'is_active' => false]);

        $this->asGateway(null, null)->postJson('/api/auth/login', ['email' => 'x@escolar.test', 'password' => 'nope'])->assertUnauthorized();
        $this->asGateway(null, null)->postJson('/api/auth/login', ['email' => 'off@escolar.test', 'password' => 'password'])->assertForbidden();
    }

    public function test_only_admin_manages_users(): void
    {
        $payload = ['name' => 'Luis', 'email' => 'luis@escolar.test', 'password' => 'secret123', 'role' => 'teacher'];

        $this->asGateway(5, 'teacher')->postJson('/api/users', $payload)->assertForbidden();

        $id = $this->asGateway(1, 'admin')->postJson('/api/users', $payload)->assertCreated()->json('id');
        $this->asGateway(1, 'admin')->getJson('/api/users?role=teacher')->assertJsonCount(1, 'data');
        $this->asGateway(1, 'admin')->putJson("/api/users/{$id}", ['is_active' => false])->assertJsonPath('is_active', false);
        $this->asGateway(1, 'admin')->deleteJson("/api/users/{$id}")->assertNoContent();
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'dup@escolar.test']);

        $this->asGateway(1, 'admin')->postJson('/api/users', [
            'name' => 'Dup', 'email' => 'dup@escolar.test', 'password' => 'secret123', 'role' => 'student',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }
}

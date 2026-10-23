<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Empresa Auth Test',
            'slug' => 'empresa-auth-test',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'name' => 'Usuario Auth Test',
            'email' => 'auth-test@example.test',
            'password' => Hash::make('ClaveSegura2026!'),
            'company_id' => $this->company->id,
        ]);

        $this->user->role = 'company_admin';
        $this->user->save();
    }

    public function test_login_with_valid_credentials_returns_token(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'auth-test@example.test',
            'password' => 'ClaveSegura2026!',
        ]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'auth-test@example.test')
            ->assertJsonPath('user.role', 'company_admin')
            ->assertJsonStructure([
                'message',
                'token',
                'token_type',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'company_id',
                    'role',
                ],
            ]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_rejects_incorrect_password(): void
    {
        $this->postJson('/api/login', [
            'email' => 'auth-test@example.test',
            'password' => 'ClaveIncorrecta2026!',
        ])->assertUnauthorized()
          ->assertJsonPath(
              'message',
              'Credenciales incorrectas.'
          );
    }

    public function test_login_rejects_nonexistent_user(): void
    {
        $this->postJson('/api/login', [
            'email' => 'inexistente@example.test',
            'password' => 'ClaveSegura2026!',
        ])->assertUnauthorized();
    }

    public function test_login_rejects_inactive_company(): void
    {
        $this->company->status = 'inactive';
        $this->company->save();

        $this->postJson('/api/login', [
            'email' => 'auth-test@example.test',
            'password' => 'ClaveSegura2026!',
        ])->assertForbidden();
    }

    public function test_login_requires_valid_email_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
                'password',
            ]);
    }

    public function test_token_can_access_authenticated_user_endpoint(): void
    {
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'auth-test@example.test',
            'password' => 'ClaveSegura2026!',
        ])->assertOk();

        $token = $loginResponse->json('token');

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath(
                'email',
                'auth-test@example.test'
            )
            ->assertJsonPath(
                'company_id',
                $this->company->id
            );
    }
}

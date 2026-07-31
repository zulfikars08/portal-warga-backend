<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CurrentUserEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_superadmin_me_contains_all_seeded_permissions(): void
    {
        $this->seed(InitialSeeder::class);
        $user = User::where('email', 'superadmin@portalwarga.test')->firstOrFail();

        $response = $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

        $response->assertJsonPath('name', 'Super Admin')
            ->assertJsonPath('roles.0', 'superadmin');

        $this->assertEqualsCanonicalizing(
            Permission::pluck('name')->all(),
            $response->json('permissions'),
        );
    }

    public function test_me_does_not_require_users_view_and_returns_only_owned_permissions(): void
    {
        Permission::create(['name' => 'dashboard.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'houses.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('dashboard.view');

        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('permissions', ['dashboard.view'])
            ->assertJsonPath('roles', []);
    }
}
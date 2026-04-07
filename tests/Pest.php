<?php

declare(strict_types=1);
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses(
    TestCase::class,
    RefreshDatabase::class,
)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeAccessibleTenant', function () {
    return $this->toBeInstanceOf(Tenant::class)
        ->and($this->value->isAccessible())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function createTenant(array $attributes = []): Tenant
{
    return Tenant::factory()->create($attributes);
}

function createUser(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

function createAdminUser(?Tenant $tenant = null): User
{
    return User::factory()->admin()->create([
        'tenant_id' => $tenant?->id ?? createTenant()->id,
    ]);
}

function createSuperAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

function actingAsUser(User $user): User
{
    test()->actingAs($user, 'sanctum');

    return $user;
}

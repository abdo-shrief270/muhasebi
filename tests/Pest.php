<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses(
    Tests\TestCase::class,
    Illuminate\Foundation\Testing\RefreshDatabase::class,
)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeAccessibleTenant', function () {
    return $this->toBeInstanceOf(\App\Domain\Tenant\Models\Tenant::class)
        ->and($this->value->isAccessible())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function createTenant(array $attributes = []): \App\Domain\Tenant\Models\Tenant
{
    return \App\Domain\Tenant\Models\Tenant::factory()->create($attributes);
}

function createUser(array $attributes = []): \App\Models\User
{
    return \App\Models\User::factory()->create($attributes);
}

function createAdminUser(?\App\Domain\Tenant\Models\Tenant $tenant = null): \App\Models\User
{
    return \App\Models\User::factory()->admin()->create([
        'tenant_id' => $tenant?->id ?? createTenant()->id,
    ]);
}

function createSuperAdmin(): \App\Models\User
{
    return \App\Models\User::factory()->superAdmin()->create();
}

function actingAsUser(\App\Models\User $user): \App\Models\User
{
    test()->actingAs($user, 'sanctum');

    return $user;
}

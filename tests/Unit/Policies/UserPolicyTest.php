<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->policy = new UserPolicy;
});

test('admin can view any users', function (): void {
    $admin = User::factory()->admin()->create();

    expect($this->policy->viewAny($admin))->toBeTrue();
});

test('non-admin cannot view any users', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    expect($this->policy->viewAny($user))->toBeFalse();
});

test('admin can view any user', function (): void {
    $admin = User::factory()->admin()->create();
    $otherUser = User::factory()->create();

    expect($this->policy->view($admin, $otherUser))->toBeTrue();
});

test('user can view themselves', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    expect($this->policy->view($user, $user))->toBeTrue();
});

test('user cannot view other users', function (): void {
    $user = User::factory()->create(['is_admin' => false]);
    $otherUser = User::factory()->create();

    expect($this->policy->view($user, $otherUser))->toBeFalse();
});

test('admin can create users', function (): void {
    $admin = User::factory()->admin()->create();

    expect($this->policy->create($admin))->toBeTrue();
});

test('non-admin cannot create users', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    expect($this->policy->create($user))->toBeFalse();
});

test('admin can update any user', function (): void {
    $admin = User::factory()->admin()->create();
    $otherUser = User::factory()->create();

    expect($this->policy->update($admin, $otherUser))->toBeTrue();
});

test('user can update themselves', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    expect($this->policy->update($user, $user))->toBeTrue();
});

test('user cannot update other users', function (): void {
    $user = User::factory()->create(['is_admin' => false]);
    $otherUser = User::factory()->create();

    expect($this->policy->update($user, $otherUser))->toBeFalse();
});

test('admin can delete non-admin users', function (): void {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['is_admin' => false]);

    expect($this->policy->delete($admin, $user))->toBeTrue();
});

test('admin cannot delete themselves', function (): void {
    $admin = User::factory()->admin()->create();

    expect($this->policy->delete($admin, $admin))->toBeFalse();
});

test('non-admin cannot delete users', function (): void {
    $user = User::factory()->create(['is_admin' => false]);
    $otherUser = User::factory()->create();

    expect($this->policy->delete($user, $otherUser))->toBeFalse();
});

test('cannot delete last admin', function (): void {
    $admin1 = User::factory()->admin()->create();

    expect($this->policy->delete($admin1, $admin1))->toBeFalse();
});

test('admin can delete another admin when multiple admins exist', function (): void {
    $admin1 = User::factory()->admin()->create();
    $admin2 = User::factory()->admin()->create();

    expect($this->policy->delete($admin1, $admin2))->toBeTrue();
});

test('admin can restore users', function (): void {
    $admin = User::factory()->admin()->create();

    expect($this->policy->restore($admin))->toBeTrue();
});

test('non-admin cannot restore users', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    expect($this->policy->restore($user))->toBeFalse();
});

test('admin can force delete users', function (): void {
    $admin = User::factory()->admin()->create();

    expect($this->policy->forceDelete($admin))->toBeTrue();
});

test('non-admin cannot force delete users', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    expect($this->policy->forceDelete($user))->toBeFalse();
});

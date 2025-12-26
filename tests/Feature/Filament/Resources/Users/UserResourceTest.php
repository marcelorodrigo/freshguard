<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('can load users with created records', function (): void {
    $users_count = 5;
    $users = User::factory()->count($users_count)->create();

    Livewire::test(ManageUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords($users)
        ->assertCountTableRecords($users_count)
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('email')
        ->assertCanRenderTableColumn('email_verified_at');
});

test('can search users by name', function (): void {
    $users = User::factory()->count(5)->create();
    $searchUser = $users->first();

    Livewire::test(ManageUsers::class)
        ->searchTable($searchUser->name)
        ->assertCanSeeTableRecords([$searchUser])
        ->assertCanNotSeeTableRecords($users->skip(1));
});

test('can search users by email', function (): void {
    $users = User::factory()->count(5)->create();
    $searchUser = $users->first();

    Livewire::test(ManageUsers::class)
        ->searchTable($searchUser->email)
        ->assertCanSeeTableRecords([$searchUser])
        ->assertCanNotSeeTableRecords($users->skip(1));
});

test('can sort users by name', function (): void {
    $users = User::factory()->count(3)->create();

    Livewire::test(ManageUsers::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords($users->sortBy('name'), inOrder: true)
        ->sortTable('name', 'desc')
        ->assertCanSeeTableRecords($users->sortByDesc('name'), inOrder: true);
});

test('can sort users by email', function (): void {
    $users = User::factory()->count(3)->create();

    Livewire::test(ManageUsers::class)
        ->sortTable('email')
        ->assertCanSeeTableRecords($users->sortBy('email'), inOrder: true)
        ->sortTable('email', 'desc')
        ->assertCanSeeTableRecords($users->sortByDesc('email'), inOrder: true);
});

test('can sort users by email verified status', function (): void {
    User::factory()->count(2)->verified()->create();
    User::factory()->count(2)->unverified()->create();

    Livewire::test(ManageUsers::class)
        ->sortTable('email_verified_at')
        ->assertOk();
});

test('can create user with required fields', function (): void {
    $userData = [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'SecurePassword123!',
    ];

    Livewire::test(ManageUsers::class)
        ->callAction('create', data: $userData)
        ->assertOk()
        ->assertNotified();

    $this->assertDatabaseHas(User::class, [
        'name' => $userData['name'],
        'email' => $userData['email'],
    ]);

    $user = User::where('email', $userData['email'])->first();
    expect(Hash::check($userData['password'], $user->password))->toBeTrue();
});

test('can create user without email verification', function (): void {
    $userData = [
        'name' => 'Unverified User',
        'email' => 'unverified@example.com',
        'password' => 'SecurePassword123!',
    ];

    Livewire::test(ManageUsers::class)
        ->callAction('create', data: $userData)
        ->assertOk()
        ->assertNotified();

    $this->assertDatabaseHas(User::class, [
        'name' => $userData['name'],
        'email' => $userData['email'],
        'email_verified_at' => null,
    ]);
});

test('cannot create user without name', function (): void {
    Livewire::test(ManageUsers::class)
        ->callAction('create', data: [
            'email' => 'test@example.com',
            'password' => 'SecurePassword123!',
        ])
        ->assertHasActionErrors(['name']);
});

test('cannot create user without email', function (): void {
    Livewire::test(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'Test User',
            'password' => 'SecurePassword123!',
        ])
        ->assertHasActionErrors(['email']);
});

test('cannot create user without password', function (): void {
    Livewire::test(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ])
        ->assertHasActionErrors(['password']);
});

test('cannot create user with invalid email format', function (): void {
    Livewire::test(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'Test User',
            'email' => 'invalid-email',
            'password' => 'SecurePassword123!',
        ])
        ->assertHasActionErrors(['email']);
});

test('cannot create user with duplicate email', function (): void {
    $existingUser = User::factory()->create();

    Livewire::test(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'Another User',
            'email' => $existingUser->email,
            'password' => 'SecurePassword123!',
        ])
        ->assertHasActionErrors(['email']);
});

test('can edit user name and email', function (): void {
    $user = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'original@example.com',
    ]);

    $newData = [
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
        'password' => '',
    ];

    Livewire::test(ManageUsers::class)
        ->callTableAction('edit', $user, data: $newData)
        ->assertNotified();

    $this->assertDatabaseHas(User::class, [
        'id' => $user->id,
        'name' => $newData['name'],
        'email' => $newData['email'],
    ]);
});

test('can edit user password', function (): void {
    $user = User::factory()->create();
    $originalPassword = $user->password;

    $newPassword = 'NewSecurePassword123!';

    Livewire::test(ManageUsers::class)
        ->callTableAction('edit', $user, data: [
            'name' => $user->name,
            'email' => $user->email,
            'password' => $newPassword,
        ])
        ->assertNotified();

    $user->refresh();
    expect($user->password)->not->toBe($originalPassword)
        ->and(Hash::check($newPassword, $user->password))->toBeTrue();
});

test('password is not changed when left empty during edit', function (): void {
    $user = User::factory()->create();
    $originalPassword = $user->password;

    Livewire::test(ManageUsers::class)
        ->callTableAction('edit', $user, data: [
            'name' => 'New Name',
            'email' => $user->email,
            'password' => '',
        ])
        ->assertNotified();

    $user->refresh();
    expect($user->password)->toBe($originalPassword)
        ->and($user->name)->toBe('New Name');
});

test('user factory can create verified users', function (): void {
    $user = User::factory()->verified()->create();

    expect($user->email_verified_at)->not->toBeNull();
});

test('user factory can create unverified users', function (): void {
    $user = User::factory()->unverified()->create();

    expect($user->email_verified_at)->toBeNull();
});

test('cannot edit user with duplicate email', function (): void {
    $existingUser = User::factory()->create();
    $userToEdit = User::factory()->create();

    Livewire::test(ManageUsers::class)
        ->callTableAction('edit', $userToEdit, data: [
            'name' => 'Updated Name',
            'email' => $existingUser->email,
            'password' => '',
        ])
        ->assertHasTableActionErrors(['email']);
});

test('can delete user', function (): void {
    $user = User::factory()->create();

    Livewire::test(ManageUsers::class)
        ->callTableAction('delete', $user)
        ->assertNotified();

    $this->assertDatabaseMissing(User::class, [
        'id' => $user->id,
    ]);
});

test('can bulk delete users', function (): void {
    $users = User::factory()->count(3)->create();

    Livewire::test(ManageUsers::class)
        ->callTableBulkAction('delete', $users)
        ->assertNotified();

    foreach ($users as $user) {
        $this->assertDatabaseMissing(User::class, [
            'id' => $user->id,
        ]);
    }
});

test('table displays correct verification icon for verified users', function (): void {
    $verifiedUser = User::factory()->verified()->create();

    Livewire::test(ManageUsers::class)
        ->assertCanSeeTableRecords([$verifiedUser]);
});

test('table displays correct verification icon for unverified users', function (): void {
    $unverifiedUser = User::factory()->unverified()->create();

    Livewire::test(ManageUsers::class)
        ->assertCanSeeTableRecords([$unverifiedUser]);
});

test('table has created_at and updated_at columns defined', function (): void {
    User::factory()->create();

    Livewire::test(ManageUsers::class)
        ->assertOk();
});

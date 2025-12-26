<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

test('can render page and see table records', function (): void {
    $users = User::factory()->count(5)->create();

    livewire(ManageUsers::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($users)
        ->assertCountTableRecords(5)
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('email')
        ->assertCanRenderTableColumn('email_verified_at');
});

test('can search users by name', function (): void {
    $users = User::factory()->count(5)->create();
    $searchUser = $users->first();

    livewire(ManageUsers::class)
        ->searchTable($searchUser->name)
        ->assertCanSeeTableRecords([$searchUser])
        ->assertCanNotSeeTableRecords($users->skip(1));
});

test('can search users by email', function (): void {
    $users = User::factory()->count(5)->create();
    $searchUser = $users->first();

    livewire(ManageUsers::class)
        ->searchTable($searchUser->email)
        ->assertCanSeeTableRecords([$searchUser])
        ->assertCanNotSeeTableRecords($users->skip(1));
});

test('can sort users by name', function (): void {
    $users = User::factory()->count(3)->create();

    livewire(ManageUsers::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords($users->sortBy('name'), inOrder: true)
        ->sortTable('name', 'desc')
        ->assertCanSeeTableRecords($users->sortByDesc('name'), inOrder: true);
});

test('can sort users by email', function (): void {
    $users = User::factory()->count(3)->create();

    livewire(ManageUsers::class)
        ->sortTable('email')
        ->assertCanSeeTableRecords($users->sortBy('email'), inOrder: true)
        ->sortTable('email', 'desc')
        ->assertCanSeeTableRecords($users->sortByDesc('email'), inOrder: true);
});

test('can sort users by email verified status', function (): void {
    User::factory()->count(2)->verified()->create();
    User::factory()->count(2)->unverified()->create();

    livewire(ManageUsers::class)
        ->sortTable('email_verified_at')
        ->assertSuccessful();
});

test('can create user with required fields', function (): void {
    $userData = [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'SecurePassword123!',
    ];

    livewire(ManageUsers::class)
        ->callAction('create', data: $userData)
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

    livewire(ManageUsers::class)
        ->callAction('create', data: $userData)
        ->assertNotified();

    $this->assertDatabaseHas(User::class, [
        'name' => $userData['name'],
        'email' => $userData['email'],
        'email_verified_at' => null,
    ]);
});

test('validates user creation data', function (array $data, array $errors): void {
    livewire(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePassword123!',
            ...$data,
        ])
        ->assertHasActionErrors($errors);
})->with([
    'name is required' => [['name' => null], ['name' => 'required']],
    'name max 255 characters' => [['name' => Str::random(256)], ['name' => 'max']],
    'email is required' => [['email' => null], ['email' => 'required']],
    'email must be valid' => [['email' => 'invalid-email'], ['email' => 'email']],
    'email must be unique' => [fn () => ['email' => User::factory()->create()->email], ['email' => 'unique']],
    'password is required' => [['password' => null], ['password' => 'required']],
]);

test('can edit user name and email', function (): void {
    $user = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'original@example.com',
    ]);

    livewire(ManageUsers::class)
        ->callTableAction('edit', $user, data: [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'password' => '',
        ])
        ->assertNotified();

    $this->assertDatabaseHas(User::class, [
        'id' => $user->id,
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ]);
});

test('can edit user password', function (): void {
    $user = User::factory()->create();
    $originalPassword = $user->password;
    $newPassword = 'NewSecurePassword123!';

    livewire(ManageUsers::class)
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

    livewire(ManageUsers::class)
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

test('cannot edit user with duplicate email', function (): void {
    $existingUser = User::factory()->create();
    $userToEdit = User::factory()->create();

    livewire(ManageUsers::class)
        ->callTableAction('edit', $userToEdit, data: [
            'name' => 'Updated Name',
            'email' => $existingUser->email,
            'password' => '',
        ])
        ->assertHasTableActionErrors(['email']);
});

test('can delete user', function (): void {
    $user = User::factory()->create();

    livewire(ManageUsers::class)
        ->callTableAction('delete', $user)
        ->assertNotified();

    $this->assertDatabaseMissing(User::class, [
        'id' => $user->id,
    ]);
});

test('can bulk delete users', function (): void {
    $users = User::factory()->count(3)->create();

    livewire(ManageUsers::class)
        ->callTableBulkAction('delete', $users)
        ->assertNotified();

    foreach ($users as $user) {
        $this->assertDatabaseMissing(User::class, [
            'id' => $user->id,
        ]);
    }
});

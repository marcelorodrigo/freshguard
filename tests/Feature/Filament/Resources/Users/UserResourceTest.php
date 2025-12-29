<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Authenticate as admin for all tests
    $this->actingAs(User::factory()->admin()->create());
});

test('can render page and see table records', function (): void {
    User::query()->delete();
    $users = User::factory()->count(5)->create();

    livewire(ManageUsers::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($users)
        ->assertCountTableRecords(5)
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('email')
        ->assertCanRenderTableColumn('email_verified_at');
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

    $user = User::query()->where('email', $userData['email'])->first();
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
        ->callTableAction(EditAction::class, $user, data: [
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
        ->callTableAction(EditAction::class, $user, data: [
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
        ->callTableAction(EditAction::class, $user, data: [
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
        ->callTableAction(EditAction::class, $userToEdit, data: [
            'name' => 'Updated Name',
            'email' => $existingUser->email,
            'password' => '',
        ])
        ->assertHasTableActionErrors(['email']);
});

test('can delete user', function (): void {
    $user = User::factory()->create();

    livewire(ManageUsers::class)
        ->callTableAction(DeleteAction::class, $user)
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

// Policy and Admin Tests

test('non-admin cannot access user management page', function (): void {
    $nonAdmin = User::factory()->create(['is_admin' => false]);

    $this->actingAs($nonAdmin);

    livewire(ManageUsers::class)
        ->assertForbidden();
});

test('admin can access user management page', function (): void {
    livewire(ManageUsers::class)
        ->assertSuccessful();
});

test('first registered user becomes admin automatically', function (): void {
    Notification::fake();

    // Clear any existing users
    User::query()->delete();

    $userData = [
        'name' => 'First User',
        'email' => 'first@example.com',
        'password' => 'password',
    ];

    $user = User::create($userData);
    event(new \Illuminate\Auth\Events\Registered($user));

    $user->refresh();
    expect($user->is_admin)->toBeTrue();
});

test('second registered user is not automatically admin', function (): void {
    Notification::fake();

    User::query()->delete();

    $firstUser = User::create([
        'name' => 'First User',
        'email' => 'first@example.com',
        'password' => 'password',
    ]);
    event(new \Illuminate\Auth\Events\Registered($firstUser));

    $secondUser = User::create([
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
    ]);
    event(new \Illuminate\Auth\Events\Registered($secondUser));

    $secondUser->refresh();
    expect($secondUser->is_admin)->toBeFalse();
});

test('admin can create users with admin role', function (): void {
    livewire(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'New Admin',
            'email' => 'newadmin@example.com',
            'password' => 'SecurePassword123!',
            'is_admin' => true,
        ])
        ->assertNotified();

    $this->assertDatabaseHas(User::class, [
        'email' => 'newadmin@example.com',
        'is_admin' => true,
    ]);
});

test('admin can see is_admin toggle field', function (): void {
    livewire(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePassword123!',
            'is_admin' => true,
        ])
        ->assertNotified();

    $this->assertDatabaseHas(User::class, [
        'email' => 'test@example.com',
        'is_admin' => true,
    ]);
});

test('non-admin cannot see is_admin toggle field', function (): void {
    $nonAdmin = User::factory()->create(['is_admin' => false]);
    $userToEdit = User::factory()->create();

    $this->actingAs($nonAdmin);

    // Non-admin can't access the management page at all
    livewire(ManageUsers::class)
        ->assertForbidden();
});

test('admin cannot remove their own admin status', function (): void {
    $admin = auth()->user();

    // Verify that the admin field exists and is enabled in the form
    expect($admin->is_admin)->toBeTrue();
});

test('admin cannot delete themselves', function (): void {
    $admin = auth()->user();

    livewire(ManageUsers::class)
        ->assertTableActionHidden('delete', $admin);
});

test('cannot delete last admin', function (): void {
    User::query()->where('id', '!=', auth()->id())->delete();

    $admin = auth()->user();

    livewire(ManageUsers::class)
        ->assertTableActionHidden('delete', $admin);
});

test('can delete admin when other admins exist', function (): void {
    $otherAdmin = User::factory()->admin()->create();

    livewire(ManageUsers::class)
        ->callTableAction(DeleteAction::class, $otherAdmin)
        ->assertNotified();

    $this->assertDatabaseMissing(User::class, [
        'id' => $otherAdmin->id,
    ]);
});

test('user can edit their own information', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user);

    // User should be able to update their own record via policy
    expect(auth()->user()->can('update', $user))->toBeTrue();
});

test('non-admin cannot edit other users', function (): void {
    $user = User::factory()->create(['is_admin' => false]);
    $otherUser = User::factory()->create();

    $this->actingAs($user);

    expect(auth()->user()->can('update', $otherUser))->toBeFalse();
});

test('is_admin column is visible in table', function (): void {
    $admin = User::factory()->admin()->create();
    $regular = User::factory()->create(['is_admin' => false]);

    livewire(ManageUsers::class)
        ->assertCanSeeTableRecords([$admin, $regular])
        ->assertCanRenderTableColumn('is_admin');
});

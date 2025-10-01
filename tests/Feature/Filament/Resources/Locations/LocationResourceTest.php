<?php
declare(strict_types=1);

namespace Feature\Filament\Resources\Locations;

use App\Filament\Resources\Locations\Pages\ManageLocations;
use App\Models\Location;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

class LocationResourceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_can_load_locations_with_created_records(): void
    {
        $locations_count = 5;
        $parent = Location::factory()->create(['name' => 'Parent Location']);
        $locations = Location::factory()
            ->count($locations_count)
            ->sequence(
                ['parent_id' => $parent->id],
                ['parent_id' => null]
            )
            ->create();

        Livewire::test(ManageLocations::class)
            ->assertOk()
            ->assertCanSeeTableRecords($locations)
            ->assertCountTableRecords($locations_count + 1)
            ->assertCanRenderTableColumn('name')
            ->assertCanRenderTableColumn('description')
            ->assertCanRenderTableColumn('parent.name');
    }

    public function test_can_create_location(): void
    {
        $location = Location::factory()->make();

        Livewire::test(ManageLocations::class)
            ->callAction('create', data: [
                'name' => $location->name,
                'description' => $location->description,
                'expiration_notify_days' => $location->expiration_notify_days,
                'parent_id' => null
            ])
            ->assertOk()
            ->assertNotified();

        $this->assertDatabaseHas(Location::class, [
            'name' => $location->name,
            'description' => $location->description,
            'expiration_notify_days' => $location->expiration_notify_days,
            'parent_id' => null
        ]);
    }

    public function test_can_edit_location(): void
    {
        $location = Location::factory()->create([
            'name' => 'Original Name',
            'description' => 'Original Description',
            'expiration_notify_days' => 10,
            'parent_id' => null
        ]);

        $newData = [
            'name' => 'Updated Name',
            'description' => 'Updated Description',
            'expiration_notify_days' => 20,
            'parent_id' => null
        ];

        Livewire::test(ManageLocations::class)
            ->callTableAction('edit', $location, data: $newData)
            ->assertNotified();

        $this->assertDatabaseHas(Location::class, [
            'id' => $location->id,
            ...$newData
        ]);
    }
}

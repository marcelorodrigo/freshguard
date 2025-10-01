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

}

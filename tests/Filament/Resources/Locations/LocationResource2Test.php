<?php

namespace Tests\Filament\Resources\Locations;

use App\Filament\Resources\Locations\Pages\ManageLocations;
use App\Models\Location;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

class LocationResource2Test extends TestCase
{
    use DatabaseMigrations;

    public function test_can_load_the_page_with_created_records(): void
    {
        $locations = Location::factory()->count(5)->create();

        Livewire::test(ManageLocations::class)
            ->assertOk()
            ->assertCanSeeTableRecords($locations)
            ->assertCountTableRecords(5)
            ->assertCanRenderTableColumn('name')
            ->assertCanRenderTableColumn('description')
            ->assertCanRenderTableColumn('parent.name');
    }

}

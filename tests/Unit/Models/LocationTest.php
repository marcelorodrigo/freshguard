<?php

namespace Tests\Unit\Models;

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_uuid_is_generated_on_creation(): void
    {
        $location = Location::factory()->create();

        $this->assertNotNull($location->id);
        $this->assertTrue(Str::isUuid($location->id));
    }

    public function test_fillable_attributes(): void
    {
        $location = new Location();

        $this->assertEquals([
            'name',
            'description',
            'expiration_notify_days',
            'parent_id',
        ], $location->getFillable());
    }

    public function test_expiration_notify_days_default_and_update(): void
    {
        // Test that the default value is set correctly
        $location = Location::factory()->create();
        $this->assertEquals(0, $location->expiration_notify_days);

        // Test updating the value
        $location->update(['expiration_notify_days' => 15]);
        $this->assertEquals(15, $location->expiration_notify_days);

        // Test with a custom value during creation
        $customLocation = Location::factory()->create([
            'expiration_notify_days' => 30,
        ]);

        $this->assertEquals(30, $customLocation->expiration_notify_days);
    }
}

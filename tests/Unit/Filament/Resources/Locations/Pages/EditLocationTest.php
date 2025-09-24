<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Pages\EditLocation;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for App\Filament\Resources\Locations\Pages\EditLocation
 *
 * Framework: PHPUnit
 */
final class EditLocationTest extends TestCase
{
    /**
     * Provide a lightweight concrete subclass to expose protected methods for unit testing.
     */
    private function makeExposedPage(): object
    {
        return new class extends EditLocation {
            public function exposedHeaderActions(): array
            {
                // Call the protected method from the parent
                return $this->getHeaderActions();
            }
        };
    }

    public function test_resource_property_is_location_resource(): void
    {
        $ref = new ReflectionClass(EditLocation::class);

        $this->assertTrue(
            $ref->hasProperty('resource'),
            'EditLocation must define the protected static $resource property'
        );

        $prop = $ref->getProperty('resource');
        $this->assertTrue($prop->isStatic(), 'EditLocation::$resource must be static');
        $this->assertTrue($prop->isProtected(), 'EditLocation::$resource should be protected');

        // Access static property value
        $prop->setAccessible(true);
        $value = $prop->getValue();

        $this->assertSame(
            LocationResource::class,
            $value,
            'EditLocation::$resource must reference LocationResource::class'
        );
    }

    public function test_get_header_actions_returns_expected_actions_in_order(): void
    {
        $page = $this->makeExposedPage();

        $actions = $page->exposedHeaderActions();

        $this->assertIsArray($actions, 'Header actions should be returned as an array');
        $this->assertCount(2, $actions, 'Header actions should contain exactly two actions');

        // Order: ViewAction first, then DeleteAction
        $this->assertInstanceOf(ViewAction::class, $actions[0], 'First action should be a ViewAction');
        $this->assertInstanceOf(DeleteAction::class, $actions[1], 'Second action should be a DeleteAction');
    }

    public function test_header_actions_are_distinct_instances(): void
    {
        $page = $this->makeExposedPage();

        $a1 = $page->exposedHeaderActions();
        $a2 = $page->exposedHeaderActions();

        // Ensure new instances are returned (not same references)
        $this->assertNotSame($a1[0], $a2[0], 'ViewAction instances should not be the same reference across calls');
        $this->assertNotSame($a1[1], $a2[1], 'DeleteAction instances should not be the same reference across calls');
    }

    public function test_header_actions_have_expected_default_names(): void
    {
        $page = $this->makeExposedPage();
        $actions = $page->exposedHeaderActions();

        // Filament Actions default names commonly match the action type ("view", "delete")
        // We avoid depending on internal property visibility; instead, use toArray() if available,
        // falling back to reflection-based access for the 'name' attribute when present.
        $names = [];

        foreach ($actions as $action) {
            if (method_exists($action, 'getName')) {
                $names[] = $action->getName();
                continue;
            }

            if (method_exists($action, 'toArray')) {
                $arr = $action->toArray();
                if (is_array($arr) && array_key_exists('name', $arr)) {
                    $names[] = $arr['name'];
                    continue;
                }
            }

            // Try to access a 'name' property through reflection as a last resort
            $ref = new ReflectionClass($action);
            if ($ref->hasProperty('name')) {
                $prop = $ref->getProperty('name');
                $prop->setAccessible(true);
                $names[] = $prop->getValue($action);
                continue;
            }

            // If no way to read a name, inject a placeholder to keep assertions meaningful.
            $names[] = null;
        }

        $this->assertSame(['view', 'delete'], $names, 'Header actions should be named "view" and "delete" respectively');
    }

    public function test_header_actions_do_not_include_unexpected_actions(): void
    {
        $page = $this->makeExposedPage();
        $actions = $page->exposedHeaderActions();

        foreach ($actions as $action) {
            $this->assertContains(
                get_class($action),
                [ViewAction::class, DeleteAction::class],
                'Header actions must only include ViewAction and DeleteAction'
            );
        }
    }
}
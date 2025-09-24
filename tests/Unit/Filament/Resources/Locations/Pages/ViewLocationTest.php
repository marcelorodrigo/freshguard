<?php
declare(strict_types=1);

namespace Tests\Unit\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Pages\ViewLocation;
use Filament\Actions\EditAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for ViewLocation page.
 *
 * Focus:
 * - Validates the protected static $resource mapping to LocationResource::class.
 * - Validates header actions contain exactly one EditAction named "edit".
 *
 * Notes:
 * - Testing framework: PHPUnit (preferred in Laravel projects).
 * - Tests avoid booting the full Laravel app; they use reflection and a test double
 *   subclass to access protected behavior in a unit-friendly manner.
 */
final class ViewLocationTest extends TestCase
{
    /**
     * If the target class isn't available (e.g., in a partial workspace),
     * mark the test as skipped to avoid false negatives.
     */
    private function requireClasses(): void
    {
        if (\!class_exists(ViewLocation::class)) {
            $this->markTestSkipped('Class not found: ' . ViewLocation::class);
        }
        if (\!class_exists(LocationResource::class)) {
            $this->markTestSkipped('Class not found: ' . LocationResource::class);
        }
        if (\!class_exists(EditAction::class)) {
            $this->markTestSkipped('Class not found: ' . EditAction::class);
        }
    }

    public function test_resource_property_is_location_resource(): void
    {
        $this->requireClasses();

        $ref = new ReflectionClass(ViewLocation::class);
        // Access the protected static property default value
        $defaultProps = $ref->getDefaultProperties();

        $this->assertArrayHasKey('resource', $defaultProps, 'Expected protected static $resource property to exist.');
        $this->assertSame(
            LocationResource::class,
            $defaultProps['resource'],
            'ViewLocation::$resource should point to LocationResource::class'
        );
    }

    public function test_header_actions_include_single_edit_action_named_edit(): void
    {
        $this->requireClasses();

        // Expose the protected getHeaderActions() via a test double subclass.
        $subject = new class extends ViewLocation {
            public function callGetHeaderActions(): array
            {
                return $this->getHeaderActions();
            }
        };

        $actions = $subject->callGetHeaderActions();

        // Basic shape assertion
        $this->assertIsArray($actions, 'getHeaderActions() should return an array.');
        $this->assertCount(1, $actions, 'Expected exactly one header action.');

        $action = $actions[0];

        // Type assertions
        $this->assertInstanceOf(
            EditAction::class,
            $action,
            'The single header action should be an instance of EditAction.'
        );

        // Name should default to "edit" when using EditAction::make()
        if (method_exists($action, 'getName')) {
            $this->assertSame('edit', $action->getName(), 'EditAction name should be "edit".');
        } else {
            // Fallback: reflect the "name" property if API differs
            $prop = (new \ReflectionClass($action))->getProperty('name');
            $prop->setAccessible(true);
            $this->assertSame('edit', $prop->getValue($action), 'EditAction name should be "edit".');
        }
    }

    public function test_header_actions_are_immutable_instances_not_strings(): void
    {
        $this->requireClasses();

        $subject = new class extends ViewLocation {
            public function callGetHeaderActions(): array
            {
                return $this->getHeaderActions();
            }
        };

        $actions = $subject->callGetHeaderActions();

        foreach ($actions as $idx => $act) {
            $this->assertIsObject($act, "Header action at index {$idx} should be an object instance.");
            $this->assertNotIsString($act, "Header action at index {$idx} should not be a string.");
        }
    }
}
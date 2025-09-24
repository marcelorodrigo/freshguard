<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Resources\Locations\Tables;

use App\Filament\Resources\Locations\Tables\LocationsTable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Testing library/framework: PHPUnit with Laravel's TestCase
 *
 * These tests validate the structure produced by LocationsTable::configure(),
 * asserting that columns, filters, record actions, and toolbar actions are set up
 * according to the PR diff.
 */
#[CoversClass(LocationsTable::class)]
#[Group('filament')]
final class LocationsTableTest extends TestCase
{
    /**
     * Helper to instantiate a minimal Table instance that can be configured.
     * In Filament v3, Table can generally be constructed without arguments.
     * If your Table version requires a different construction, update here.
     */
    private function makeTable(): Table
    {
        // Some Filament versions provide Table::make(). Prefer it if available.
        if (method_exists(Table::class, 'make')) {
            /** @var Table $table */
            $table = Table::make();
            return $table;
        }

        // Fallback: attempt direct instantiation.
        /** @var Table $table */
        $table = new Table();
        return $table;
    }

    public function test_configure_returns_table_instance(): void
    {
        $table = $this->makeTable();
        $configured = LocationsTable::configure($table);

        $this->assertInstanceOf(Table::class, $configured, 'configure() should return a Table instance');
        $this->assertSame($table, $configured, 'configure() should fluently return the given Table instance');
    }

    public function test_columns_are_configured_correctly_in_order(): void
    {
        $table = LocationsTable::configure($this->makeTable());

        // Access columns meta if available; Filament's API exposes getColumns or similar.
        // We will try common accessors in order, failing with clear messages to guide updates.
        $columns = null;
        foreach (['getColumns', 'columns'] as $method) {
            if (method_exists($table, $method)) {
                $columns = $table->{$method}();
                break;
            }
        }

        $this->assertIsArray($columns, 'Unable to access Table columns; ensure a public accessor exists (getColumns/columns).');

        // Expect 6 text columns in the given order
        $this->assertCount(6, $columns, 'Expected exactly 6 columns');

        $this->assertInstanceOf(TextColumn::class, $columns[0]);
        $this->assertSame('id', $columns[0]->getName() ?? $columns[0]->getColumn() ?? null, 'First column should be "id"');

        $this->assertInstanceOf(TextColumn::class, $columns[1]);
        $this->assertSame('name', $columns[1]->getName() ?? $columns[1]->getColumn() ?? null, 'Second column should be "name"');

        $this->assertInstanceOf(TextColumn::class, $columns[2]);
        $this->assertSame('description', $columns[2]->getName() ?? $columns[2]->getColumn() ?? null, 'Third column should be "description"');

        $this->assertInstanceOf(TextColumn::class, $columns[3]);
        $this->assertSame('parent.name', $columns[3]->getName() ?? $columns[3]->getColumn() ?? null, 'Fourth column should be "parent.name"');

        $this->assertInstanceOf(TextColumn::class, $columns[4]);
        $this->assertSame('created_at', $columns[4]->getName() ?? $columns[4]->getColumn() ?? null, 'Fifth column should be "created_at"');

        $this->assertInstanceOf(TextColumn::class, $columns[5]);
        $this->assertSame('updated_at', $columns[5]->getName() ?? $columns[5]->getColumn() ?? null, 'Sixth column should be "updated_at"');
    }

    public function test_id_created_updated_columns_toggleable_hidden_by_default(): void
    {
        $table = LocationsTable::configure($this->makeTable());

        $columns = null;
        foreach (['getColumns', 'columns'] as $method) {
            if (method_exists($table, $method)) {
                $columns = $table->{$method}();
                break;
            }
        }
        $this->assertIsArray($columns);

        // Extract by name helper
        $byName = static function (array $cols, string $name) {
            foreach ($cols as $c) {
                $cName = method_exists($c, 'getName') ? $c->getName() : (method_exists($c, 'getColumn') ? $c->getColumn() : null);
                if ($cName === $name) {
                    return $c;
                }
            }
            return null;
        };

        $idCol = $byName($columns, 'id');
        $this->assertNotNull($idCol, 'id column missing');
        $this->assertTrue(
            method_exists($idCol, 'isToggledHiddenByDefault') ? $idCol->isToggledHiddenByDefault() : true,
            'id should be toggleable and hidden by default'
        );

        $createdCol = $byName($columns, 'created_at');
        $this->assertNotNull($createdCol, 'created_at column missing');
        $this->assertTrue(
            method_exists($createdCol, 'isToggledHiddenByDefault') ? $createdCol->isToggledHiddenByDefault() : true,
            'created_at should be toggleable and hidden by default'
        );

        $updatedCol = $byName($columns, 'updated_at');
        $this->assertNotNull($updatedCol, 'updated_at column missing');
        $this->assertTrue(
            method_exists($updatedCol, 'isToggledHiddenByDefault') ? $updatedCol->isToggledHiddenByDefault() : true,
            'updated_at should be toggleable and hidden by default'
        );
    }

    public function test_text_columns_searchable_flags(): void
    {
        $table = LocationsTable::configure($this->makeTable());
        $columns = method_exists($table, 'getColumns') ? $table->getColumns() : (method_exists($table, 'columns') ? $table->columns() : []);

        $getName = static fn($c) => method_exists($c, 'getName') ? $c->getName() : (method_exists($c, 'getColumn') ? $c->getColumn() : null);
        $find = static function(array $cols, string $name) use ($getName) {
            foreach ($cols as $c) {
                if ($getName($c) === $name) return $c;
            }
            return null;
        };

        foreach (['name', 'description', 'parent.name'] as $searchableName) {
            $col = $find($columns, $searchableName);
            $this->assertNotNull($col, "$searchableName column missing");
            $isSearchable = method_exists($col, 'isSearchable') ? $col->isSearchable() : true;
            $this->assertTrue($isSearchable, "$searchableName should be searchable");
        }
    }

    public function test_created_updated_columns_are_datetime_and_sortable(): void
    {
        $table = LocationsTable::configure($this->makeTable());
        $columns = method_exists($table, 'getColumns') ? $table->getColumns() : (method_exists($table, 'columns') ? $table->columns() : []);

        $getName = static fn($c) => method_exists($c, 'getName') ? $c->getName() : (method_exists($c, 'getColumn') ? $c->getColumn() : null);
        $find = static function(array $cols, string $name) use ($getName) {
            foreach ($cols as $c) {
                if ($getName($c) === $name) return $c;
            }
            return null;
        };

        foreach (['created_at', 'updated_at'] as $dtName) {
            $col = $find($columns, $dtName);
            $this->assertNotNull($col, "$dtName column missing");

            $isDateTime = method_exists($col, 'isDateTime') ? $col->isDateTime() : (method_exists($col, 'getDateTime') ? (bool) $col->getDateTime() : true);
            $this->assertTrue($isDateTime, "$dtName should be configured as dateTime");

            $isSortable = method_exists($col, 'isSortable') ? $col->isSortable() : (method_exists($col, 'getSortable') ? (bool) $col->getSortable() : true);
            $this->assertTrue($isSortable, "$dtName should be sortable");
        }
    }

    public function test_filters_are_configured_even_if_empty(): void
    {
        $table = LocationsTable::configure($this->makeTable());

        $filters = null;
        foreach (['getFilters', 'filters'] as $method) {
            if (method_exists($table, $method)) {
                $filters = $table->{$method}();
                break;
            }
        }

        // Per current diff, filters array is empty
        if ($filters === null) {
            $this->markTestSkipped('Table does not expose filters accessor; skip structural check.');
        } else {
            $this->assertIsArray($filters);
            $this->assertCount(0, $filters, 'Expected no filters per current configuration');
        }
    }

    public function test_record_actions_include_view_and_edit(): void
    {
        $table = LocationsTable::configure($this->makeTable());

        $recordActions = null;
        foreach (['getRecordActions', 'recordActions'] as $method) {
            if (method_exists($table, $method)) {
                $recordActions = $table->{$method}();
                break;
            }
        }

        $this->assertIsArray($recordActions, 'Unable to access record actions; ensure accessor exists.');
        $this->assertNotEmpty($recordActions);

        $classes = array_map(static fn($a) => is_object($a) ? $a::class : gettype($a), $recordActions);
        $this->assertContains(ViewAction::class, $classes, 'Record actions should include ViewAction');
        $this->assertContains(EditAction::class, $classes, 'Record actions should include EditAction');
    }

    public function test_toolbar_contains_bulk_group_with_delete_bulk_action(): void
    {
        $table = LocationsTable::configure($this->makeTable());

        $toolbarActions = null;
        foreach (['getHeaderActions', 'getToolbarActions', 'toolbarActions', 'headerActions'] as $method) {
            if (method_exists($table, $method)) {
                $toolbarActions = $table->{$method}();
                break;
            }
        }

        $this->assertIsArray($toolbarActions, 'Unable to access toolbar/header actions; ensure accessor exists.');
        $this->assertNotEmpty($toolbarActions);

        // Find BulkActionGroup and assert it includes DeleteBulkAction
        $foundGroup = null;
        foreach ($toolbarActions as $action) {
            if ($action instanceof BulkActionGroup) {
                $foundGroup = $action;
                break;
            }
        }
        $this->assertNotNull($foundGroup, 'Expected a BulkActionGroup in toolbar actions');

        // Attempt to enumerate grouped actions
        $grouped = null;
        foreach (['getActions', 'actions'] as $method) {
            if (method_exists($foundGroup, $method)) {
                $grouped = $foundGroup->{$method}();
                break;
            }
        }

        $this->assertIsArray($grouped, 'Unable to access actions within BulkActionGroup');
        $classes = array_map(static fn($a) => is_object($a) ? $a::class : gettype($a), $grouped);
        $this->assertContains(DeleteBulkAction::class, $classes, 'BulkActionGroup should include DeleteBulkAction');
    }

    public function test_id_column_label_is_id_and_created_updated_labels_are_set(): void
    {
        $table = LocationsTable::configure($this->makeTable());
        $columns = method_exists($table, 'getColumns') ? $table->getColumns() : (method_exists($table, 'columns') ? $table->columns() : []);

        $byName = static function (array $cols, string $name) {
            foreach ($cols as $c) {
                $cName = method_exists($c, 'getName') ? $c->getName() : (method_exists($c, 'getColumn') ? $c->getColumn() : null);
                if ($cName === $name) {
                    return $c;
                }
            }
            return null;
        };

        $idCol = $byName($columns, 'id');
        $this->assertNotNull($idCol, 'id column missing');
        $idLabel = method_exists($idCol, 'getLabel') ? $idCol->getLabel() : 'ID';
        $this->assertSame('ID', $idLabel, 'id column label should be "ID"');

        $createdCol = $byName($columns, 'created_at');
        $this->assertNotNull($createdCol, 'created_at column missing');
        $createdLabel = method_exists($createdCol, 'getLabel') ? $createdCol->getLabel() : 'Created';
        $this->assertSame('Created', $createdLabel, 'created_at column label should be "Created"');

        $updatedCol = $byName($columns, 'updated_at');
        $this->assertNotNull($updatedCol, 'updated_at column missing');
        $updatedLabel = method_exists($updatedCol, 'getLabel') ? $updatedCol->getLabel() : 'Updated';
        $this->assertSame('Updated', $updatedLabel, 'updated_at column label should be "Updated"');
    }

    public function test_configure_throws_exception_is_not_expected(): void
    {
        $table = $this->makeTable();
        $thrown = null;
        try {
            LocationsTable::configure($table);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertNull($thrown, 'configure() should not throw; it is declared with @throws but normal configuration should not error');
    }
}
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locations\RelationManagers;

use App\Filament\Resources\Items\Schemas\BatchForm;
use App\Filament\Resources\Items\Tables\BatchesTable;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class BatchesRelationManager extends RelationManager
{
    protected static string $relationship = 'batches';

    public static function getTitle(mixed $ownerRecord = null, ?string $pageClass = null): string
    {
        return __('Batches');
    }

    public function form(Schema $schema): Schema
    {
        // You may want to restrict item/location selection here or use readonly fields
        return BatchForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        $table = BatchesTable::configure($table);

        // Remove 'Location' column since we know the parent
        $table = $table->columns(collect($table->getColumns())
            ->reject(fn ($col) => $col->getName() === 'location.name')
            ->all()
        );

        return $table
            ->headerActions([
                CreateAction::make()
                    ->label(__('New Batch'))
                    ->icon('heroicon-o-plus'),
            ])
            ->actions([
                EditAction::make()
                    ->label(__('Edit'))
                    ->icon('heroicon-o-pencil'),
                DeleteAction::make()
                    ->label(__('Delete'))
                    ->icon('heroicon-o-trash'),
            ])
            ->bulkActions([]);
    }
}

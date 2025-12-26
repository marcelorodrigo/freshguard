<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\RelationManagers;

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
        return BatchForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        $table = BatchesTable::configure($table);

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

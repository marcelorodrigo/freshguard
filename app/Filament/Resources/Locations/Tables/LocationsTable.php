<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locations\Tables;

use App\Actions\DeleteLocations;
use App\Models\Location;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withExists('batches'))
            ->contentGrid([
                'sm' => 1,
                'md' => 1,
                'lg' => 1,
            ])
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('description')
                    ->searchable(),
                TextColumn::make('parent.name')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->disabled(fn (Location $record): bool => (bool) $record->getAttribute('batches_exists'))
                    ->tooltip(fn (Location $record): ?string => $record->getAttribute('batches_exists')
                        ? (string) __('filament.locations.delete.disabled_tooltip')
                        : null)
                    ->modalDescription(__('filament.locations.delete.modal_description'))
                    ->failureNotificationTitle(__('filament.locations.delete.blocked_title'))
                    ->failureNotificationBody(__('filament.locations.delete.blocked_body'))
                    ->using(
                        static fn (Location $record): bool => (new DeleteLocations)(new Collection([$record]))
                    ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalDescription(__('filament.locations.delete.bulk_modal_description'))
                        ->failureNotificationTitle(__('filament.locations.delete.bulk_blocked_title'))
                        ->using(static function (Collection $records, DeleteBulkAction $action): void {
                            $deleted = (new DeleteLocations)($records);

                            if (! $deleted) {
                                $action->reportCompleteBulkProcessingFailure(
                                    'location_contains_batches',
                                    __('filament.locations.delete.bulk_blocked_body'),
                                );

                                return;
                            }

                            $action->reportBulkProcessingSuccessfulRecordsCount($records->count());
                        }),
                ]),
            ]);
    }
}

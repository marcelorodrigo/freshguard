<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Schemas;

use App\Models\Item;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Marcelorodrigo\FilamentBarcodeScannerField\Forms\Components\BarcodeInput;
use OpenFoodFacts\Laravel\Facades\OpenFoodFacts;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label(__('Name')),
                BarcodeInput::make('barcode')
                    ->maxLength(255)
                    ->default(null)
                    ->label(__('Barcode'))
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state, ?string $old): void {
                        // Only fetch if barcode changed and has a value
                        if (empty($state) || $state === $old) {
                            return;
                        }

                        try {
                            $productData = OpenFoodFacts::barcode($state);

                            if (empty($productData)) {
                                return;
                            }

                            // Only populate if name is empty
                            if (empty($get('name')) && ! empty($productData['product_name'])) {
                                $set('name', $productData['product_name']);
                            }

                            // Only populate if description is empty
                            if (empty($get('description')) && ! empty($productData['generic_name'])) {
                                $set('description', $productData['generic_name']);
                            }

                            // Only populate tags if they are null/empty
                            $currentTags = $get('tags');
                            if (empty($currentTags) && ! empty($productData['categories_hierarchy'])) {
                                $categories = is_array($productData['categories_hierarchy'])
                                    ? array_values($productData['categories_hierarchy'])
                                    : [];

                                // Extract category names, removing 'en:' prefix
                                $tags = array_map(
                                    fn (string $category): string => str_replace('en:', '', $category),
                                    array_filter($categories, 'is_string')
                                );

                                if (! empty($tags)) {
                                    $set('tags', $tags);
                                }
                            }
                        } catch (\Exception) {
                            // Silently fail if API call fails
                        }
                    }),
                Select::make('location_id')
                    ->relationship('location', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->label(__('Location')),
                Textarea::make('description')
                    ->maxLength(1000)
                    ->nullable()
                    ->label(__('Description')),
                TextInput::make('quantity')
                    ->integer()
                    ->default(0)
                    ->readOnly()
                    ->helperText(__('The quantity is computed from all batches'))
                    ->label(__('Quantity'))
                    ->hidden(static fn ($record) => is_null($record)),
                TextInput::make('expiration_notify_days')
                    ->integer()
                    ->suffix(__('days'))
                    ->minValue(0)
                    ->default(0)
                    ->label(__('Notify before expiration')),
                TagsInput::make('tags')
                    ->label(__('Tags'))
                    ->suggestions(function (): array {
                        // Get all existing tags from all items
                        return Item::query()
                            ->whereNotNull('tags')
                            ->pluck('tags')
                            ->flatten()
                            ->unique()
                            ->sort()
                            ->values()
                            ->toArray();
                    })
                    ->placeholder(__('Add tags...')),
            ]);
    }
}

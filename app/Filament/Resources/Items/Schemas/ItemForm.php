<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Schemas;

use App\Models\Item;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Log;
use Marcelorodrigo\FilamentBarcodeScannerField\Forms\Components\BarcodeInput;
use OpenFoodFacts\Laravel\Facades\OpenFoodFacts;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Basic Information'))
                    ->schema([
                        Grid::make(['sm' => 1, 'md' => 2])
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->label(__('Name'))
                                    ->columnSpan(['sm' => 1, 'md' => 1]),
                                BarcodeInput::make('barcode')
                                    ->maxLength(255)
                                    ->default(null)
                                    ->label(__('Barcode'))
                                    ->live(onBlur: true)
                                    ->columnSpan(['sm' => 1, 'md' => 1])
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state, ?string $old): void {
                                        // Only fetch if barcode changed and has a value
                                        if (empty($state) || $state === $old) {
                                            return;
                                        }

                                        try {
                                            $productData = OpenFoodFacts::barcode($state);

                                            if (empty($productData)) {
                                                Notification::make()
                                                    ->title(__('Product not found'))
                                                    ->body(__('No product information found for this barcode.'))
                                                    ->warning()
                                                    ->send();

                                                return;
                                            }

                                            $fieldsPopulated = [];

                                            // Only populate if name is empty
                                            if (empty($get('name')) && ! empty($productData['product_name'])) {
                                                $set('name', $productData['product_name']);
                                                $fieldsPopulated[] = __('name');
                                            }

                                            // Only populate if description is empty
                                            if (empty($get('description')) && ! empty($productData['generic_name'])) {
                                                $set('description', $productData['generic_name']);
                                                $fieldsPopulated[] = __('description');
                                            }

                                            // Only populate tags if they are null/empty
                                            $currentTags = $get('tags');
                                            if (empty($currentTags) && ! empty($productData['categories_hierarchy'])) {
                                                $tags = self::extractTagsFromCategories($productData['categories_hierarchy']);

                                                if (! empty($tags)) {
                                                    $set('tags', $tags);
                                                    $fieldsPopulated[] = __('tags');
                                                }
                                            }

                                            // Show success notification with populated fields
                                            if (! empty($fieldsPopulated)) {
                                                Notification::make()
                                                    ->title(__('Product data loaded'))
                                                    ->body(__('Populated: :fields', ['fields' => implode(', ', $fieldsPopulated)]))
                                                    ->success()
                                                    ->send();
                                            } else {
                                                Notification::make()
                                                    ->title(__('Product found'))
                                                    ->body(__('No empty fields to populate.'))
                                                    ->info()
                                                    ->send();
                                            }
                                        } catch (\Exception $e) {
                                            Log::warning('Error fetching product data for barcode', [
                                                'barcode' => $state,
                                                'error' => $e->getMessage(),
                                                'exception' => get_class($e),
                                            ]);
                                        }
                                    }),
                            ]),
                        Textarea::make('description')
                            ->maxLength(1000)
                            ->nullable()
                            ->label(__('Description'))
                            ->columnSpanFull(),
                    ])
                    ->compact(),

                Section::make(__('Inventory Details'))
                    ->schema([
                        Grid::make(['sm' => 1, 'md' => 2])
                            ->schema([
                                TextInput::make('quantity')
                                    ->integer()
                                    ->default(0)
                                    ->readOnly()
                                    ->helperText(__('The quantity is computed from all batches'))
                                    ->label(__('Quantity'))
                                    ->hidden(static fn ($record) => is_null($record))
                                    ->columnSpan(['sm' => 1, 'md' => 1]),
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
                                    ->placeholder(__('Add tags...'))
                                    ->columnSpan(['sm' => 1, 'md' => 1]),
                            ]),
                    ])
                    ->compact(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function extractTagsFromCategories(mixed $categoriesHierarchy): array
    {
        $categories = is_array($categoriesHierarchy)
            ? array_values($categoriesHierarchy)
            : [];

        return array_map(
            fn (string $category): string => str_replace('en:', '', $category),
            array_filter($categories, 'is_string')
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Batch;
use App\Models\Item;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class QuickConsume extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 10;

    public static function navigationLabel(): ?string
    {
        return __('filament.navigation.quick_consume');
    }

    public static function navigationGroup(): ?string
    {
        return __('filament.navigation.inventory');
    }

    protected string $view = 'filament.pages.quick-consume';

    #[Url]
    public string $search = '';

    /** @var Collection<int, Item> */
    public Collection $searchResults;

    public function mount(): void
    {
        /** @var Collection<int, Item> $searchResults */
        $searchResults = collect();

        $this->searchResults = $searchResults;

        if (strlen($this->search) >= 2) {
            $this->performSearch();
        }
    }

    public function updatedSearch(): void
    {
        if (strlen($this->search) < 2) {
            /** @var Collection<int, Item> $searchResults */
            $searchResults = collect();

            $this->searchResults = $searchResults;

            return;
        }

        $this->performSearch();
    }

    private function performSearch(): void
    {
        $search = $this->search;

        $this->searchResults = Item::query()
            ->with([
                'batches' => function (Relation $query): Relation {
                    return $query
                        ->with('location')
                        ->where('quantity', '>', 0)
                        ->orderBy('expires_at');
                },
            ])
            ->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            })
            ->whereHas('batches', fn (Builder $q): Builder => $q->where('quantity', '>', 0))
            ->limit(10)
            ->get();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('search')
                            ->live(debounce: 200)
                            ->placeholder(__('quick-consume.search.placeholder'))
                            ->prefixIcon(Heroicon::MagnifyingGlass)
                            ->helperText(__('quick-consume.search.help')),
                    ]),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->state(['results' => $this->searchResults])
            ->components([
                $this->getResultsComponent(),
            ]);
    }

    private function getResultsComponent(): RepeatableEntry|EmptyState
    {
        if ($this->searchResults->isEmpty()) {
            if (strlen($this->search) < 2) {
                return EmptyState::make(__('quick-consume.empty.initial.title'))
                    ->description(__('quick-consume.empty.initial.description'))
                    ->icon(Heroicon::OutlinedMagnifyingGlass);
            }

            return EmptyState::make(__('quick-consume.empty.title'))
                ->description(__('quick-consume.empty.description'))
                ->icon(Heroicon::OutlinedMagnifyingGlass);
        }

        return RepeatableEntry::make('results')
            ->schema([
                TextEntry::make('name')
                    ->label('')
                    ->weight(FontWeight::Bold)
                    ->tooltip(fn (mixed $record): string => ($record instanceof Item) ? ($record->description ?? '') : '')
                    ->size(TextSize::Large),
                RepeatableEntry::make('batches')
                    ->table([
                        TableColumn::make(__('quick-consume.batch.location')),
                        TableColumn::make(__('quick-consume.batch.expires_at')),
                        TableColumn::make(__('quick-consume.batch.quantity')),
                        TableColumn::make(''),
                    ])
                    ->schema([
                        TextEntry::make('location.name'),
                        TextEntry::make('expires_at')
                            ->formatStateUsing(fn (?Carbon $state): string => $state?->format('d/m/Y') ?? '-')
                            ->icon(fn (?Carbon $state): Heroicon => $this->getExpirationIcon($state))
                            ->iconColor(fn (?Carbon $state): string => $this->getExpirationColor($state)),
                        TextEntry::make('quantity')
                            ->numeric(),
                        IconEntry::make('id')
                            ->icon(Heroicon::AdjustmentsHorizontal)
                            ->label(__('quick-consume.action.consume'))
                            ->color('danger')
                            ->action(
                                Action::make('consume')
                                    ->label(__('quick-consume.action.consume'))
                                    ->requiresConfirmation()
                                    ->modalHeading(__('quick-consume.action.confirm.title'))
                                    ->modalDescription(__('quick-consume.action.confirm.description'))
                                    ->action(function (Batch $batch): void {
                                        $this->consumeBatch($batch->id);
                                    })
                            ),
                    ]),
            ]);
    }

    private function getExpirationIcon(?Carbon $expiresAt): Heroicon
    {
        if ($expiresAt === null) {
            return Heroicon::OutlinedQuestionMarkCircle;
        }

        if ($expiresAt->isPast()) {
            return Heroicon::OutlinedExclamationTriangle;
        }

        if ($expiresAt->diffInDays(now()) <= 7) {
            return Heroicon::OutlinedClock;
        }

        return Heroicon::OutlinedCheckCircle;
    }

    private function getExpirationColor(?Carbon $expiresAt): string
    {
        if ($expiresAt === null) {
            return 'gray';
        }

        if ($expiresAt->isPast()) {
            return 'danger';
        }

        if ($expiresAt->diffInDays(now()) <= 7) {
            return 'warning';
        }

        return 'success';
    }

    public function consumeBatch(string $batchId): void
    {
        if (empty($batchId)) {
            return;
        }

        $itemName = DB::transaction(function () use ($batchId): ?string {
            $batch = Batch::query()
                ->where('id', $batchId)
                ->lockForUpdate()
                ->first();

            if ($batch === null || $batch->quantity <= 0) {
                return null;
            }

            $itemName = $batch->item->name;

            if ($batch->quantity === 1) {
                $batch->delete();
            } else {
                $batch->quantity--;
                $batch->save();
            }

            return $itemName;
        });

        if ($itemName === null) {
            return;
        }

        Notification::make()
            ->title(__('quick-consume.notification.consumed.title'))
            ->body(__('quick-consume.notification.consumed.body', ['item' => $itemName]))
            ->success()
            ->send();

        $this->performSearch();
    }
}

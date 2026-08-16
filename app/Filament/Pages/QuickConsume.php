<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\ConsumeBatch;
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
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

class QuickConsume extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('filament.navigation.quick_consume');
    }

    public static function getNavigationGroup(): string
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
        $this->searchResults = new Collection;

        if ($this->hasSearchTerm()) {
            $this->performSearch();
        }
    }

    public function updatedSearch(): void
    {
        if (! $this->hasSearchTerm()) {
            $this->searchResults = new Collection;
            $this->refreshInfolist();

            return;
        }

        $this->performSearch();
    }

    private function performSearch(): void
    {
        $search = trim($this->search);
        $searchPattern = sprintf('%%%s%%', addcslashes($search, '\\%_'));
        $escapeChar = '\\';

        $this->searchResults = Item::query()
            ->select(['id', 'name', 'barcode', 'description'])
            ->with([
                'batches' => function (Relation $query): void {
                    $query
                        ->select(['id', 'item_id', 'location_id', 'expires_at', 'quantity'])
                        ->with('location')
                        ->where('quantity', '>', 0)
                        ->orderBy('expires_at')
                        ->orderBy('id');
                },
            ])
            ->where(function (Builder $query) use ($searchPattern, $escapeChar): void {
                $query->whereRaw('`name` like ? escape ?', [$searchPattern, $escapeChar])
                    ->orWhereRaw('`barcode` like ? escape ?', [$searchPattern, $escapeChar])
                    ->orWhereRaw('`description` like ? escape ?', [$searchPattern, $escapeChar]);
            })
            ->whereHas('batches', fn (Builder $q): Builder => $q->where('quantity', '>', 0))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(10)
            ->get();

        $this->refreshInfolist();
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
            if (! $this->hasSearchTerm()) {
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
                    ->tooltip(fn (Item $record): string => $record->description ?? '')
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
                            ->icon(fn (?Carbon $state): Heroicon => $this->getExpirationStatus($state)['icon'])
                            ->iconColor(fn (?Carbon $state): string => $this->getExpirationStatus($state)['color']),
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
                                    ->action(function (Batch $batch, ConsumeBatch $consumeBatch): void {
                                        $this->consumeBatch($batch->id, $consumeBatch);
                                    })
                            ),
                    ]),
            ]);
    }

    /**
     * @return array{icon: Heroicon, color: string}
     */
    private function getExpirationStatus(?Carbon $expiresAt): array
    {
        $today = Carbon::today();

        return match (true) {
            $expiresAt === null => ['icon' => Heroicon::OutlinedQuestionMarkCircle, 'color' => 'gray'],
            $expiresAt->copy()->startOfDay()->isBefore($today) => [
                'icon' => Heroicon::OutlinedExclamationTriangle,
                'color' => 'danger',
            ],
            $today->diffInDays($expiresAt->copy()->startOfDay()) <= 7 => [
                'icon' => Heroicon::OutlinedClock,
                'color' => 'warning',
            ],
            default => ['icon' => Heroicon::OutlinedCheckCircle, 'color' => 'success'],
        };
    }

    public function consumeBatch(string $batchId, ConsumeBatch $consumeBatch): void
    {
        if ($batchId === '') {
            return;
        }

        $itemName = $consumeBatch($batchId);

        if ($itemName === null) {
            Notification::make()
                ->title(__('quick-consume.notification.unavailable.title'))
                ->body(__('quick-consume.notification.unavailable.body'))
                ->warning()
                ->send();

            $this->refreshSearchResults();

            return;
        }

        Notification::make()
            ->title(__('quick-consume.notification.consumed.title'))
            ->body(__('quick-consume.notification.consumed.body', ['item' => $itemName]))
            ->success()
            ->send();

        $this->refreshSearchResults();
    }

    private function hasSearchTerm(): bool
    {
        return Str::length(trim($this->search)) >= 2;
    }

    private function refreshSearchResults(): void
    {
        if ($this->hasSearchTerm()) {
            $this->performSearch();

            return;
        }

        $this->searchResults = new Collection;
        $this->refreshInfolist();
    }

    private function refreshInfolist(): void
    {
        $this->cacheSchema('infolist', null);
    }
}

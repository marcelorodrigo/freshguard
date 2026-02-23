<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Batch;
use App\Models\Item;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;

class QuickConsume extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static ?string $slug = 'quick-consume';

    protected string $view = 'filament.pages.quick-consume';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMinusCircle;

    protected static ?int $navigationSort = 1;

    #[Url]
    public string $search = '';

    public ?Item $selectedItem = null;

    public static function getNavigationLabel(): string
    {
        return __('Quick Consume');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Inventory');
    }

    /**
     * @var Collection<int, Batch>|null
     */
    public ?Collection $batches = null;

    public function updatedSearch(): void
    {
        $this->selectedItem = null;
        $this->batches = null;
    }

    public function selectItem(string $itemId): void
    {
        $this->selectedItem = Item::query()->find($itemId);
        $this->search = '';

        if ($this->selectedItem !== null) {
            $this->loadBatches();
        }
    }

    public function consume(string $batchId): void
    {
        $batch = Batch::query()->find($batchId);

        if ($batch === null) {
            return;
        }

        if ($batch->quantity < 1) {
            Notification::make()
                ->title(__('Cannot consume more than available'))
                ->body(__('This batch has :quantity units available.', ['quantity' => $batch->quantity]))
                ->danger()
                ->send();

            return;
        }

        $newQuantity = $batch->quantity - 1;
        $batch->quantity = $newQuantity;
        $batch->save();

        if ($newQuantity === 0) {
            $batch->delete();
            Notification::make()
                ->title(__('Batch emptied and removed'))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('1 unit consumed'))
                ->success()
                ->send();
        }

        $this->loadBatches();

        if ($this->batches === null || $this->batches->isEmpty()) {
            $this->selectedItem = null;
        }
    }

    public function clearSelection(): void
    {
        $this->selectedItem = null;
        $this->batches = null;
        $this->search = '';
    }

    /**
     * @return Collection<int, Item>
     */
    public function getSearchResults(): Collection
    {
        if (strlen($this->search) < 2) {
            /** @var Collection<int, Item> */
            return new Collection;
        }

        /** @var Collection<int, Item> */
        return Item::query()
            ->where('name', 'LIKE', "%{$this->search}%")
            ->orWhere('barcode', 'LIKE', "%{$this->search}%")
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    /**
     * @return array{color: string, label: string, icon: BackedEnum}
     */
    public function getExpirationStatus(Batch $batch): array
    {
        $expiresAt = $batch->expires_at->startOfDay();
        $today = today();

        if ($expiresAt->isPast()) {
            return [
                'color' => 'danger',
                'label' => __('Expired'),
                'icon' => Heroicon::OutlinedExclamationCircle,
            ];
        }

        $notifyDays = $batch->location->expiration_notify_days ?? 7;
        $warningDate = $today->copy()->addDays($notifyDays);

        if ($expiresAt->lessThanOrEqualTo($warningDate)) {
            return [
                'color' => 'warning',
                'label' => __('Expiring Soon'),
                'icon' => Heroicon::OutlinedExclamationTriangle,
            ];
        }

        return [
            'color' => 'success',
            'label' => __('Good'),
            'icon' => Heroicon::OutlinedCheckCircle,
        ];
    }

    protected function loadBatches(): void
    {
        if ($this->selectedItem === null) {
            $this->batches = null;

            return;
        }

        $this->batches = Batch::query()
            ->with('location')
            ->where('item_id', $this->selectedItem->id)
            ->where('quantity', '>', 0)
            ->orderBy('expires_at', 'asc')
            ->get();
    }

    public function searchResultsInfolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                RepeatableEntry::make('items')
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('Item Name'))
                            ->url(fn (Item $record): string => 'javascript:void(0)')
                            ->openUrlInNewTab(false)
                            ->extraAttributes(fn (Item $record): array => [
                                'wire:click' => '$dispatch(\'select-item\', { id: \''.$record->id.'\' })',
                                'class' => 'cursor-pointer hover:text-primary transition-colors',
                            ]),
                        TextEntry::make('quantity')
                            ->label(__('Units Available'))
                            ->state(fn (Item $record): string => "{$record->quantity} units"),
                    ])
                    ->columns(2),
            ]);
    }

    public function batchesInfolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                RepeatableEntry::make('batches')
                    ->schema([
                        TextEntry::make('expiration_status')
                            ->label(__('Status'))
                            ->badge()
                            ->color(fn (Batch $record): string => $this->getExpirationStatus($record)['color'])
                            ->state(fn (Batch $record): string => $this->getExpirationStatus($record)['label']),
                        TextEntry::make('location.name')
                            ->label(__('Location')),
                        TextEntry::make('quantity')
                            ->label(__('Units'))
                            ->state(fn (Batch $record): string => (string) $record->quantity),
                        TextEntry::make('expires_at')
                            ->label(__('Expires'))
                            ->date('d M Y'),
                        TextEntry::make('consume')
                            ->label(__('Action'))
                            ->url(fn (Batch $record): string => 'javascript:void(0)')
                            ->openUrlInNewTab(false)
                            ->extraAttributes(fn (Batch $record): array => [
                                'wire:click' => '$wire.consume(\''.$record->id.'\')',
                                'class' => 'cursor-pointer',
                            ])
                            ->state(fn (Batch $record): string => $record->quantity > 0 ? __('Consume 1 Unit') : __('Out of Stock'))
                            ->color(fn (Batch $record): string => $record->quantity > 0 ? 'primary' : 'gray'),
                    ])
                    ->columns(5),
            ]);
    }
}

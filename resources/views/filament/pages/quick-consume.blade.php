<x-filament-panels::page>
    @if($selectedItem === null)
    <x-filament::card class="mx-auto w-full max-w-2xl" x-data>
        <x-filament::input.wrapper suffix-icon="heroicon-o-magnifying-glass">
            <x-filament::input
                    wire:model.live.debounce.200ms="search"
                    type="search"
                    :placeholder="__('Search items by name or barcode...')"
                    autofocus
            />
        </x-filament::input.wrapper>
        @if(strlen($search) >= 2)
        @php($searchResults = $this->getSearchResults())
        <div class="mt-3">
            @if($searchResults->isNotEmpty())
                {{ $this->searchResultsInfolist->state(['items' => $searchResults]) }}
            @elseif($search)
            <x-filament::card class="text-center">
                <x-filament::icon
                        icon="heroicon-o-face-frown"
                        class="w-10 h-10 mx-auto text-gray-400 mb-3"
                />
                <p class="text-gray-500 dark:text-gray-400">
                    {{ __('No items found matching your search.') }}
                </p>
            </x-filament::card>
            @endif
        </div>
        @endif

        <div
            @select-item.window="if ($event.detail.id) { $wire.selectItem($event.detail.id) }"
        ></div>
    </x-filament::card>
    @else
    <div x-data class="space-y-6">
        <div class="flex items-center gap-4">
            <x-filament::button
                    color="gray"
                    variant="subtle"
                    wire:click="clearSelection"
                    class="shrink-0"
            >
                <x-filament::icon icon="heroicon-o-arrow-left" class="w-5 h-5 me-1"/>
                {{ __('Back') }}
            </x-filament::button>

            <div class="min-w-0 flex-1">
                <h2 class="text-xl font-bold text-gray-900 dark:text-white truncate">
                    {{ $selectedItem->name }}
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $selectedItem->quantity }} {{ __('total units') }}
                </p>
            </div>
        </div>

        @if($batches === null || $batches->isEmpty())
        <x-filament::card class="text-center">
            <x-filament::icon
                    icon="heroicon-o-archive-box-x-mark"
                    class="w-8 h-8 mx-auto text-gray-400 mb-3"
            />
            <p class="text-gray-500 dark:text-gray-400 font-medium">
                {{ __('No batches available for this item.') }}
            </p>
            <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">
                {{ __('Add stock to this item to start consuming.') }}
            </p>
        </x-filament::card>
        @else
            {{ $this->batchesInfolist->state(['batches' => $batches]) }}
        @endif
    </div>
    @endif
</x-filament-panels::page>

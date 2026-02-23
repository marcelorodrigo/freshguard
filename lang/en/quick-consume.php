<?php

declare(strict_types=1);

return [
    'title' => 'Quick Consume',
    'search' => [
        'placeholder' => 'Search items by name, barcode, or description...',
        'help' => 'Type at least 2 characters to search',
    ],
    'empty' => [
        'title' => 'No items found',
        'description' => 'Try adjusting your search terms',
        'initial' => [
            'title' => 'Start typing to search',
            'description' => 'Search for items by name, barcode, or description',
        ],
    ],
    'batch' => [
        'expires_at' => 'Expires',
        'location' => 'Location',
        'quantity' => 'Qty',
    ],
    'action' => [
        'consume' => 'Consume',
        'confirm' => [
            'title' => 'Consume 1 unit?',
            'description' => 'This will reduce the batch quantity by 1. If it reaches zero, the batch will be deleted.',
        ],
    ],
    'notification' => [
        'consumed' => [
            'title' => 'Item consumed',
            'body' => '1 unit has been removed from :item',
        ],
    ],
];

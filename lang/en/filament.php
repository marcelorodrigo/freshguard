<?php

declare(strict_types=1);

return [
    'navigation' => [
        'quick_consume' => 'Quick Consume',
        'inventory' => 'Inventory',
    ],
    'locations' => [
        'delete' => [
            'blocked_title' => 'Location cannot be deleted',
            'blocked_body' => 'This location contains inventory. Remove all batches before deleting it.',
            'bulk_blocked_title' => 'Locations were not deleted',
            'bulk_blocked_body' => 'No locations were deleted because at least one selected location contains inventory.',
            'disabled_tooltip' => 'Locations containing inventory cannot be deleted.',
            'modal_description' => 'Child locations will be kept and become root locations.',
            'bulk_modal_description' => 'This deletion is atomic: if any selected location contains inventory, no locations will be deleted. Child locations will be kept and become root locations.',
        ],
    ],
];

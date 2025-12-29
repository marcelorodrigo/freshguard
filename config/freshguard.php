<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Registrations Enabled
    |--------------------------------------------------------------------------
    |
    | Controls whether new user registrations are allowed in the application.
    | Set to true to allow registrations, false to restrict to existing users only.
    |
    | Env: FRESHGUARD_REGISTRATIONS_ENABLED
    |
    */
    'registrations_enabled' => (bool) env('FRESHGUARD_REGISTRATIONS_ENABLED', true),
];

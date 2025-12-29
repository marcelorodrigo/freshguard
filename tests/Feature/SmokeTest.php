<?php

namespace Tests\Feature;

it('registration is enabled when FRESHGUARD_REGISTRATIONS_ENABLED config is true', function () {
    expect(config('freshguard.registrations_enabled'))->toBeTrue();
});

it('registration can be disabled when FRESHGUARD_REGISTRATIONS_ENABLED config is false', function () {
    config()->set('freshguard.registrations_enabled', false);

    expect(config('freshguard.registrations_enabled'))->toBeFalse();
});

<?php

namespace Tests\Feature;

it('the application redirects to user registration when no user is configured', function () {
    $response = $this->get('/');
    $response->assertStatus(302)
        ->assertRedirect('/register');
});

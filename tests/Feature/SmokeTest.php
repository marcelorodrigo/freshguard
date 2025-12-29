<?php

namespace Tests\Feature;

it('It redirects to the login page', function () {
    $response = $this->get('/');
    $response->assertStatus(302)
        ->assertRedirect("/login");
});

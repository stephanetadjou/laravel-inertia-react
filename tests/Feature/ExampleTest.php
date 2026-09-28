<?php

use App\Models\User;

it('redirects a guest visiting the home page to the login page', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});

it('redirects an authenticated user from the home page to the dashboard', function () {
    $response = $this->actingAs(User::factory()->create())->get('/');

    $response->assertRedirect(route('dashboard.index'));
});

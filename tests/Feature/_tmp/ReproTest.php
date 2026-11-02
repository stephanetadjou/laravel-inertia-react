<?php

use App\Models\Organization;

it('repro', function () {
    Organization::factory()->create(['name' => 'Retail Group']);
    $r = $this->post('/register', ['organization_name' => 'RETAIL GROUP', 'store_name' => 'MOODA', 'name' => 'MOODA', 'email' => 'new@example.com', 'password' => 'Secret-123456', 'password_confirmation' => 'Secret-123456']);
    dump($r->status(), $r->exception?->getMessage());
    $r->assertRedirect();
});

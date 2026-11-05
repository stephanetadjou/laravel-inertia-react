<?php

use App\Models\Organization;
use App\Models\User;

it('ferme l import des ventes apres la reprise en gestion complete', function () {
    $organization = Organization::factory()->create(['sector' => 'commerce', 'usage_mode' => 'complete']);
    expect($organization->salesImportOpen())->toBeTrue();

    $organization->forceFill(['history_import_closed_at' => now()])->save();
    expect($organization->fresh()->salesImportOpen())->toBeFalse();

    $organization->forceFill(['usage_mode' => 'analyse'])->save();
    expect($organization->fresh()->salesImportOpen())->toBeTrue();
});

<?php

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Fichier que PHP n'a pas pu écrire dans son dossier temporaire (disque du
 * serveur plein) : UPLOAD_ERR_CANT_WRITE.
 */
function failedUpload(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($path, "telephone,boutique,date_achat\n");

    return new UploadedFile($path, 'historique-ventes-test-2026.csv', 'text/csv', UPLOAD_ERR_CANT_WRITE, true);
}

function importUser(): array
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);

    return [User::factory()->create(['organization_id' => $organization->id]), $store];
}

it('explains a file the server could not receive on the purchase import preview', function () {
    [$user] = importUser();

    $this->actingAs($user)->from('/purchases')->post('/purchases/preview', ['file' => failedUpload()])
        ->assertRedirect('/purchases')
        ->assertSessionHasErrors(['file' => "Le serveur n'a pas pu recevoir le fichier (espace disque insuffisant sur le serveur ou fichier trop volumineux). Réessayez ou contactez l'administrateur."]);
});

it('explains a missing or unsupported file in French', function (?UploadedFile $file, string $message) {
    [$user] = importUser();

    $this->actingAs($user)->post('/purchases', array_filter(['file' => $file]))
        ->assertSessionHasErrors(['file' => $message]);
})->with([
    'no file' => [null, 'Choisissez un fichier CSV ou Excel.'],
    'pdf' => [fn () => UploadedFile::fake()->create('ventes.pdf', 10, 'application/pdf'), 'Format non pris en charge : envoyez un fichier CSV, XLSX ou XLS.'],
]);

it('explains a file the server could not receive on the contact import', function () {
    [$user, $store] = importUser();

    $this->actingAs($user)->post('/imports/preview', ['store_id' => $store->id, 'file' => failedUpload()])
        ->assertSessionHasErrors(['file' => "Le serveur n'a pas pu recevoir le fichier (espace disque insuffisant sur le serveur ou fichier trop volumineux). Réessayez ou contactez l'administrateur."]);
});

it('still previews a comma separated purchase file with a UTF-8 BOM', function () {
    [$user, $store] = importUser();
    $csv = "\xEF\xBB\xBFtelephone,boutique,date_achat,nom,quantite,consentement,prix,montant,categorie\n"
        ."077123456,{$store->name},15/09/2026,Awa Mba,1,oui,15000,15000,Chaussures\n";

    $this->actingAs($user)->post('/purchases/preview', ['file' => UploadedFile::fake()->createWithContent('historique-ventes-test-2026.csv', $csv)])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('preview.purchases_to_record', 1)->where('errors', []));
});

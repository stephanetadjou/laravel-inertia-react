<?php

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

it('imports contacts and purchases from a csv for a store scoped to the user organization', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $csv = "nom,telephone,categorie,montant,date_achat\n".
        "Amanda Obame,74129782,Robes,45000,2026-09-01\n".
        "Jean Mba,66212551,Costumes,120000,2026-09-10\n";

    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $response = $this->actingAs($user)->post('/imports', [
        'store_id' => $store->id,
        'file' => $file,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    expect(Contact::where('store_id', $store->id)->count())->toBe(2);
    expect(Purchase::count())->toBe(2);
    expect(Contact::where('phone_e164', '24174129782')->exists())->toBeTrue();
});

it('previews an import without writing to the database', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $csv = "nom,telephone\n".
        "Amanda Obame,74129782\n".
        "\n". // ligne vide, doit être ignorée silencieusement
        "Jean Mba,66212551\n".
        "Test Invalide,123\n"; // numéro trop court -> ligne ignorée avec motif

    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $response = $this->actingAs($user)->post('/imports/preview', [
        'store_id' => $store->id,
        'file' => $file,
    ]);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('preview.contacts_to_create', 2)
        ->where('preview.contacts_to_update', 0)
        ->where('preview.total_valid', 2)
        ->has('preview.skipped', 1)
        ->has('preview.sample', 2),
    );

    expect(Contact::count())->toBe(0);
});

it('imports contacts from an xlsx spreadsheet', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['nom', 'telephone'],
        ['Amanda Obame', '74129782'],
        ['Jean Mba', '66212551'],
    ]);

    $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
    (new Xlsx($spreadsheet))->save($tmpPath);
    $file = new UploadedFile($tmpPath, 'contacts.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

    $response = $this->actingAs($user)->post('/imports', [
        'store_id' => $store->id,
        'file' => $file,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    expect(Contact::where('store_id', $store->id)->count())->toBe(2);
    expect(Contact::where('phone_e164', '24174129782')->exists())->toBeTrue();
});

it('refuses to import into a store belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $file = UploadedFile::fake()->createWithContent('contacts.csv', "nom,telephone\nTest,74129782\n");

    $response = $this->actingAs($user)->post('/imports', [
        'store_id' => $otherStore->id,
        'file' => $file,
    ]);

    $response->assertStatus(404);
});

it('refuses to preview an import into a store belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $file = UploadedFile::fake()->createWithContent('contacts.csv', "nom,telephone\nTest,74129782\n");

    $response = $this->actingAs($user)->post('/imports/preview', [
        'store_id' => $otherStore->id,
        'file' => $file,
    ]);

    $response->assertStatus(404);
});

it('normalizes Gabonese number variants and explains why 9-digit numbers are skipped', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $csv = "nom,telephone\n".
        "Amanda Obame,+241 62 60 50 54\n".
        "Jean Mba,00241 77 12 34 56\n".
        "Paul Nze,066212551\n".
        "Doublon Amanda,062605054\n". // même numéro qu'Amanda une fois normalisé
        "Neuf Chiffres,612345678\n";

    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $response = $this->actingAs($user)->post('/imports/preview', [
        'store_id' => $store->id,
        'file' => $file,
    ]);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('preview.total_valid', 4)
        ->has('preview.skipped', 1)
        ->where('preview.skipped.0', fn ($reason) => str_contains($reason, 'Ligne 6')
            && str_contains($reason, '612345678')
            && str_contains($reason, '8 chiffres commençant par 6 ou 7')),
    );

    $this->actingAs($user)->post('/imports', [
        'store_id' => $store->id,
        'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
    ])->assertRedirect();

    expect(Contact::where('store_id', $store->id)->orderBy('phone_e164')->pluck('phone_e164')->all())
        ->toBe(['24162605054', '24166212551', '24177123456']);
});

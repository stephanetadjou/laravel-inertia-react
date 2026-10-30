<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\SalesImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->organization = Organization::factory()->create(['sector' => 'commerce']);
    Role::seedDefaultsFor($this->organization);
    $this->brandA = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Enseigne A']);
    $this->brandB = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Enseigne B']);
    $this->storeA = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandA->id, 'name' => 'A Centre', 'code' => 'A01']);
    $this->storeB = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandB->id, 'name' => 'B Gare', 'code' => 'B01']);
    $this->admin = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Admin groupe')->value('id'),
    ]);
    $this->boissons = Category::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Boissons']);
    $this->epicerie = Category::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Épicerie']);
});

function salesCsv(array $lines): UploadedFile
{
    $content = implode("\n", array_map(fn ($line) => implode(';', $line), $lines))."\n";

    return UploadedFile::fake()->createWithContent('ventes.csv', $content);
}

function standardSales(): UploadedFile
{
    return salesCsv([
        ['Date', 'Magasin', 'Ticket', 'Téléphone', 'Nom', 'Famille', 'Article', 'Qté', 'Prix', 'Montant', 'Genre'],
        ['01/09/2026', 'A01', 'T1', '62 60 50 54', 'Awa Ndong', 'Boissons', 'Coca 33cl', '2', '500', '1000', 'Femme'],
        ['01/09/2026', 'A01', 'T1', '62 60 50 54', 'Awa Ndong', 'Épicerie', 'Riz 5kg', '1', '4500', '4500', 'Femme'],
        ['02/09/2026', 'B01', 'T9', '', '', 'Boissons', 'Eau 1,5L', '1', '400', '400', ''],
        ['03/09/2026', 'B01', 'T10', '062605054', '', 'Boissons', '', '1', '', '2000', ''],
        ['04/09/2026', 'A01', 'R1', '62605054', '', 'Épicerie', 'Riz 5kg', '1', '4500', '-4500', ''],
        ['', 'A01', 'T2', '62605054', '', 'Boissons', '', '1', '', '100', ''],
        ['05/09/2026', 'A01', 'T3', '62605054', '', '', 'Article mystère', '1', '', '300', ''],
    ]);
}

function uploadAndMap($test, UploadedFile $file, array $extra = []): ImportBatch
{
    $test->actingAs($test->admin)->post('/sales-imports', ['file' => $file, ...$extra])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();

    $test->actingAs($test->admin)->post("/sales-imports/{$batch->id}/mapping", [
        'mapping' => $batch->mapping,
        'default_category_id' => $extra['default_category_id'] ?? null,
    ])->assertRedirect();

    return $batch->refresh();
}

it('recognizes columns, checks every row and reports errors by line number', function () {
    $batch = uploadAndMap($this, standardSales());

    expect($batch->mapping)->toMatchArray(['date' => 0, 'store' => 1, 'receipt' => 2, 'phone' => 3, 'name' => 4, 'family' => 5, 'article' => 6, 'quantity' => 7, 'unit_price' => 8, 'line_total' => 9])
        ->and($batch->status)->toBe(ImportBatch::STATUS_VALIDATED)
        ->and($batch->accepted_rows)->toBe(5)
        ->and($batch->rejected_rows)->toBe(2);

    $errors = $batch->rows()->where('status', 'rejected')->orderBy('row_number')->get();
    expect($errors->pluck('row_number')->all())->toBe([7, 8])
        ->and($errors->pluck('error_code')->all())->toBe(['date_missing', 'family_missing']);

    // Rien n'est créé avant la confirmation.
    expect(Purchase::count())->toBe(0);

    $this->actingAs($this->admin)->get("/sales-imports/{$batch->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('SalesImports/Show')->where('preview.tickets', 4)->where('preview.anonymous', 1));
});

it('creates tickets with lines, keeps anonymous sales and nets returns', function () {
    $batch = uploadAndMap($this, standardSales());
    $this->actingAs($this->admin)->post("/sales-imports/{$batch->id}/confirm")->assertRedirect();
    $batch->refresh();

    expect($batch->status)->toBe(ImportBatch::STATUS_COMPLETED_WITH_ERRORS)
        ->and($batch->tickets_count)->toBe(4)
        ->and($batch->anonymous_tickets)->toBe(1);

    $t1 = Purchase::where('receipt_number', 'T1')->sole();
    expect($t1->amount)->toBe(5500)
        ->and($t1->items)->toHaveCount(2)
        ->and($t1->items->pluck('category_id')->sort()->values()->all())->toBe(collect([$this->boissons->id, $this->epicerie->id])->sort()->values()->all())
        ->and($t1->items->first()->properties)->toBe(['genre' => 'Femme']);

    // Vente anonyme : conservée, sans client.
    $anonymous = Purchase::where('receipt_number', 'T9')->sole();
    expect($anonymous->contact_id)->toBeNull()->and($anonymous->amount)->toBe(400);

    // Retour : ticket négatif, ne compte pas comme un achat.
    $return = Purchase::where('receipt_number', 'R1')->sole();
    expect($return->type)->toBe(Purchase::TYPE_RETURN)->and($return->amount)->toBe(-4500);

    // Un même numéro (format local avec 0) dans deux marques = une seule fiche.
    $customer = Customer::where('phone_e164', '24162605054')->sole();
    expect(Customer::count())->toBe(1)
        ->and($customer->contacts()->count())->toBe(2)
        ->and($customer->total_spent)->toBe(5500 + 2000 - 4500)
        ->and($customer->purchases_count)->toBe(2);

    expect(AuditLog::where('action', 'import.completed')->exists())->toBeTrue();
});

it('refuses the same file twice unless the first import is cancelled', function () {
    $batch = uploadAndMap($this, standardSales());
    $this->actingAs($this->admin)->post("/sales-imports/{$batch->id}/confirm");
    $count = Purchase::count();

    $this->actingAs($this->admin)->post('/sales-imports', ['file' => standardSales()])->assertSessionHasErrors('file');
    expect(ImportBatch::count())->toBe(1);

    $this->actingAs($this->admin)->post("/sales-imports/{$batch->id}/cancel")->assertSessionHas('success');
    expect(Purchase::withCancelled()->count())->toBe(0)
        ->and($batch->fresh()->status)->toBe(ImportBatch::STATUS_CANCELLED)
        ->and(Customer::sole()->total_spent)->toBe(0)
        ->and(AuditLog::where('action', 'import.cancelled')->sole()->payload['deleted'])->toBe($count);

    $this->actingAs($this->admin)->post('/sales-imports', ['file' => standardSales()])->assertRedirect();
    expect(ImportBatch::count())->toBe(2);
});

it('marks tickets already imported from another file as duplicates', function () {
    $first = uploadAndMap($this, standardSales());
    $this->actingAs($this->admin)->post("/sales-imports/{$first->id}/confirm");

    $second = uploadAndMap($this, salesCsv([
        ['Date', 'Magasin', 'Ticket', 'Téléphone', 'Famille', 'Montant'],
        ['01/09/2026', 'A01', 'T1', '62605054', 'Boissons', '1000'],
        ['06/09/2026', 'A01', 'T50', '62605054', 'Boissons', '700'],
    ]));

    expect($second->duplicate_rows)->toBe(1)->and($second->accepted_rows)->toBe(1);

    $this->actingAs($this->admin)->post("/sales-imports/{$second->id}/confirm");
    expect(Purchase::where('receipt_number', 'T1')->count())->toBe(1)
        ->and(Purchase::where('receipt_number', 'T50')->exists())->toBeTrue();
});

it('accepts a totals-only file with a default family and a fixed store', function () {
    $batch = uploadAndMap($this, salesCsv([
        ['Date', 'Total', 'Téléphone'],
        ['10/09/2026', '15 000', '74 11 22 33'],
        ['10/09/2026', '8 500', ''],
    ]), ['store_id' => $this->storeA->id, 'default_category_id' => $this->boissons->id]);

    expect($batch->accepted_rows)->toBe(2);
    $this->actingAs($this->admin)->post("/sales-imports/{$batch->id}/confirm");

    expect(Purchase::where('store_id', $this->storeA->id)->sum('amount'))->toBe(23500)
        ->and(Purchase::with('items')->get()->flatMap->items->pluck('category_id')->unique()->all())->toBe([$this->boissons->id]);
});

it('requires a family column or a default family in the mapping', function () {
    $this->actingAs($this->admin)->post('/sales-imports', ['file' => salesCsv([['Date', 'Magasin', 'Montant'], ['10/09/2026', 'A01', '100']])]);
    $batch = ImportBatch::latest('id')->firstOrFail();

    $this->actingAs($this->admin)->post("/sales-imports/{$batch->id}/mapping", ['mapping' => $batch->mapping])
        ->assertSessionHasErrors('mapping');
});

it('records business customers and their source customer code', function () {
    $batch = uploadAndMap($this, salesCsv([
        ['Date', 'Magasin', 'Facture', 'Code client', 'Client', 'Type client', 'Téléphone', 'Famille', 'Montant'],
        ['11/09/2026', 'A01', 'F100', 'CLI-042', 'Dépôt Mbolo', 'Grossiste', '66 12 34 56', 'Épicerie', '850000'],
        ['12/09/2026', 'A01', 'F101', 'CLI-042', 'Dépôt Mbolo', 'Grossiste', '', 'Épicerie', '420000'],
    ]));
    $this->actingAs($this->admin)->post("/sales-imports/{$batch->id}/confirm");

    $customer = Customer::where('phone_e164', '24166123456')->sole();
    expect($customer->type)->toBe(Customer::TYPE_BUSINESS)
        ->and($customer->company_name)->toBe('Dépôt Mbolo')
        ->and($customer->external_code)->toBe('CLI-042')
        // La seconde facture n'a que le code client : rattachée à la même fiche.
        ->and($customer->total_spent)->toBe(1270000);
});

it('keeps a brand manager inside their brand', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Responsable de marque')->value('id'),
        'brand_id' => $this->brandA->id,
    ]);

    $this->actingAs($manager)->post('/sales-imports', ['file' => standardSales()])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->brand_id)->toBe($this->brandA->id);

    $this->actingAs($manager)->post("/sales-imports/{$batch->id}/mapping", ['mapping' => $batch->mapping]);
    $codes = $batch->rows()->where('status', 'rejected')->pluck('error_code')->all();
    // Les lignes du point de vente B (autre marque) sont refusées.
    expect($codes)->toContain('store_unknown');

    // Un import de la marque B n'est pas visible.
    $other = ImportBatch::create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandB->id, 'store_id' => $this->storeB->id, 'original_filename' => 'b.csv', 'file_path' => 'x', 'file_hash' => 'h', 'status' => 'validated']);
    $this->actingAs($manager)->get("/sales-imports/{$other->id}")->assertForbidden();
});

it('forbids imports to roles without the import right', function () {
    $marketing = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Marketing')->value('id'),
    ]);

    $this->actingAs($marketing)->get('/sales-imports')->assertForbidden();
    $this->actingAs($marketing)->post('/sales-imports', ['file' => standardSales()])->assertForbidden();
});

it('downloads the error report as CSV and XLSX', function () {
    $batch = uploadAndMap($this, standardSales());

    $csv = $this->actingAs($this->admin)->get("/sales-imports/{$batch->id}/errors")->assertOk()->streamedContent();
    expect($csv)->toContain('Statut;Code;Erreur')->toContain('7;Rejetée;date_missing');

    $this->actingAs($this->admin)->get("/sales-imports/{$batch->id}/errors?format=xlsx")->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('reads money in several formats', function (string $raw, float $expected) {
    expect(SalesImport::parseNumber($raw))->toBe($expected);
})->with([
    ['12 500', 12500.0],
    ['12.500', 12500.0],
    ['1,250,000', 1250000.0],
    ['12500,50', 12500.5],
    ['-3 000 FCFA', -3000.0],
    ['12,500.75', 12500.75],
]);

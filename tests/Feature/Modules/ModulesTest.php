<?php

use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function moduleAdmin(array $organization = []): User
{
    $org = Organization::factory()->create($organization);

    return User::factory()->create(['organization_id' => $org->id, 'role_id' => defaultRole($org, 'Super administrateur')->id]);
}

it('pre-checks the modules of the sector and adjusts them with the answers at registration', function () {
    $this->post('/register', [
        'organization_name' => 'Club Sport Libreville',
        'sector' => 'sport',
        'store_name' => 'Terrain Akaé',
        'name' => 'Directeur',
        'email' => 'club@example.com',
        'password' => 'Secret-123456',
        'password_confirmation' => 'Secret-123456',
        'answers' => ['rdv' => false, 'seances_groupe' => true, 'abonnements' => true, 'tranches' => true, 'beneficiaires' => true, 'lieux' => true],
        'settings' => ['seances_groupe' => ['capacity' => 25, 'makeup_after' => 2, 'makeup_weekday' => 6], 'beneficiaires' => ['label' => 'eleve', 'minor_needs_responsible' => true]],
    ])->assertRedirect('/dashboard');

    $organization = Organization::where('name', 'Club Sport Libreville')->firstOrFail();

    expect($organization->enabledModules())->toEqualCanonicalizing(['seances_groupe', 'abonnements', 'tranches', 'beneficiaires', 'lieux', 'catalogue', 'fidelite'])
        ->and($organization->hasAgenda())->toBeFalse()
        ->and($organization->moduleSettings('seances_groupe')['capacity'])->toBe(25)
        ->and($organization->moduleSettings('tranches')['due_day'])->toBe(5)
        ->and($organization->beneficiaryLabel(true))->toBe('Élèves');

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('auth.organization.modules', fn ($modules) => collect($modules)->contains('seances_groupe') && ! collect($modules)->contains('rdv'))
        ->where('auth.organization.beneficiaries_label', 'Élèves'));
});

it('applies the sector preset when only the sector is chosen', function () {
    $this->post('/register', [
        'organization_name' => 'Salon Beauté', 'sector' => 'beaute', 'store_name' => 'Salon', 'name' => 'Gérante',
        'email' => 'salon@example.com', 'password' => 'Secret-123456', 'password_confirmation' => 'Secret-123456',
    ])->assertRedirect('/dashboard');

    expect(Organization::where('name', 'Salon Beauté')->firstOrFail()->enabledModules())->toBe(['rdv', 'prospects', 'reponses_auto', 'rappels_rdv', 'relance_cycle', 'catalogue']);
});

it('gives historical organizations every module that existed before', function () {
    $legacy = Organization::factory()->create(['sector' => null]);
    $retail = Organization::factory()->create(['sector' => 'commerce']);

    expect($legacy->enabledModules())->toBe(['rdv', 'lieux', 'catalogue', 'produits', 'fidelite', 'campagnes'])
        ->and($retail->hasModule('familles'))->toBeTrue()
        ->and($retail->hasAgenda())->toBeFalse()
        ->and($retail->hasModule('catalogue'))->toBeFalse();
});

it('lets the manager switch modules and their settings', function () {
    $admin = moduleAdmin(['sector' => 'beaute', 'whatsapp_api_enabled' => false]);

    $this->actingAs($admin)->get('/settings/modules')->assertInertia(fn (Assert $page) => $page
        ->component('Settings/Modules')
        ->where('modules.0.key', 'rdv')
        ->where('modules.0.enabled', true)
        ->where('settings.tranches.due_day', 5));

    $this->actingAs($admin)->put('/settings/modules', [
        'enabled' => ['rdv', 'tranches', 'campagnes'],
        'settings' => ['tranches' => ['due_day' => 10, 'reminders' => [-3, 0]]],
    ])->assertRedirect();

    $organization = $admin->organization->fresh();
    expect($organization->enabledModules())->toBe(['rdv', 'tranches', 'campagnes'])
        ->and($organization->whatsapp_api_enabled)->toBeTrue()
        ->and($organization->moduleSettings('tranches'))->toBe(['due_day' => 10, 'reminders' => [-3, 0]])
        ->and($organization->loyaltySettings()['enabled'])->toBeFalse();

    // Modules décochés : écrans fermés.
    $this->actingAs($admin)->get('/catalog')->assertRedirect('/dashboard');
    $this->actingAs($admin)->get('/settings/loyalty')->assertRedirect('/dashboard');
    $this->actingAs($admin)->get('/appointments')->assertOk();
});

it('closes the agenda screens without the appointments module', function () {
    $admin = moduleAdmin(['sector' => 'commerce']);

    $this->actingAs($admin)->get('/appointments')->assertRedirect('/dashboard');
    $this->actingAs($admin)->get('/settings/booking')->assertRedirect('/dashboard');
    $this->actingAs($admin)->get('/settings/staff')->assertRedirect('/dashboard');
});

it('allows a single establishment without the multiple places module', function () {
    $admin = moduleAdmin(['sector' => 'beaute']);
    $brand = Brand::factory()->create(['organization_id' => $admin->organization_id]);
    Store::factory()->create(['organization_id' => $admin->organization_id, 'brand_id' => $brand->id]);

    $this->actingAs($admin)->post('/stores', ['brand_id' => $brand->id, 'name' => 'Deuxième salon'])->assertSessionHas('error');
    $this->actingAs($admin)->post('/brands', ['name' => 'Autre marque'])->assertSessionHas('error');
    expect(Store::where('organization_id', $admin->organization_id)->count())->toBe(1);

    $this->actingAs($admin)->get('/stores')->assertInertia(fn (Assert $page) => $page->where('canAddStores', false));
});

it('links imported lines to the catalogue product and its category when retail uses the catalogue', function () {
    Storage::fake('local');
    $admin = moduleAdmin(['sector' => 'commerce']);
    $organization = $admin->organization;
    $organization->setModules(['familles', 'lieux', 'catalogue']);
    $organization->save();
    $admin->update(['role_id' => Role::where('organization_id', $organization->id)->where('name', 'Super administrateur')->value('id')]);
    $brand = Brand::factory()->create(['organization_id' => $organization->id]);
    Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id, 'code' => 'A01']);
    $sodas = Category::factory()->create(['organization_id' => $organization->id, 'name' => 'Sodas']);
    $coca = CatalogItem::create(['organization_id' => $organization->id, 'type' => 'product', 'name' => 'Coca 33cl', 'reference' => 'COC33', 'category_id' => $sodas->id, 'price' => 500]);

    $csv = "Date;Magasin;Ticket;Article;Code;Montant\n01/09/2026;A01;T1;COCA 33CL;;1000\n01/09/2026;A01;T2;Canette;COC33;500\n";
    $this->actingAs($admin)->post('/sales-imports', ['file' => UploadedFile::fake()->createWithContent('ventes.csv', $csv)])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    $mapping = [...$batch->mapping, 'article_code' => 4];
    $this->actingAs($admin)->post("/sales-imports/{$batch->id}/mapping", ['mapping' => $mapping])->assertRedirect();
    expect($batch->refresh()->accepted_rows)->toBe(2);

    $this->actingAs($admin)->post("/sales-imports/{$batch->id}/confirm")->assertRedirect();

    $items = PurchaseItem::orderBy('id')->get();
    expect($items)->toHaveCount(2)
        ->and($items->pluck('catalog_item_id')->all())->toBe([$coca->id, $coca->id])
        ->and($items->pluck('category_id')->all())->toBe([$sodas->id, $sodas->id]);
});

it('adds products to the catalogue only when the company sells some', function (bool $sellsProducts) {
    $this->post('/register', [
        'organization_name' => 'Garage Akanda', 'sector' => 'technique', 'store_name' => 'Garage', 'name' => 'Chef',
        'email' => 'garage@example.com', 'password' => 'Secret-123456', 'password_confirmation' => 'Secret-123456',
        'answers' => ['produits' => $sellsProducts],
    ])->assertRedirect('/dashboard');

    $organization = Organization::where('name', 'Garage Akanda')->firstOrFail();
    expect($organization->hasModule('produits'))->toBe($sellsProducts)
        ->and($organization->hasModule('rdv'))->toBeTrue();

    $this->get('/catalog')->assertInertia(fn (Assert $page) => $page->where('productsEnabled', $sellsProducts));

    $response = $this->post('/catalog', ['type' => 'product', 'name' => 'Huile moteur 5L', 'price' => 25000]);
    $sellsProducts ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('type');
    expect(CatalogItem::where('type', 'product')->exists())->toBe($sellsProducts);
})->with([true, false]);

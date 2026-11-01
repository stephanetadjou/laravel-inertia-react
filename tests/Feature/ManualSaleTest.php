<?php

use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Loyalty;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-01 15:00'));
});

/** Salon (secteur à agenda) avec un catalogue et la fidélité lancée. */
function salonSetup(): array
{
    $organization = Organization::factory()->create([
        'sector' => 'beaute',
        'loyalty_settings' => ['enabled' => true, 'fcfa_per_point' => 1000, 'starts_on' => '2026-01-01'],
    ]);
    $brand = Brand::factory()->create(['organization_id' => $organization->id]);
    $store = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id, 'name' => 'Salon Louis']);
    $admin = User::factory()->create(['organization_id' => $organization->id]);
    $coupe = CatalogItem::factory()->create(['organization_id' => $organization->id, 'name' => 'Coupe', 'price' => 8000, 'type' => 'service']);

    return [$organization, $store, $admin, $coupe];
}

/** Groupe de commerce à deux marques, avec ses rôles types et deux familles. */
function retailSetup(): array
{
    $organization = Organization::factory()->create(['sector' => 'commerce']);
    Role::seedDefaultsFor($organization);
    $adidas = Brand::factory()->create(['organization_id' => $organization->id, 'name' => 'Adidas']);
    $mango = Brand::factory()->create(['organization_id' => $organization->id, 'name' => 'Mango']);
    $adidasStore = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $adidas->id, 'name' => 'Adidas Glass']);
    $mangoStore = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $mango->id, 'name' => 'Mango Centre']);
    $role = fn (string $name) => Role::where('organization_id', $organization->id)->where('name', $name)->value('id');
    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => $role('Admin groupe')]);
    $robes = Category::factory()->create(['organization_id' => $organization->id, 'name' => 'Robes']);
    $sneakers = Category::factory()->create(['organization_id' => $organization->id, 'name' => 'Sneakers']);

    return compact('organization', 'adidas', 'mango', 'adidasStore', 'mangoStore', 'admin', 'robes', 'sneakers', 'role');
}

function salePayload(array $overrides = []): array
{
    return [
        'customer_mode' => 'new',
        'name' => 'Estelle Moussavou',
        'phone' => '077 12 34 56',
        'purchased_at' => '2026-11-01',
        'payment_method' => 'airtel_money',
        ...$overrides,
    ];
}

it('records a sale for a new customer in a salon: lines, discount, points and audit', function () {
    [$organization, $store, $admin, $coupe] = salonSetup();

    $this->actingAs($admin)->get('/purchases')->assertOk()
        ->assertInertia(fn ($page) => $page->where('manualSale.retail', false)->has('manualSale.catalog', 1)->has('manualSale.stores', 1));

    $this->actingAs($admin)->post('/purchases/manual', salePayload([
        'store_id' => $store->id,
        'receipt_number' => 'S-001',
        'note' => 'Commande par téléphone',
        'lines' => [
            ['catalog_item_id' => $coupe->id, 'quantity' => 1, 'unit_price' => 8000, 'discount' => 1000],
            ['catalog_item_id' => null, 'name' => 'Huile capillaire', 'quantity' => 2, 'unit_price' => 4000],
        ],
    ]))->assertRedirect(route('purchases.index'))->assertSessionHas('success')->assertSessionHasNoErrors();

    $purchase = Purchase::with('items')->sole();
    $customer = Customer::where('phone_e164', '24177123456')->sole();

    expect($purchase)
        ->amount->toBe(15000)
        ->subtotal->toBe(16000)
        ->discount_amount->toBe(1000)
        ->source->toBe('manual')
        ->payment_method->toBe('airtel_money')
        ->receipt_number->toBe('S-001')
        ->note->toBe('Commande par téléphone')
        ->and($purchase->items)->toHaveCount(2)
        ->and($purchase->items->firstWhere('catalog_item_id', $coupe->id)->amount)->toBe(7000)
        ->and($purchase->contact->customer_id)->toBe($customer->id)
        ->and($customer->name)->toBe('Estelle Moussavou')
        ->and($customer->purchases_count)->toBe(1)
        ->and($customer->total_spent)->toBe(15000)
        ->and(Loyalty::for($organization)->balance($customer))->toBe(15)
        ->and(AuditLog::where('action', 'sale.created')->where('subject_id', $purchase->id)->exists())->toBeTrue();

    // Même numéro de ticket dans le même point de vente : refusé.
    $this->actingAs($admin)->post('/purchases/manual', salePayload([
        'store_id' => $store->id, 'receipt_number' => 'S-001',
        'lines' => [['catalog_item_id' => $coupe->id, 'quantity' => 1, 'unit_price' => 8000]],
    ]))->assertSessionHasErrors(['receipt_number' => 'Ce numéro de ticket existe déjà pour ce point de vente.']);
    expect(Purchase::count())->toBe(1);
});

it('records a Retail sale for an existing customer: family required, article kept, second brand', function () {
    $r = retailSetup();
    $contact = Contact::factory()->create(['store_id' => $r['adidasStore']->id, 'phone_e164' => '24166445566', 'name' => 'Ben Obame']);
    $ben = $contact->fresh()->customer;

    $this->actingAs($r['admin'])->get('/purchases')
        ->assertInertia(fn ($page) => $page->where('manualSale.retail', true)->has('manualSale.families', 2)->where('manualSale.family_label', 'Famille'));

    $this->actingAs($r['admin'])->getJson('/purchases/manual/customers?q=ben')->assertOk()->assertJsonPath('0.id', $ben->id);

    $this->actingAs($r['admin'])->post('/purchases/manual', [
        'customer_mode' => 'existing', 'customer_id' => $ben->id, 'store_id' => $r['mangoStore']->id, 'purchased_at' => '2026-10-31',
        'lines' => [['quantity' => 1, 'unit_price' => 30000]],
    ])->assertSessionHasErrors(['lines.0.category_id' => 'Choisissez la famille de chaque ligne.']);

    $this->actingAs($r['admin'])->post('/purchases/manual', [
        'customer_mode' => 'existing', 'customer_id' => $ben->id, 'store_id' => $r['mangoStore']->id, 'purchased_at' => '2026-10-31', 'payment_method' => 'cash',
        'lines' => [['category_id' => $r['robes']->id, 'article' => 'Robe portefeuille', 'quantity' => 1, 'unit_price' => 30000]],
    ])->assertSessionHasNoErrors();

    $purchase = Purchase::with('items')->sole();
    expect($purchase->category_id)->toBe($r['robes']->id)
        ->and($purchase->article)->toBe('Robe portefeuille')
        ->and($purchase->items->first()->article_id)->toBe(Article::where('label', 'Robe portefeuille')->value('id'))
        ->and($purchase->contact->store_id)->toBe($r['mangoStore']->id)
        ->and($purchase->contact->customer_id)->toBe($ben->id)
        ->and($ben->fresh()->brands_count)->toBe(1);

    // Deux marques : Adidas (ticket existant) puis Mango.
    Purchase::create(['contact_id' => $contact->id, 'store_id' => $r['adidasStore']->id, 'amount' => 20000, 'quantity' => 1, 'purchased_at' => '2026-10-01', 'category_id' => $r['sneakers']->id]);
    $this->artisan('crm:refresh-lifecycle')->assertSuccessful();
    expect($ben->fresh()->brands_count)->toBe(2);
});

it('records an anonymous sale without creating any customer', function () {
    $r = retailSetup();

    $this->actingAs($r['admin'])->post('/purchases/manual', [
        'customer_mode' => 'anonymous', 'buyer_name' => 'Passage', 'store_id' => $r['adidasStore']->id, 'purchased_at' => '2026-11-01',
        'lines' => [['category_id' => $r['sneakers']->id, 'quantity' => 2, 'unit_price' => 25000, 'discount' => 5000]],
    ])->assertSessionHasNoErrors();

    expect(Purchase::sole())->contact_id->toBeNull()->buyer_name->toBe('Passage')->amount->toBe(45000)
        ->and(Customer::count())->toBe(0)
        ->and(Contact::count())->toBe(0);
});

it('validates the form in French', function () {
    [, $store, $admin, $coupe] = salonSetup();
    $line = ['catalog_item_id' => $coupe->id, 'quantity' => 1, 'unit_price' => 8000];

    $this->actingAs($admin)->post('/purchases/manual', ['customer_mode' => 'new', 'store_id' => $store->id, 'purchased_at' => '2026-11-01', 'lines' => [$line]])
        ->assertSessionHasErrors(['name' => 'Indiquez le nom du nouveau client.', 'phone' => 'Indiquez le numéro de téléphone du nouveau client.']);
    $this->actingAs($admin)->post('/purchases/manual', salePayload(['store_id' => $store->id, 'purchased_at' => '2026-11-02', 'lines' => [$line]]))
        ->assertSessionHasErrors(['purchased_at' => 'La date de la vente ne peut pas être dans le futur.']);
    $this->actingAs($admin)->post('/purchases/manual', salePayload(['store_id' => $store->id, 'lines' => []]))
        ->assertSessionHasErrors(['lines' => 'Ajoutez au moins une ligne.']);
    $this->actingAs($admin)->post('/purchases/manual', salePayload(['store_id' => $store->id, 'lines' => [[...$line, 'discount' => 9000]]]))
        ->assertSessionHasErrors(['lines.0.discount' => 'La remise de la ligne 1 dépasse son montant.']);
    $this->actingAs($admin)->post('/purchases/manual', salePayload(['store_id' => $store->id, 'lines' => [['quantity' => 1, 'unit_price' => 1000]]]))
        ->assertSessionHasErrors(['lines.0.name' => 'Indiquez le libellé de la ligne 1.']);
    $this->actingAs($admin)->post('/purchases/manual', salePayload(['store_id' => $store->id, 'phone' => '12', 'lines' => [$line]]))
        ->assertSessionHasErrors(['phone' => 'Numéro de téléphone invalide.']);

    expect(Purchase::count())->toBe(0);
});

it('keeps a brand manager inside their scope', function () {
    $r = retailSetup();
    $manager = User::factory()->create(['organization_id' => $r['organization']->id, 'role_id' => $r['role']('Responsable de marque'), 'brand_id' => $r['mango']->id]);
    $adidasCustomer = Contact::factory()->create(['store_id' => $r['adidasStore']->id, 'phone_e164' => '24166445566', 'name' => 'Ben Obame'])->fresh()->customer;
    $line = [['category_id' => $r['robes']->id, 'quantity' => 1, 'unit_price' => 10000]];

    $this->actingAs($manager)->get('/purchases')
        ->assertInertia(fn ($page) => $page->where('manualSale.stores', [['id' => $r['mangoStore']->id, 'name' => 'Mango Centre']]));

    $this->actingAs($manager)->post('/purchases/manual', salePayload(['store_id' => $r['adidasStore']->id, 'lines' => $line]))
        ->assertSessionHasErrors(['store_id' => 'Ce point de vente n’est pas dans votre périmètre.']);
    $this->actingAs($manager)->post('/purchases/manual', ['customer_mode' => 'existing', 'customer_id' => $adidasCustomer->id, 'store_id' => $r['mangoStore']->id, 'purchased_at' => '2026-11-01', 'lines' => $line])
        ->assertSessionHasErrors(['customer_id' => 'Ce client n’est pas dans votre périmètre.']);
    $this->actingAs($manager)->getJson('/purchases/manual/customers?q=ben')->assertOk()->assertExactJson([]);

    // Le même client, saisi par son numéro : rattaché à sa fiche du groupe.
    $this->actingAs($manager)->post('/purchases/manual', salePayload(['name' => 'Ben O.', 'phone' => '066 44 55 66', 'store_id' => $r['mangoStore']->id, 'lines' => $line]))
        ->assertSessionHasNoErrors();
    expect(Purchase::sole()->contact->customer_id)->toBe($adidasCustomer->id);
});

it('shows the button and accepts sales only for roles allowed to record them', function () {
    $r = retailSetup();
    $direction = User::factory()->create(['organization_id' => $r['organization']->id, 'role_id' => $r['role']('Direction')]);

    $this->actingAs($direction)->get('/purchases')->assertOk()->assertInertia(fn ($page) => $page->where('manualSale', null));
    $this->actingAs($direction)->post('/purchases/manual', salePayload(['store_id' => $r['adidasStore']->id, 'lines' => [['category_id' => $r['robes']->id, 'quantity' => 1, 'unit_price' => 1000]]]))->assertForbidden();
    $this->actingAs($direction)->getJson('/purchases/manual/customers?q=ben')->assertForbidden();

    // Secteur à agenda : toute l'équipe encaisse, la saisie manuelle aussi.
    [$organization, $store] = salonSetup();
    $storeManager = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Responsable de boutique')->id, 'store_id' => $store->id]);
    $this->actingAs($storeManager)->get('/purchases')->assertInertia(fn ($page) => $page->has('manualSale.stores', 1));
});

it('suggests customers as you type: name without accents, partial phone, customer code, scope', function () {
    $r = retailSetup();
    $manager = User::factory()->create(['organization_id' => $r['organization']->id, 'role_id' => $r['role']('Responsable de marque'), 'brand_id' => $r['mango']->id]);
    $aicha = Contact::factory()->create(['store_id' => $r['mangoStore']->id, 'phone_e164' => '24177123456', 'name' => 'Aïcha Makaya'])->fresh()->customer;
    $aicha->update(['external_code' => 'CL-0042', 'rfm_segment' => 'champions', 'last_purchase_at' => '2026-10-20']);
    Contact::factory()->create(['store_id' => $r['mangoStore']->id, 'phone_e164' => '24166000111', 'name' => 'Brice Ndong']);
    $adidasOnly = Contact::factory()->create(['store_id' => $r['adidasStore']->id, 'phone_e164' => '24174999888', 'name' => 'Aïcha Obiang'])->fresh()->customer;

    $search = fn (User $user, string $q) => collect($this->actingAs($user)->getJson('/purchases/manual/customers?q='.urlencode($q))->assertOk()->json())->pluck('id')->all();

    // Nom partiel, sans accents ni majuscules, plusieurs mots dans le désordre.
    expect($search($r['admin'], 'aich'))->toContain($aicha->id, $adidasOnly->id)
        ->and($search($r['admin'], 'AICHA'))->toContain($aicha->id)
        ->and($search($r['admin'], 'mak aïc'))->toBe([$aicha->id])
        // Début de mot : « ndo » trouve Ndong, « ong » ne trouve rien.
        ->and($search($r['admin'], 'ndo'))->toHaveCount(1)
        ->and($search($r['admin'], 'ong'))->toBe([])
        ->and($search($r['admin'], 'a'))->toBe([]);

    // Téléphone partiel : format local avec 0, avec l'indicatif, avec espaces.
    expect($search($r['admin'], '0771234'))->toBe([$aicha->id])
        ->and($search($r['admin'], '+241 77 12'))->toBe([$aicha->id])
        ->and($search($r['admin'], '3456'))->toBe([$aicha->id]);

    // Code client, et détail de la suggestion.
    expect($search($r['admin'], 'cl-0042'))->toBe([$aicha->id]);
    $this->actingAs($r['admin'])->getJson('/purchases/manual/customers?q=cl-0042')
        ->assertExactJson([['id' => $aicha->id, 'name' => 'Aïcha Makaya', 'phone' => '24177123456', 'code' => 'CL-0042', 'segment' => 'Champions', 'last_purchase_at' => '2026-10-20']]);

    // Périmètre : la responsable Mango ne voit pas la cliente d'Adidas seulement.
    expect($search($manager, 'aicha'))->toBe([$aicha->id]);

    // Un nom modifié est retrouvé sous son nouveau nom.
    $aicha->update(['name' => 'Aïcha Nzé']);
    expect($search($r['admin'], 'nze'))->toBe([$aicha->id]);
});

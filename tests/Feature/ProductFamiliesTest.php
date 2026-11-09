<?php

use App\Models\Category;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerSearch;
use App\Services\DailyReport;
use App\Services\FamilyAnalytics;
use App\Services\FamilyMatcher;
use Illuminate\Http\UploadedFile;

function familiesSetup(): array
{
    $organization = Organization::factory()->create(['sector' => 'commerce']);
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $store = Store::factory()->create(['organization_id' => $organization->id, 'name' => 'Carré']);

    return [$organization, $user, $store];
}

function familyPurchase(Store $store, string $phone, array $attributes): Purchase
{
    $contact = Contact::firstOrCreate(
        ['store_id' => $store->id, 'phone_e164' => $phone],
        ['name' => "Client {$phone}", 'consent_status' => 'opt_in'],
    );

    return Purchase::create(['contact_id' => $contact->id, 'store_id' => $store->id, 'amount' => 1000, 'quantity' => 1, 'purchased_at' => today(), ...$attributes]);
}

it('normalizes labels for matching', function () {
    expect(FamilyMatcher::normalize('  Crèmes  Visage ! '))->toBe('creme visage')
        ->and(FamilyMatcher::normalize('BOISSONS'))->toBe('boisson')
        ->and(FamilyMatcher::normalize('Jus'))->toBe('jus');
});

it('recognizes the family by name, keyword or saved rule, and leaves the rest to classify', function () {
    [$organization, , $store] = familiesSetup();
    $boissons = Category::create(['organization_id' => $organization->id, 'name' => 'Boissons', 'keywords' => 'coca, fanta, jus']);
    $hygiene = Category::create(['organization_id' => $organization->id, 'name' => 'Hygiène']);

    $byName = familyPurchase($store, '+24177000001', ['product_category' => 'boisson']);
    $byKeyword = familyPurchase($store, '+24177000001', ['article' => 'COCA-COLA 33CL']);
    $unknown = familyPurchase($store, '+24177000001', ['product_category' => 'Savon liquide']);

    expect($byName->category_id)->toBe($boissons->id)
        ->and($byKeyword->category_id)->toBe($boissons->id)
        ->and($unknown->category_id)->toBeNull();

    $labels = FamilyMatcher::for($organization->id)->unclassified();
    expect($labels)->toHaveCount(1)->and($labels[0]['label'])->toBe('Savon liquide');

    // Classement par lot : la correspondance sert aux ventes existantes et aux prochaines.
    expect(FamilyMatcher::for($organization->id)->assign(['Savon liquide'], $hygiene->id))->toBe(1);
    expect($unknown->fresh()->category_id)->toBe($hygiene->id);
    expect(familyPurchase($store, '+24177000002', ['product_category' => 'SAVON LIQUIDE'])->category_id)->toBe($hygiene->id);
});

it('imports the article and free columns as generic properties', function () {
    [$organization, $user] = familiesSetup();
    Category::create(['organization_id' => $organization->id, 'name' => 'Chemises']);

    $csv = "telephone,boutique,date_achat,article,categorie,genre,marque,montant\n"
        ."+24177000001,Carré,2026-10-01,Chemise lin bleue,Chemises,HOMME,Zara,25000\n"
        ."+24177000002,Carré,2026-10-02,Robe fleurie,Robes,femme,,30000\n";

    $preview = $this->actingAs($user)->post('/purchases/preview', ['file' => UploadedFile::fake()->createWithContent('ventes.csv', $csv)]);
    $preview->assertInertia(fn ($page) => $page
        ->where('preview.classified', 1)
        ->where('preview.to_classify', 1)
        ->where('preview.properties', ['genre', 'marque']));

    $this->actingAs($user)->post('/purchases', ['file' => UploadedFile::fake()->createWithContent('ventes.csv', $csv)])->assertSessionHas('success');

    $shirt = Purchase::where('article', 'Chemise lin bleue')->sole();
    $dress = Purchase::where('article', 'Robe fleurie')->sole();

    expect($shirt->category?->name)->toBe('Chemises')
        ->and($shirt->properties)->toBe(['genre' => 'Homme', 'marque' => 'Zara'])
        ->and($dress->category_id)->toBeNull()
        ->and($dress->properties)->toBe(['genre' => 'Femme']);

    $this->actingAs($user)->get('/categories/a-classer')->assertInertia(fn ($page) => $page
        ->component('Categories/Unclassified')
        ->has('labels', 1)
        ->where('labels.0.label', 'Robes'));

    // « Une famille par libellé » : la famille Robes est créée et la vente rangée.
    $this->actingAs($user)->post('/categories/a-classer', ['labels' => ['Robes'], 'mode' => 'each'])->assertSessionHas('success');
    expect($dress->fresh()->category?->name)->toBe('Robes');
});

it('computes family figures, lapsed buyers and cross-selling affinities', function () {
    [$organization, $user, $store] = familiesSetup();
    $boissons = Category::create(['organization_id' => $organization->id, 'name' => 'Boissons']);
    $epicerie = Category::create(['organization_id' => $organization->id, 'name' => 'Épicerie']);

    // A : boissons deux fois (réachat) + épicerie ; B : boissons il y a 200 jours puis épicerie récemment.
    familyPurchase($store, '+24177000001', ['category_id' => $boissons->id, 'amount' => 2000, 'purchased_at' => today()->subDays(10)]);
    familyPurchase($store, '+24177000001', ['category_id' => $boissons->id, 'amount' => 3000, 'purchased_at' => today()->subDays(3)]);
    familyPurchase($store, '+24177000001', ['category_id' => $epicerie->id, 'amount' => 5000, 'purchased_at' => today()->subDays(3)]);
    familyPurchase($store, '+24177000002', ['category_id' => $boissons->id, 'amount' => 1000, 'purchased_at' => today()->subDays(200)]);
    familyPurchase($store, '+24177000002', ['category_id' => $epicerie->id, 'amount' => 4000, 'purchased_at' => today()->subDays(5)]);

    $analytics = new FamilyAnalytics([$store->id]);
    $overview = $analytics->overview(collect([$boissons, $epicerie]), today()->subMonths(12), today(), 90);
    $row = collect($overview['rows'])->firstWhere('id', $boissons->id);

    expect($row['customers'])->toBe(2)
        ->and($row['amount'])->toBe(6000)
        ->and($row['average_basket'])->toBe(2000)
        ->and($row['repeat_rate'])->toBe(50)
        ->and($row['lapsed'])->toBe(1);

    $affinity = $analytics->affinities($boissons, collect([$boissons, $epicerie]), today()->subMonths(12), today());
    expect($affinity[0]['name'])->toBe('Épicerie')->and($affinity[0]['share'])->toBe(100);

    $this->actingAs($user)->get('/categories')->assertInertia(fn ($page) => $page
        ->component('Categories/Index')
        ->where('overview.total', 15000)
        ->where('familyWord', 'famille'));
    $this->actingAs($user)->get("/categories/{$boissons->id}")->assertInertia(fn ($page) => $page
        ->component('Categories/Show')
        ->where('stats.lapsed', 1)
        ->where('affinities.0.name', 'Épicerie'));
});

it('targets customers by family, lapsed family, never bought family and property', function () {
    [$organization, $user, $store] = familiesSetup();
    $boissons = Category::create(['organization_id' => $organization->id, 'name' => 'Boissons']);
    $epicerie = Category::create(['organization_id' => $organization->id, 'name' => 'Épicerie']);

    familyPurchase($store, '+24177000001', ['category_id' => $boissons->id, 'purchased_at' => today()->subDays(3), 'properties' => ['genre' => 'Femme']]);
    familyPurchase($store, '+24177000002', ['category_id' => $boissons->id, 'purchased_at' => today()->subDays(200)]);
    familyPurchase($store, '+24177000002', ['category_id' => $epicerie->id, 'purchased_at' => today()->subDays(2)]);

    $a = Customer::where('phone_e164', '+24177000001')->value('id');
    $b = Customer::where('phone_e164', '+24177000002')->value('id');
    $ids = fn (array $filters) => (new CustomerSearch($user, $filters))->query()->pluck('id')->all();

    expect($ids(['family' => $boissons->id, 'family_days' => 90]))->toBe([$a])
        ->and($ids(['family' => $boissons->id, 'family_mode' => 'lapsed', 'family_days' => 90]))->toBe([$b])
        ->and($ids(['family' => $boissons->id, 'family_days' => 0, 'family_not' => $epicerie->id]))->toBe([$a])
        ->and($ids(['prop' => 'genre:Femme']))->toBe([$a])
        ->and((new CustomerSearch($user, ['family' => $boissons->id, 'family_mode' => 'lapsed', 'family_days' => 90]))->label())->toBe('Plus de Boissons depuis 90 j');

    $this->actingAs($user)->get("/customers/{$a}")->assertInertia(fn ($page) => $page
        ->where('habits.visits', 1)
        ->where('habits.families.0.name', 'Boissons')
        ->where('habits.properties.0.value', 'Femme'));
});

it('hides the agenda for retail and shows the sector at registration', function () {
    // Inscription publique : 4 secteurs, dont les Boutiques (clé commerce).
    $this->get('/register')->assertInertia(fn ($page) => $page->has('sectors', count(Organization::PUBLIC_SECTORS))
        ->where('sectors', fn ($sectors) => collect($sectors)->contains('label', 'Boutiques')));

    [$organization, $user] = familiesSetup();

    $this->actingAs($user)->get('/customers')->assertInertia(fn ($page) => $page
        ->where('auth.organization.has_agenda', false)
        ->where('auth.organization.sells_products', true));

    $this->actingAs($user)->put('/organisation', ['name' => $organization->name, 'sector' => 'beaute'])->assertSessionHas('success');
    expect($organization->fresh()->hasAgenda())->toBeTrue();
});

it('lists the families sold today in the daily report', function () {
    [$organization, , $store] = familiesSetup();
    $boissons = Category::create(['organization_id' => $organization->id, 'name' => 'Boissons']);
    familyPurchase($store, '+24177000001', ['category_id' => $boissons->id, 'amount' => 2500]);

    $report = (new DailyReport($organization, today()))->build();

    expect($report['families'][0])->toBe(['name' => 'Boissons', 'customers' => 1, 'revenue' => 2500]);
});

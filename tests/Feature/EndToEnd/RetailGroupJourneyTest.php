<?php

use App\Models\Brand;
use App\Models\Customer;
use App\Models\Group;
use App\Models\ImportBatch;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Loyalty;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
 * Parcours complet d'une entreprise Menentia Retail (commerce multimarque) :
 * inscription, marques et points de vente, fidélité, import de caisse,
 * fiche client, segments, export, relance par groupe, responsable de marque.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(Carbon::parse('2026-10-20 10:00'));
});

it('runs the Retail Group journey from sign-up to a brand manager', function () {
    // 1. Inscription, secteur commerce : pas d'agenda.
    $this->post('/register', [
        'organization_name' => 'Retail Group',
        'sector' => 'commerce',
        'store_name' => 'Adidas Glass',
        'name' => 'Direction Retail',
        'email' => 'dg@retail-group.test',
        'password' => 'Secret-123456',
        'password_confirmation' => 'Secret-123456',
    ])->assertRedirect(route('dashboard.index'));

    $admin = User::where('email', 'dg@retail-group.test')->sole();
    $organization = $admin->organization;
    expect($organization->sector)->toBe('commerce')
        ->and($organization->hasAgenda())->toBeFalse();

    $this->actingAs($admin)->get('/appointments')->assertRedirect(route('dashboard.index'));
    $this->actingAs($admin)->get('/settings/staff')->assertRedirect(route('dashboard.index'));

    // 2. Deuxième marque et son point de vente.
    $adidas = Store::where('organization_id', $organization->id)->sole()->brand;
    $this->actingAs($admin)->post('/brands', ['name' => 'Mango'])->assertSessionHas('success');
    $mango = Brand::where('organization_id', $organization->id)->where('name', 'Mango')->sole();
    $this->actingAs($admin)->post('/stores', ['brand_id' => $mango->id, 'name' => 'Mango Centre-ville', 'code' => 'MG01'])->assertSessionHas('success');

    // 3. Fidélité lancée avant l'import : 1 point par 1 000 FCFA.
    $this->actingAs($admin)->put('/settings/loyalty', [
        'enabled' => true, 'fcfa_per_point' => 1000, 'starts_on' => '2026-01-01',
        'tiers' => [['name' => 'Bronze', 'min_points' => 0], ['name' => 'Or', 'min_points' => 100]],
    ])->assertSessionHasNoErrors();

    // 4. Import du fichier de caisse : dépôt, association des colonnes, confirmation.
    $csv = implode("\n", [
        'Date;Magasin;Ticket;Téléphone;Nom;Famille;Montant',
        '01/09/2026;Adidas Glass;A-1;077 11 22 33;Awa Ndong;Sneakers;60000',
        '15/09/2026;Adidas Glass;A-2;077 11 22 33;Awa Ndong;Textile;40000',
        '02/10/2026;MG01;M-1;077112233;Awa Ndong;Robes;50000',
        '05/10/2026;Adidas Glass;A-3;066 44 55 66;Ben Obame;Sneakers;30000',
        '06/10/2026;MG01;M-2;;;Robes;20000',
    ])."\n";
    $this->actingAs($admin)->post('/sales-imports', ['file' => UploadedFile::fake()->createWithContent('caisse.csv', $csv)])->assertRedirect();
    $batch = ImportBatch::latest('id')->sole();
    $this->actingAs($admin)->post("/sales-imports/{$batch->id}/mapping", ['mapping' => $batch->mapping])->assertRedirect();
    expect($batch->refresh()->accepted_rows)->toBe(5)->and($batch->rejected_rows)->toBe(0);
    $this->actingAs($admin)->post("/sales-imports/{$batch->id}/confirm")->assertRedirect();

    expect($batch->refresh()->tickets_count)->toBe(5)
        ->and($batch->anonymous_tickets)->toBe(1)
        ->and(Purchase::count())->toBe(5);

    // 5. Fiche client unique pour les deux marques, sans prise de rendez-vous.
    $awa = Customer::where('organization_id', $organization->id)->where('phone_e164', '24177112233')->sole();
    $ben = Customer::where('organization_id', $organization->id)->where('phone_e164', '24166445566')->sole();
    expect($awa->fresh()->brands_count)->toBe(2)
        ->and($awa->total_spent)->toBe(150000)
        ->and(Loyalty::for($organization->fresh())->balance($awa))->toBe(150);

    $this->actingAs($admin)->get("/customers/{$awa->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Customers/Show')->where('appointmentStores', []));

    // 6. Segments : les multimarques, et la vue Multimarque par marque.
    $this->actingAs($admin)->get('/customers?brands=multi')->assertOk()
        ->assertInertia(fn ($page) => $page->has('customers.data', 1)->where('customers.data.0.id', $awa->id));

    $this->actingAs($admin)->get('/multibrand')->assertOk()
        ->assertInertia(fn ($page) => $page->where('kpis.multi', 1)
            ->where('perBrand', fn ($rows) => collect($rows)->firstWhere('id', $adidas->id)['returning'] === 1));

    // 7. Export des clients (journalisé) et relance par groupe.
    $export = $this->actingAs($admin)->get('/customers/export')->assertOk()->streamedContent();
    expect($export)->toContain('Awa Ndong')->toContain('Ben Obame');

    $this->actingAs($admin)->post('/audiences/group', ['name' => 'VIP multimarques', 'filters' => ['brands' => 'multi']])->assertRedirect();
    $group = Group::where('organization_id', $organization->id)->where('name', 'VIP multimarques')->sole();
    $this->actingAs($admin)->get("/actions?list=group&group={$group->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('rows', fn ($rows) => collect($rows)->pluck('name')->unique()->values()->all() === ['Awa Ndong']));

    // 8. Responsable de marque Mango : sa marque seulement.
    $role = Role::where('organization_id', $organization->id)->where('name', 'Responsable de marque')->sole();
    $this->actingAs($admin)->post('/users', [
        'name' => 'Resp. Mango', 'email' => 'mango@retail-group.test', 'password' => 'secret-123',
        'role_id' => $role->id, 'brand_id' => $mango->id,
    ])->assertSessionHas('success');
    $manager = User::where('email', 'mango@retail-group.test')->sole();

    $this->actingAs($manager)->get('/customers')->assertOk()
        ->assertInertia(fn ($page) => $page->has('customers.data', 1)->where('customers.data.0.id', $awa->id));
    $this->actingAs($manager)->get("/customers/{$ben->id}")->assertForbidden();
    $this->actingAs($manager)->get('/multibrand')->assertOk()
        ->assertInertia(fn ($page) => $page->where('perBrand', fn ($rows) => collect($rows)->pluck('name')->all() === ['Mango']));
    expect($this->actingAs($manager)->get('/customers/export')->assertOk()->streamedContent())
        ->toContain('Awa Ndong')->not->toContain('Ben Obame');
    $this->actingAs($manager)->get('/users')->assertForbidden();
});

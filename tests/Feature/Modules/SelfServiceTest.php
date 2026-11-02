<?php

use App\Models\Brand;
use App\Models\ClassEnrollment;
use App\Models\Customer;
use App\Models\GroupClass;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['sector' => 'sport', 'whatsapp_api_enabled' => false]);
    $this->organization->setModules(['seances_groupe', 'abonnements', 'tranches', 'beneficiaires', 'lieux', 'catalogue']);
    $this->organization->save();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
});

it('shows a getting-started list adapted to the modules, then lets it be hidden', function () {
    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.done', 0)
        ->where('onboarding.steps', fn ($steps) => collect($steps)->pluck('key')->values()->all() === ['modules', 'places', 'staff', 'catalog', 'classes', 'plans', 'customers', 'report', 'follow_up']));

    GroupClass::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Judo', 'weekday' => 6, 'start_time' => '10:30', 'end_time' => '11:30', 'starts_on' => '2026-10-01']);
    $this->actingAs($this->admin)->put('/settings/modules', ['enabled' => ['seances_groupe', 'abonnements', 'tranches', 'beneficiaires', 'lieux', 'catalogue']]);

    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.done', 2)
        ->where('onboarding.steps.4.done', true));

    $this->actingAs($this->admin)->post('/onboarding/dismiss');
    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('onboarding', null));
});

it('offers an Excel template for each import, with a help sheet', function (string $kind) {
    $response = $this->actingAs($this->admin)->get("/modeles/{$kind}")->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('.xlsx')
        ->and($response->streamedContent())->toStartWith('PK');
})->with(['clients', 'adherents', 'ventes']);

it('imports children with their parent and enrolls them in a class', function () {
    $class = GroupClass::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Judo 6-9 ans', 'weekday' => 6, 'start_time' => '10:30', 'end_time' => '11:30', 'starts_on' => '2026-10-01']);
    $csv = "Responsable;Téléphone du responsable;Personne suivie;Date de naissance;Lien;Cours\n"
        ."Mireille Mba;077 00 11 22;Léo Mba;01/03/2017;parent;Judo 6-9 ans\n"
        ."Mireille Mba;077 00 11 22;Inès Mba;12/06/2015;parent;\n"
        ."Paul Obame;;Jean Obame;;;\n"
        ."Mireille Mba;077 00 11 22;Léo Mba;01/03/2017;parent;Natation\n";

    $this->actingAs($this->admin)->post('/imports/beneficiaries', [
        'store_id' => $this->store->id,
        'file' => UploadedFile::fake()->createWithContent('adherents.csv', $csv),
    ])->assertSessionHas('error', fn ($m) => str_contains($m, '2 personne(s) suivie(s) ajoutée(s), 1 déjà connue(s), 1 inscription(s)') && str_contains($m, 'Ligne 4') && str_contains($m, 'Natation'));

    $parent = Customer::where('name', 'Mireille Mba')->firstOrFail();
    expect($parent->beneficiaries()->pluck('name')->sort()->values()->all())->toBe(['Inès Mba', 'Léo Mba'])
        ->and(Customer::where('name', 'Léo Mba')->first()->birthdate->toDateString())->toBe('2017-03-01')
        ->and(ClassEnrollment::where('group_class_id', $class->id)->count())->toBe(1);
});

it('shares a help link with signed-in users', function () {
    config(['services.menentia.support_whatsapp' => '+241 77 00 00 00']);

    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('support', fn ($url) => str_starts_with($url, 'https://wa.me/24177000000?text=')));
});

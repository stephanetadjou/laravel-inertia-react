<?php

use App\Mail\MonthlyReportMail;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\LoyaltyProgram;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerScoring;
use App\Services\LoyaltyEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-04 10:00'));
    $this->organization = Organization::factory()->create(['sector' => 'commerce']);
    Role::seedDefaultsFor($this->organization);
    $role = fn (string $name) => Role::where('organization_id', $this->organization->id)->where('name', $name)->value('id');
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $role('Admin groupe')]);
    $this->operator = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $role('Opérateur d’import')]);
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Adidas']);
    $other = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Mango']);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id, 'name' => 'Adidas Glass']);
    $this->otherStore = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $other->id, 'name' => 'Mango Akanda']);
    $this->manager = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $role('Responsable de marque'), 'brand_id' => $other->id]);

    $program = LoyaltyProgram::create(['organization_id' => $this->organization->id, 'name' => 'Fidélité 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $program->rules()->create(['brand_id' => $brand->id, 'threshold' => 400000, 'tranche_amount' => 10000, 'points_per_tranche' => 1, 'activation_bonus' => 50, 'tiers' => [['points' => 300, 'percent' => 10]], 'validity_months' => 12, 'reset_after_use' => true]);

    $this->contact = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24162000001', 'name' => 'Awa Ndong']);
    Purchase::create(['contact_id' => $this->contact->id, 'store_id' => $this->store->id, 'amount' => 500000, 'purchased_at' => '2026-10-05']);
    app(CustomerScoring::class)->refreshOrganization($this->organization);
    app(LoyaltyEngine::class)->recalculate($this->organization);
});

it('downloads every report as a PDF and audits it', function (string $type) {
    $response = $this->actingAs($this->admin)->get("/rapports/{$type}?month=2026-10&store={$this->store->id}")->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and(substr($response->baseResponse->getContent(), 0, 4))->toBe('%PDF')
        ->and(AuditLog::where('action', 'export')->exists())->toBeTrue();
})->with(['direction', 'fidelite', 'magasin', 'relance']);

it('exports the customer page as a PDF', function () {
    $response = $this->actingAs($this->admin)->get("/customers/{$this->contact->customer_id}/fiche.pdf")->assertOk();

    expect(substr($response->baseResponse->getContent(), 0, 4))->toBe('%PDF');
});

it('needs a store for the store report, inside the scope of the role', function () {
    $this->actingAs($this->admin)->get('/rapports/magasin?month=2026-10')->assertSessionHasErrors('store');
    $this->actingAs($this->manager)->get("/rapports/magasin?month=2026-10&store={$this->store->id}")->assertSessionHasErrors('store');
    $this->actingAs($this->manager)->get("/rapports/magasin?month=2026-10&store={$this->otherStore->id}")->assertOk();
    $this->actingAs($this->manager)->get("/customers/{$this->contact->customer_id}/fiche.pdf")->assertNotFound();
});

it('refuses roles without the export right', function () {
    $this->actingAs($this->operator)->get('/rapports/direction?month=2026-10')->assertForbidden();
});

it('shows the reports page', function () {
    $this->actingAs($this->admin)->get('/rapports')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reports/Index')->has('types', 4));
});

it('sends the monthly reports only when asked', function () {
    Mail::fake();

    $this->artisan('crm:monthly-report')->assertSuccessful();
    Mail::assertNothingSent();

    $this->organization->update(['report_settings' => ['monthly_enabled' => true, 'recipients' => ['direction@example.test']]]);
    $this->artisan('crm:monthly-report')->assertSuccessful();

    Mail::assertSent(MonthlyReportMail::class, fn ($mail) => count($mail->files) === 2 && $mail->hasTo('direction@example.test') && $mail->period === 'octobre 2026');
});

it('saves the monthly option in the report settings', function () {
    $this->actingAs($this->admin)->put('/settings/reports', ['enabled' => false, 'monthly_enabled' => true, 'recipients' => ['a@example.test'], 'send_at' => '21:00'])->assertSessionHasNoErrors();

    expect($this->organization->fresh()->reportSettings()['monthly_enabled'])->toBeTrue();
});

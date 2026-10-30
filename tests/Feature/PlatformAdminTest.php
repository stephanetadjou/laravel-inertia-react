<?php

use App\Models\Lead;
use App\Models\Organization;
use App\Models\PlatformLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-30 10:00'));
    $this->admin = User::factory()->create(['email' => 'equipe@menentia.test']);
    $this->admin->forceFill(['is_platform_admin' => true])->save();

    $this->salon = Organization::factory()->create([
        'name' => 'Salon Awa', 'sector' => 'beaute', 'status' => 'trial', 'plan' => 'solo', 'trial_ends_at' => now()->addDays(3),
    ]);
    $this->retail = Organization::factory()->create(['name' => 'Retail Group', 'sector' => 'commerce', 'status' => 'active', 'plan' => 'premium']);
    $this->member = User::factory()->create(['organization_id' => $this->salon->id]);
});

it('keeps the platform back office out of reach of company users', function () {
    $this->get('/admin/dashboard')->assertRedirect('/login');

    foreach (['/admin/dashboard', '/admin/organizations', "/admin/organizations/{$this->salon->id}", '/admin/leads', '/admin/leads/export'] as $url) {
        $this->actingAs($this->member)->get($url)->assertForbidden();
    }
    $this->actingAs($this->member)->post("/admin/organizations/{$this->salon->id}/suspend", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($this->member)->put("/admin/organizations/{$this->salon->id}/plan", ['plan' => 'premium'])->assertForbidden();

    // L'attribut ne peut pas être donné par un formulaire d'entreprise.
    expect((new User)->isFillable('is_platform_admin'))->toBeFalse();
});

it('grants and revokes access with the artisan command', function () {
    $this->artisan('menentia:make-admin', ['email' => $this->member->email])->assertSuccessful();
    expect($this->member->fresh()->isPlatformAdmin())->toBeTrue();

    $this->artisan('menentia:make-admin', ['email' => $this->member->email, '--revoke' => true])->assertSuccessful();
    expect($this->member->fresh()->isPlatformAdmin())->toBeFalse();

    $this->artisan('menentia:make-admin', ['email' => 'inconnu@example.com'])->assertFailed();
});

it('starts a new sign-up with a 14-day trial', function () {
    $this->post('/register', [
        'organization_name' => 'Nouvelle entreprise', 'sector' => 'sante', 'store_name' => 'Cabinet',
        'name' => 'Dr Ndong', 'email' => 'dr@example.com', 'password' => 'Secret-123456', 'password_confirmation' => 'Secret-123456',
    ])->assertRedirect();

    $organization = Organization::where('name', 'Nouvelle entreprise')->sole();
    expect($organization->status)->toBe('trial')
        ->and($organization->plan)->toBe('solo')
        ->and($organization->trial_ends_at->toDateString())->toBe('2026-11-13');
});

it('shows the dashboard: statuses, sectors, sign-ups and trials ending this week', function () {
    Organization::factory()->create(['status' => 'trial', 'trial_ends_at' => now()->subDay()]);
    Lead::create(['name' => 'A', 'company' => 'B', 'whatsapp_raw' => '077', 'consent_at' => now()]);

    $this->actingAs($this->admin)->get('/admin')->assertRedirect('/admin/dashboard');
    $this->actingAs($this->admin)->get('/admin/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Dashboard')
            ->where('total', 4)
            ->where('trialExpired', 1)
            ->where('newLeads', 1)
            ->where('statuses', fn ($s) => collect($s)->firstWhere('key', 'active')['total'] === 2)
            ->where('sectors', fn ($s) => collect($s)->firstWhere('sector', 'commerce')['total'] === 1)
            ->where('signupsTotal', 4)
            ->has('signups', 30)
            ->where('trialsEnding', fn ($rows) => collect($rows)->pluck('name')->all() === ['Salon Awa']));
});

it('lists companies with search and filters', function () {
    $this->actingAs($this->admin)->get('/admin/organizations')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Organizations/Index')->where('organizations.total', 3));

    $this->actingAs($this->admin)->get('/admin/organizations?offer=retail')
        ->assertInertia(fn ($page) => $page->has('organizations.data', 1)->where('organizations.data.0.name', 'Retail Group')->where('organizations.data.0.offer', 'Menentia Retail'));
    $this->actingAs($this->admin)->get('/admin/organizations?status=trial&sector=beaute&q=awa')
        ->assertInertia(fn ($page) => $page->has('organizations.data', 1)
            ->where('organizations.data.0.name', 'Salon Awa')
            ->where('organizations.data.0.users_count', 1)
            ->where('organizations.data.0.plan_label', 'Solo'));

    $this->actingAs($this->admin)->get("/admin/organizations/{$this->salon->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Organizations/Show')->has('users', 1));
});

it('extends a trial, changes the plan and activates the subscription, with a log entry each', function () {
    $this->actingAs($this->admin)->post("/admin/organizations/{$this->salon->id}/trial", ['days' => 7])->assertSessionHas('success');
    expect($this->salon->fresh()->trial_ends_at->toDateString())->toBe('2026-11-09');

    $this->actingAs($this->admin)->put("/admin/organizations/{$this->salon->id}/plan", ['plan' => 'equipe'])->assertSessionHas('success');
    $this->actingAs($this->admin)->put("/admin/organizations/{$this->salon->id}/plan", ['plan' => 'gratuit'])->assertSessionHasErrors('plan');
    expect($this->salon->fresh()->plan)->toBe('equipe');

    $this->actingAs($this->admin)->post("/admin/organizations/{$this->salon->id}/activate")->assertSessionHas('success');
    expect($this->salon->fresh()->status)->toBe('active');

    // Abonnée : plus d'essai à prolonger.
    $this->actingAs($this->admin)->post("/admin/organizations/{$this->salon->id}/trial", ['days' => 7])->assertSessionHas('error');

    expect(PlatformLog::where('organization_id', $this->salon->id)->pluck('action')->all())
        ->toBe(['organization.trial_extended', 'organization.plan_changed', 'organization.activated'])
        ->and(PlatformLog::first()->user_id)->toBe($this->admin->id);
});

it('suspends a company: its users only see the suspended page, until reactivation', function () {
    $this->actingAs($this->admin)->post("/admin/organizations/{$this->salon->id}/suspend", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->admin)->post("/admin/organizations/{$this->salon->id}/suspend", ['reason' => 'Abonnement impayé'])->assertSessionHas('success');
    expect($this->salon->fresh()->status)->toBe('suspended');

    $this->actingAs($this->member)->get('/dashboard')->assertForbidden()
        ->assertInertia(fn ($page) => $page->component('Suspended')->where('organization', 'Salon Awa')->where('reason', 'Abonnement impayé'));
    $this->actingAs($this->member)->get('/customers')->assertForbidden();
    $this->actingAs($this->member)->post('/logout')->assertRedirect();

    // Une autre entreprise n'est pas touchée.
    $retailUser = User::factory()->create(['organization_id' => $this->retail->id]);
    $this->actingAs($retailUser)->get('/dashboard')->assertOk();

    // Retour à l'essai, qui court encore.
    $this->actingAs($this->admin)->post("/admin/organizations/{$this->salon->id}/reactivate")->assertSessionHas('success');
    expect($this->salon->fresh())->status->toBe('trial')->suspension_reason->toBeNull();
    // Nouvelle requête : utilisateur rechargé (comme à chaque requête réelle).
    $this->actingAs($this->member->fresh())->get('/dashboard')->assertOk();

    expect(PlatformLog::pluck('action')->all())->toBe(['organization.suspended', 'organization.reactivated']);
});

it('follows up demo requests and exports them to Excel', function () {
    $lead = Lead::create(['name' => 'Awa', 'company' => 'Salon Awa', 'whatsapp_raw' => '077 12 34 56', 'whatsapp' => '24177123456', 'city' => 'Libreville', 'consent_at' => now()]);
    Lead::create(['name' => 'Ben', 'company' => 'Garage Ben', 'whatsapp_raw' => '066', 'consent_at' => now()]);

    $this->actingAs($this->admin)->get('/admin/leads?q=salon')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Leads')->has('leads.data', 1)->where('counts.new', 2));

    $this->actingAs($this->admin)->put("/admin/leads/{$lead->id}", ['status' => 'contacted', 'note' => 'Rappeler lundi'])->assertSessionHas('success');
    $this->actingAs($this->admin)->put("/admin/leads/{$lead->id}", ['status' => 'perdu'])->assertSessionHasErrors('status');
    expect($lead->fresh())->status->toBe('contacted')->note->toBe('Rappeler lundi')->contacted_at->not->toBeNull();

    $this->actingAs($this->admin)->get('/admin/leads?status=contacted')
        ->assertInertia(fn ($page) => $page->has('leads.data', 1)->where('leads.data.0.company', 'Salon Awa'));

    $response = $this->actingAs($this->admin)->get('/admin/leads/export')->assertOk();
    expect($response->headers->get('content-type'))->toContain('spreadsheetml');
    $file = tempnam(sys_get_temp_dir(), 'leads').'.xlsx';
    file_put_contents($file, $response->streamedContent());
    $rows = IOFactory::load($file)->getActiveSheet()->toArray();
    expect($rows)->toHaveCount(3)
        ->and($rows[0][2])->toBe('Entreprise')
        ->and(collect($rows)->pluck(2)->all())->toContain('Salon Awa', 'Garage Ben');

    expect(PlatformLog::pluck('action')->all())->toBe(['lead.updated', 'leads.exported']);
});

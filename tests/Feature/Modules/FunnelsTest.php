<?php

use App\Models\Brand;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use App\Modules\Location\Models\VehicleRequest;
use App\Modules\Tunnels\Models\Funnel;
use App\Modules\Tunnels\Models\FunnelLead;
use App\Modules\Tunnels\Services\Funnels;
use App\Support\SectorPresets;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-16 10:00'));
    $this->organization = Organization::factory()->create(['sector' => 'location', 'whatsapp_api_enabled' => false, 'plan' => 'solo']);
    SectorPresets::apply($this->organization, true);
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id, 'booking_phone' => '24166001122']);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
});

it('creates funnels from templates within the pack limit', function () {
    expect($this->organization->hasModule('tunnels'))->toBeTrue()->and(Funnels::limit($this->organization))->toBe(1);
    $this->actingAs($this->admin)->get('/funnels')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Funnels/Index')->where('templates.0.key', 'mariage'));

    $this->post('/funnels', ['template' => 'mariage'])->assertSessionHasNoErrors();
    $this->post('/funnels', ['template' => 'week_end'])->assertSessionHasNoErrors();
    [$first, $second] = Funnel::orderBy('id')->get()->all();
    expect($first->is_active)->toBeTrue()->and($second->is_active)->toBeFalse();
    $this->post("/funnels/{$second->id}/toggle")->assertSessionHas('error');

    $this->organization->update(['plan' => 'gratuit']);
    expect(Funnels::limit($this->organization->fresh()))->toBe(0);
    expect(array_keys(Funnels::TEMPLATES))->toContain('fetes', 'premiere_visite', 'essai_gratuit', 'diagnostic', 'visites', 'lunettes_3_fois', 'inscriptions');
});

it('takes a request on the public page, counts visits and sources, then redirects after the end date', function () {
    $funnel = Funnels::createFromTemplate($this->organization, 'mariage', ['seats' => 2]);
    $url = "/t/{$this->organization->slug}/{$funnel->slug}";

    $this->get("{$url}?src=facebook")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Funnels/Public')->where('source', 'facebook')->where('funnel.seats_left', 2));
    $this->get($url);
    expect($funnel->fresh()->visits)->toBe(1);

    $this->post($url, ['name' => 'Grâce', 'phone' => '12'])->assertSessionHasErrors('phone');
    $this->post($url, ['name' => 'Grâce', 'phone' => '077123456', 'source' => 'facebook', 'starts_at' => '2026-12-13 09:00', 'vehicles_count' => 4])->assertSessionHas('funnel_sent');

    $lead = FunnelLead::firstOrFail();
    expect($lead->source)->toBe('facebook')->and($lead->request->purpose)->toBe('event')->and($lead->request->notes)->toContain('Tunnel « Voitures de mariage »')
        ->and($lead->customer->acquisition_source)->toBe('tunnel')
        ->and(Task::where('kind', 'online_request')->exists())->toBeTrue()
        ->and(Funnels::stats($funnel->fresh())['by_source'])->toBe(['facebook' => 1]);

    $this->travelTo($funnel->ends_at->copy()->addMinute());
    $this->get($url)->assertRedirect("/rdv/{$this->organization->slug}");
});

it('runs the J0 / J+1 / J+3 sequence and stops when the client buys or says STOP', function () {
    $funnel = Funnels::createFromTemplate($this->organization, 'mariage');
    $url = "/t/{$this->organization->slug}/{$funnel->slug}";
    $this->post($url, ['name' => 'Paul', 'phone' => '077222333'])->assertSessionHasNoErrors();
    $this->post($url, ['name' => 'Aline', 'phone' => '077444555'])->assertSessionHasNoErrors();
    $labels = fn () => collect(Funnels::due($this->organization))->pluck('label', 'customer')->all();

    expect($labels())->toBe(['Paul' => 'Récapitulatif (J0)', 'Aline' => 'Récapitulatif (J0)']);
    FunnelLead::all()->each(fn ($l) => Funnels::markDone($l));
    expect($labels())->toBe([]);
    $this->travel(1)->days();
    expect(array_values($labels()))->toBe(['Rappel avec un avis (J+1)', 'Rappel avec un avis (J+1)']);

    // Paul réserve (demande gagnée), Aline répond STOP.
    $paul = FunnelLead::whereHas('customer', fn ($q) => $q->where('name', 'Paul'))->firstOrFail();
    VehicleRequest::whereKey($paul->vehicle_request_id)->update(['status' => 'won']);
    Contact::where('phone_e164', '24177444555')->update(['consent_status' => 'opt_out']);

    expect($labels())->toBe([])
        ->and($paul->fresh()->stop_reason)->toBe('converted')
        ->and(FunnelLead::whereHas('customer', fn ($q) => $q->where('name', 'Aline'))->value('stop_reason'))->toBe('stop')
        ->and(Funnels::stats($funnel->fresh())['sales'])->toBe(1);
});

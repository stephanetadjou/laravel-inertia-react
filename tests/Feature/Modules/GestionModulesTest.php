<?php

use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Modules\Caisse\Models\CashClosing;
use App\Modules\Depenses\Models\Expense;
use App\Modules\Facturation\Models\Invoice;
use App\Modules\Stock\Models\StockItem;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Architecture modulaire (registre, packs) et applications de gestion :
 * Devis et factures, Caisse du jour, Dépenses, lanceur d'applications.
 */

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 11)->setTime(10, 0));
    $this->organization = Organization::factory()->create(['sector' => 'technique', 'whatsapp_api_enabled' => false, 'plan' => 'equipe', 'status' => 'active']);
    $this->organization->setModules(['rdv', 'catalogue', 'factures', 'caisse', 'depenses']);
    $this->organization->save();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id, 'name' => 'Optique Lumière']);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->customer = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177001122', 'name' => 'Grâce Mba', 'consent_status' => 'opt_in'])->customer;
});

function newQuote($test, array $extra = []): Invoice
{
    $test->actingAs($test->admin)->post('/invoices', [
        'type' => 'quote', 'customer_id' => $test->customer->id, 'store_id' => $test->store->id, 'title' => 'Lunettes progressives',
        'discount_amount' => 5000,
        'lines' => [
            ['label' => 'Consultation de vue', 'quantity' => 1, 'unit_price' => 15000],
            ['label' => 'Verres progressifs', 'quantity' => 2, 'unit_price' => 60000],
        ],
        ...$extra,
    ])->assertSessionHasNoErrors();

    return Invoice::latest('id')->firstOrFail();
}

it('reads module manifests, packs and dependencies from app/Modules', function () {
    expect(ModuleRegistry::get('factures'))->toMatchArray(['pack' => 'equipe', 'app' => 'invoices'])
        ->and(ModuleRegistry::get('rappels_rdv')['depends'])->toBe(['rdv'])
        ->and(ModuleRegistry::withDependencies(['rappels_rdv']))->toContain('rdv');

    $this->organization->setModules(['rappels_rdv']);
    expect($this->organization->modules['enabled'])->toContain('rdv');
});

it('applies the pack: free offer has no automation, packs gate modules only when enforced', function () {
    $this->organization->update(['plan' => 'gratuit']);
    expect($this->organization->automationAllowed())->toBeFalse()
        ->and($this->organization->packAllows('factures'))->toBeFalse()
        ->and($this->organization->hasModule('factures'))->toBeTrue();

    config(['nentix.enforce_packs' => true]);
    expect($this->organization->hasModule('factures'))->toBeFalse()
        ->and($this->organization->hasModule('rdv'))->toBeTrue();

    $this->organization->update(['status' => 'trial']);
    expect($this->organization->pack())->toBe('equipe')->and($this->organization->hasModule('factures'))->toBeTrue();
});

it('creates a numbered quote with its total and a WhatsApp share link', function () {
    $quote = newQuote($this);

    expect($quote->number)->toBe('DEV-2026-0001')
        ->and($quote->subtotal)->toBe(135000)
        ->and($quote->total)->toBe(130000)
        ->and($quote->lines)->toHaveCount(2);

    $this->get("/invoices/{$quote->id}")->assertInertia(fn (Assert $page) => $page
        ->component('Invoices/Show')
        ->where('document.number', 'DEV-2026-0001')
        ->where('customerCard.name', 'Grâce Mba')
        ->where('document.share_url', fn ($url) => str_starts_with($url, 'https://wa.me/24177001122?text=') && str_contains(urldecode($url), "/d/{$quote->token}")));

    $this->post("/invoices/{$quote->id}/sent");
    expect($quote->fresh()->status)->toBe('sent');
});

it('lets the customer accept the quote online, then invoices a deposit and the balance', function () {
    $quote = newQuote($this);
    $this->post("/invoices/{$quote->id}/sent");
    auth()->logout();

    $this->get("/d/{$quote->token}")->assertOk()->assertSee('J’accepte le devis', false);
    $this->post("/d/{$quote->token}/accept", ['name' => 'Grâce Mba'])->assertRedirect();
    expect($quote->fresh())->status->toBe('accepted')->accepted_by_name->toBe('Grâce Mba');

    $this->actingAs($this->admin)->post("/invoices/{$quote->id}/convert", ['deposit_percent' => 30])->assertSessionHasNoErrors();
    $deposit = Invoice::where('kind', 'deposit')->firstOrFail();
    expect($deposit->number)->toBe('FAC-2026-0001')->and($deposit->total)->toBe(39000);

    // Paiement de l'acompte : une vente du client, visible dans la caisse du jour.
    $this->post("/invoices/{$deposit->id}/payments", ['amount' => 39000, 'method' => 'airtel_money'])->assertSessionHasNoErrors();
    expect($deposit->fresh()->status)->toBe('paid');
    $purchase = Purchase::where('source', 'invoice')->firstOrFail();
    expect($purchase->amount)->toBe(39000)->and($purchase->contact->customer_id)->toBe($this->customer->id);
    expect($this->customer->fresh()->purchases_count)->toBe(1);

    $this->post("/invoices/{$quote->id}/convert")->assertSessionHasNoErrors();
    $balance = Invoice::where('kind', 'balance')->firstOrFail();
    expect($balance->total)->toBe(91000);

    $this->post("/invoices/{$balance->id}/payments", ['amount' => 50000, 'method' => 'cash'])->assertSessionHasNoErrors();
    expect($balance->fresh())->status->toBe('partial')->paid_amount->toBe(50000);
    $this->post("/invoices/{$balance->id}/payments", ['amount' => 50000])->assertSessionHasErrors('amount');
    $this->post("/invoices/{$quote->id}/convert")->assertSessionHasErrors('invoice');
});

it('lists overdue invoices with a ready WhatsApp reminder', function () {
    $this->actingAs($this->admin)->post('/invoices', [
        'type' => 'invoice', 'customer_id' => $this->customer->id, 'store_id' => $this->store->id,
        'due_on' => '2026-11-01', 'issued_on' => '2026-10-20',
        'lines' => [['label' => 'Entretien', 'quantity' => 1, 'unit_price' => 20000]],
    ])->assertSessionHasNoErrors();

    $this->get('/invoices?type=invoice&status=overdue')->assertInertia(fn (Assert $page) => $page
        ->component('Invoices/Index')
        ->has('documents.data', 1)
        ->where('documents.data.0.status', 'overdue')
        ->where('documents.data.0.reminder_url', fn ($url) => str_contains(urldecode($url), 'pas encore réglée'))
        ->where('stats.overdue_count', 1));
});

it('keeps invoices of another organization private', function () {
    $quote = newQuote($this);
    $other = Organization::factory()->create();
    $other->setModules(['factures']);
    $other->save();
    $stranger = User::factory()->create(['organization_id' => $other->id, 'role_id' => defaultRole($other, 'Super administrateur')->id]);

    $this->actingAs($stranger)->get("/invoices/{$quote->id}")->assertNotFound();
});

it('closes the day: sales by payment method, cash expenses and the cash difference', function () {
    Purchase::factory()->create(['contact_id' => $this->customer->contacts()->first()->id, 'store_id' => $this->store->id, 'amount' => 20000, 'payment_method' => 'cash', 'purchased_at' => now()]);
    Purchase::factory()->create(['contact_id' => $this->customer->contacts()->first()->id, 'store_id' => $this->store->id, 'amount' => 15000, 'payment_method' => 'moov_money', 'purchased_at' => now()]);
    Expense::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'spent_on' => today(), 'amount' => 3000, 'category' => 'transport', 'label' => 'Taxi', 'method' => 'cash']);

    $this->actingAs($this->admin)->get('/cash')->assertInertia(fn (Assert $page) => $page
        ->component('Cash/Index')
        ->where('totals.in', 35000)
        ->where('totals.cash_expected', 17000)
        ->where('totals.customers', 1)
        ->has('byMethod', 2)
        ->where('customers.0.thanks_url', fn ($url) => str_starts_with($url, 'https://wa.me/24177001122')));

    $this->post('/cash/close', ['store_id' => $this->store->id, 'day' => '2026-11-11', 'opening_float' => 10000, 'cash_counted' => 26000])->assertSessionHasNoErrors();
    expect(CashClosing::firstOrFail())->cash_expected->toBe(27000)->difference->toBe(-1000);
    $this->post('/cash/close', ['store_id' => $this->store->id, 'day' => '2026-11-11', 'opening_float' => 0, 'cash_counted' => 0])->assertSessionHasErrors('cash_counted');
});

it('records expenses with a receipt and sums them by category', function () {
    Storage::fake();
    $this->actingAs($this->admin)->post('/expenses', [
        'store_id' => $this->store->id, 'spent_on' => '2026-11-05', 'amount' => 150000, 'category' => 'loyer', 'label' => 'Loyer novembre', 'method' => 'transfer',
        'receipt' => UploadedFile::fake()->image('recu.jpg'),
    ])->assertSessionHasNoErrors();
    $this->post('/expenses', ['store_id' => $this->store->id, 'spent_on' => '2026-11-06', 'amount' => 20000, 'category' => 'fournitures', 'label' => 'Montures'])->assertSessionHasNoErrors();

    $expense = Expense::where('category', 'loyer')->firstOrFail();
    $this->get("/expenses/{$expense->id}/receipt")->assertOk();

    $this->get('/expenses?month=2026-11')->assertInertia(fn (Assert $page) => $page
        ->component('Expenses/Index')
        ->where('stats.total', 170000)
        ->where('byCategory.0.key', 'loyer')
        ->has('expenses.data', 2));

    $this->delete("/expenses/{$expense->id}")->assertRedirect();
    expect(Expense::count())->toBe(1);
});

it('hides an application whose module is off', function () {
    $this->organization->setModules(['rdv']);
    $this->organization->save();

    $this->actingAs($this->admin)->get('/invoices')->assertRedirect('/dashboard');
    $this->get('/cash')->assertRedirect('/dashboard');
    $this->get('/expenses')->assertRedirect('/dashboard');
});

it('shows the app launcher with customer-centred badges and the automations of an app', function () {
    $this->actingAs($this->admin)->get('/apps')->assertInertia(fn (Assert $page) => $page
        ->component('Apps/Index')
        ->has('badges.invoices')
        ->has('badges.cash')
        ->where('auth.organization.pack', 'equipe'));

    $this->get('/apps/automations?app=agenda')->assertInertia(fn (Assert $page) => $page
        ->component('Apps/Automations')
        ->where('items.0.key', 'rappels_rdv'));
});

it('tracks the stock of products sold with a service: sale, cancel, receipt with expense, inventory', function () {
    $this->organization->setModules(['rdv', 'catalogue', 'produits', 'stock', 'depenses', 'factures']);
    $this->organization->save();
    $frame = CatalogItem::create(['organization_id' => $this->organization->id, 'type' => 'product', 'name' => 'Monture Ray', 'price' => 45000, 'active' => true]);

    $this->actingAs($this->admin)->post('/stock/track', ['catalog_item_id' => $frame->id, 'quantity' => 3, 'min_quantity' => 2])->assertSessionHasNoErrors();
    $item = StockItem::firstOrFail();
    expect($item->quantity)->toBe(3);

    // Vente par facture : la monture sort du stock.
    $this->post('/invoices', [
        'type' => 'invoice', 'customer_id' => $this->customer->id, 'store_id' => $this->store->id,
        'lines' => [['label' => 'Monture Ray', 'quantity' => 1, 'unit_price' => 45000, 'catalog_item_id' => $frame->id]],
    ])->assertSessionHasNoErrors();
    $invoice = Invoice::latest('id')->firstOrFail();
    $this->post("/invoices/{$invoice->id}/payments", ['amount' => 45000, 'method' => 'cash'])->assertSessionHasNoErrors();
    expect($item->fresh()->quantity)->toBe(2);

    // Vente annulée : retour en stock.
    Purchase::where('source', 'invoice')->firstOrFail()->update(['status' => Purchase::STATUS_CANCELLED]);
    expect($item->fresh()->quantity)->toBe(3);

    $this->post("/stock/{$item->id}/receive", ['quantity' => 5, 'cost_price' => 20000, 'supplier' => 'Optic Import'])->assertSessionHasNoErrors();
    expect($item->fresh()->quantity)->toBe(8)
        ->and(Expense::where('label', 'like', 'Réassort%')->value('amount'))->toBe(100000);

    $this->post("/stock/{$item->id}/count", ['counted' => 6])->assertSessionHasNoErrors();
    expect($item->fresh()->quantity)->toBe(6);

    $this->get('/stock')->assertInertia(fn (Assert $page) => $page->component('Stock/Index')->where('items.0.quantity', 6)->where('stats.products', 1));
    $this->get('/stock/movements')->assertInertia(fn (Assert $page) => $page->component('Stock/Movements')->has('movements.data', 5));
});

it('shows the day of customers, the customer pulse of an app and finds a customer with the quick search', function () {
    $quote = newQuote($this);
    $this->post("/invoices/{$quote->id}/sent")->assertRedirect();
    $this->travel(4)->days();

    $this->get('/apps')->assertInertia(fn (Assert $page) => $page
        ->component('Apps/Index')
        ->where('day.quotes.0.customer.name', 'Grâce Mba')
        ->where('day.quotes.0.days', 4));

    $this->getJson('/apps/pulse?app=invoices')->assertOk()
        ->assertJsonPath('items.0.text', '1 devis sans réponse depuis 3 jours');

    $this->getJson('/apps/search?q=grâ')->assertOk()->assertJsonPath('customers.0.id', $this->customer->id);
    $this->getJson('/apps/search?q=77001')->assertOk()->assertJsonPath('customers.0.id', $this->customer->id);

    $other = Organization::factory()->create();
    $stranger = User::factory()->create(['organization_id' => $other->id, 'role_id' => defaultRole($other, 'Super administrateur')->id]);
    $this->actingAs($stranger)->getJson('/apps/search?q=Grâce')->assertOk()->assertJsonCount(0, 'customers');
});

it('lists all applications and the modules that can be added', function () {
    $this->actingAs($this->admin)->get('/apps/toutes')->assertInertia(fn (Assert $page) => $page
        ->component('Apps/All')
        ->where('available', fn ($items) => collect($items)->pluck('key')->contains('stock') && ! collect($items)->pluck('key')->contains('factures')));
});

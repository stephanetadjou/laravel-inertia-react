<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\PaymentSchedule;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paiement en plusieurs fois : création d'un échéancier, encaissement d'une
 * tranche (une vente, comme à la caisse) et annulation des tranches restantes.
 */
class Installments
{
    /**
     * Découpe un montant en tranches mensuelles ; la dernière absorbe l'arrondi.
     *
     * @return list<array{amount: int, due_on: string, label: string|null}>
     */
    public static function split(int $total, int $count, Carbon $firstDue): array
    {
        $count = max(1, $count);
        $part = intdiv($total, $count);

        return array_map(fn (int $i) => [
            'amount' => $i === $count - 1 ? $total - $part * ($count - 1) : $part,
            'due_on' => $firstDue->copy()->addMonthsNoOverflow($i)->toDateString(),
            'label' => null,
        ], range(0, $count - 1));
    }

    /**
     * @param  array{customer_id: int, beneficiary_id?: int|null, store_id: int, label: string, catalog_item_id?: int|null, subscription_id?: int|null, note?: string|null, installments: list<array{amount: int, due_on: string, label?: string|null}>}  $data
     */
    public function createSchedule(User $user, array $data): PaymentSchedule
    {
        $lines = array_values(array_filter($data['installments'], fn ($line) => (int) $line['amount'] > 0));

        if ($lines === []) {
            throw ValidationException::withMessages(['installments' => 'Indiquez au moins une tranche.']);
        }

        return DB::transaction(function () use ($user, $data, $lines) {
            $schedule = PaymentSchedule::create([
                'organization_id' => $user->organization_id,
                'store_id' => $data['store_id'],
                'customer_id' => $data['customer_id'],
                'beneficiary_id' => $data['beneficiary_id'] ?? null,
                'catalog_item_id' => $data['catalog_item_id'] ?? null,
                'subscription_id' => $data['subscription_id'] ?? null,
                'label' => $data['label'],
                'total_amount' => array_sum(array_map(fn ($line) => (int) $line['amount'], $lines)),
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);

            usort($lines, fn ($a, $b) => strcmp($a['due_on'], $b['due_on']));

            foreach ($lines as $index => $line) {
                Installment::create([
                    'payment_schedule_id' => $schedule->id,
                    'organization_id' => $user->organization_id,
                    'number' => $index + 1,
                    'label' => filled($line['label'] ?? null) ? mb_substr(trim($line['label']), 0, 255) : null,
                    'amount' => (int) $line['amount'],
                    'due_on' => $line['due_on'],
                ]);
            }

            Audit::record('schedule.created', $schedule, "Échéancier « {$schedule->label} » : ".count($lines).' tranche(s), '.number_format((int) $schedule->total_amount, 0, ',', ' ').' FCFA', [], $user);

            return $schedule;
        });
    }

    /**
     * Encaisse une tranche : vente au nom de la personne concernée (personne
     * suivie, sinon payeur), dans l'établissement de l'échéancier.
     */
    public function pay(Installment $installment, User $user, ?string $paymentMethod = null, ?Carbon $paidAt = null, bool $refreshScores = true): Purchase
    {
        if ($installment->paid_at !== null) {
            throw ValidationException::withMessages(['installment' => 'Cette tranche est déjà payée.']);
        }

        $schedule = $installment->schedule()->with(['customer', 'beneficiary', 'catalogItem.category'])->firstOrFail();

        if ($schedule->status !== PaymentSchedule::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['installment' => 'Cet échéancier est annulé.']);
        }

        $paidAt ??= now();
        $count = $schedule->installments()->count();
        $name = $installment->label ?? "{$schedule->label} — tranche {$installment->number}/{$count}";

        $purchase = DB::transaction(function () use ($installment, $schedule, $user, $paymentMethod, $paidAt, $name) {
            $contact = $this->contactFor($schedule->subject(), $schedule->store_id);
            $item = $schedule->catalogItem;

            $purchase = Purchase::create([
                'contact_id' => $contact->id,
                'store_id' => $schedule->store_id,
                'amount' => (int) $installment->amount,
                'quantity' => 1,
                'subtotal' => (int) $installment->amount,
                'discount_amount' => 0,
                'currency' => $user->organization->currency ?? 'XAF',
                'category_id' => $item?->category_id,
                'product_category' => $item?->category?->name ?? $schedule->label,
                'purchased_at' => $paidAt,
                'payment_method' => $paymentMethod,
                'type' => Purchase::TYPE_SALE,
                'source' => 'installment',
                'note' => $schedule->beneficiary_id ? "Payé par {$schedule->customer->name}" : null,
            ]);

            PurchaseItem::create([
                'purchase_id' => $purchase->id,
                'catalog_item_id' => $item?->id,
                'type' => $item?->type ?? CatalogItem::TYPE_SERVICE,
                'name' => mb_substr($name, 0, 255),
                'quantity' => 1,
                'unit_price' => (int) $installment->amount,
                'amount' => (int) $installment->amount,
                'discount_amount' => 0,
                'category_id' => $item?->category_id,
                'product_category' => $item?->category?->name,
            ]);

            $installment->update(['paid_at' => $paidAt, 'payment_method' => $paymentMethod, 'purchase_id' => $purchase->id, 'paid_by' => $user->id]);

            return $purchase;
        });

        if ($refreshScores) {
            app(CustomerScoring::class)->refreshOrganization($user->organization);
            Audit::record('installment.paid', $installment, "Tranche encaissée : {$name}, ".number_format((int) $installment->amount, 0, ',', ' ').' FCFA', ['purchase_id' => $purchase->id], $user);
        }

        return $purchase;
    }

    /** Annule l'échéancier : les tranches payées restent des ventes, les autres disparaissent des relances. */
    public function cancel(PaymentSchedule $schedule, User $user): void
    {
        $schedule->update(['status' => PaymentSchedule::STATUS_CANCELLED]);
        Audit::record('schedule.cancelled', $schedule, "Échéancier « {$schedule->label} » annulé", [], $user);
    }

    /** Contact de la personne dans l'établissement (créé au besoin, sans numéro pour une personne suivie). */
    private function contactFor(Customer $customer, int $storeId): Contact
    {
        return $customer->contacts()->where('store_id', $storeId)->first()
            ?? Contact::create([
                'store_id' => $storeId,
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'phone_e164' => $customer->phone_e164,
            ]);
    }
}

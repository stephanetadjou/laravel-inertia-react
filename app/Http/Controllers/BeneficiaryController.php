<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Customer;
use App\Services\Audit;
use App\Services\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Personnes suivies (module « beneficiaires ») : enfants, élèves, patients ou
 * salariés suivis pour le compte d'un responsable. Chaque personne a sa
 * propre fiche client (historique, segments, fidélité) ; les messages
 * partent au responsable (cf. Customer::recipient()).
 */
class BeneficiaryController extends Controller
{
    /** Ajoute une personne suivie depuis la fiche de son responsable. */
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $user = $request->user();
        $storeIds = $this->visibleStoreIds($request, $customer);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'birthdate' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::in(['F', 'H'])],
            'relation' => ['required', Rule::in(array_keys(Customer::RELATIONS))],
            'phone' => ['nullable', 'string', 'max:30'],
            'health_note' => ['nullable', 'string', 'max:2000'],
            'store_id' => ['nullable', 'integer', Rule::in($storeIds)],
        ]);

        $phone = filled($data['phone'] ?? null) ? PhoneNormalizer::normalize($data['phone']) : null;
        if (filled($data['phone'] ?? null) && $phone === null) {
            throw ValidationException::withMessages(['phone' => 'Numéro de téléphone invalide.']);
        }
        if ($phone !== null && Customer::where('organization_id', $customer->organization_id)->where('phone_e164', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => 'Ce numéro appartient déjà à une fiche client : liez plutôt cette fiche au responsable.']);
        }

        // Point de vente : celui choisi, sinon celui du responsable.
        $storeId = $data['store_id'] ?? $customer->contacts()->whereIn('store_id', $storeIds)->latest('id')->value('store_id') ?? $storeIds[0] ?? null;
        abort_if($storeId === null, 403);

        $beneficiary = DB::transaction(function () use ($customer, $data, $phone, $storeId, $user) {
            $beneficiary = Customer::create([
                'organization_id' => $customer->organization_id,
                'phone_e164' => $phone,
                'name' => $data['name'],
                'birthdate' => $data['birthdate'] ?? null,
                'gender' => $data['gender'] ?? null,
                'responsible_id' => $customer->id,
                'responsible_relation' => $data['relation'],
                'health_note' => $user->canManageOrganization() ? ($data['health_note'] ?? null) : null,
                'identity_confirmed_at' => now(),
            ]);

            Contact::create([
                'store_id' => $storeId,
                'customer_id' => $beneficiary->id,
                'name' => $data['name'],
                'phone_e164' => $phone,
                'birthdate' => $data['birthdate'] ?? null,
                'gender' => $data['gender'] ?? null,
            ]);

            return $beneficiary;
        });

        Audit::record('customer.beneficiary_added', $beneficiary, "{$beneficiary->name} suivi(e) par {$customer->name}");

        return back()->with('success', "{$beneficiary->name} est maintenant suivi(e) par {$customer->name}.");
    }

    /**
     * Rattache une fiche à un responsable (ou retire le rattachement). Un
     * mineur garde un responsable si l'entreprise l'exige.
     */
    public function updateResponsible(Request $request, Customer $customer): RedirectResponse
    {
        $this->visibleStoreIds($request, $customer);
        $organization = $request->user()->organization;

        $data = $request->validate([
            'responsible_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('organization_id', $customer->organization_id)],
            'relation' => ['nullable', 'required_with:responsible_id', Rule::in(array_keys(Customer::RELATIONS))],
        ]);

        $responsibleId = $data['responsible_id'] ?? null;

        if ($responsibleId === $customer->id || ($responsibleId !== null && Customer::whereKey($responsibleId)->value('responsible_id') === $customer->id)) {
            throw ValidationException::withMessages(['responsible_id' => 'Une personne ne peut pas être son propre responsable.']);
        }
        if ($responsibleId === null && $customer->isMinor() && $organization->moduleSettings('beneficiaires')['minor_needs_responsible']) {
            throw ValidationException::withMessages(['responsible_id' => 'Un mineur doit avoir un responsable.']);
        }
        if ($responsibleId === null && $customer->phone_e164 === null) {
            throw ValidationException::withMessages(['responsible_id' => 'Sans numéro, cette personne a besoin d’un responsable à qui écrire.']);
        }

        $customer->update(['responsible_id' => $responsibleId, 'responsible_relation' => $responsibleId ? $data['relation'] : null]);
        Audit::record('customer.responsible_changed', $customer, $responsibleId ? 'Responsable : '.Customer::whereKey($responsibleId)->value('name') : 'Responsable retiré');

        return back()->with('success', 'Responsable enregistré.');
    }

    /** Note de santé : réservée aux responsables de l'entreprise et au coach de la personne. */
    public function updateHealthNote(Request $request, Customer $customer): RedirectResponse
    {
        $this->visibleStoreIds($request, $customer);
        abort_unless($customer->healthNoteVisibleTo($request->user()), 403);

        $data = $request->validate(['health_note' => ['nullable', 'string', 'max:2000']]);
        $customer->update(['health_note' => $data['health_note'] ?? null]);
        Audit::record('customer.health_note', $customer, 'Note de santé modifiée');

        return back()->with('success', 'Note de santé enregistrée.');
    }

    /** @return array<int, int> */
    private function visibleStoreIds(Request $request, Customer $customer): array
    {
        $user = $request->user();

        if ($customer->organization_id !== $user->organization_id) {
            throw new HttpException(404);
        }

        $visible = $user->visibleStoreIds();
        $storeIds = $visible ?? $user->organization->stores()->pluck('id')->all();

        if ($visible !== null && ! $customer->contacts()->whereIn('store_id', $visible)->exists()) {
            throw new HttpException(404);
        }

        return array_values($storeIds);
    }

    /** Âge affiché (« 9 ans »). */
    public static function ageLabel(?Carbon $birthdate): ?string
    {
        return $birthdate ? ((int) $birthdate->diffInYears(today())).' ans' : null;
    }
}

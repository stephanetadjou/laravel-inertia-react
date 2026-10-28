<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\Closure;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Paramètres de l'agenda : intervenants (horaires, prestations, réservables
 * en ligne) et fermetures (jours fériés, congés, fermetures exceptionnelles).
 */
class ScheduleSettingsController extends Controller
{
    /** Couleurs proposées pour distinguer les intervenants dans l'agenda. */
    public const COLORS = ['#0E7BD6', '#00C896', '#7C3AED', '#F59E0B', '#EF4444', '#0EA5E9', '#14B8A6', '#EC4899'];

    public function staff(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        return Inertia::render('Settings/Staff', [
            'staff' => StaffMember::where('organization_id', $organizationId)
                ->with(['store:id,name', 'services:id', 'store'])
                ->orderBy('name')
                ->get()
                ->map(fn (StaffMember $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'store_id' => $s->store_id,
                    'store_name' => $s->store->name,
                    'user_id' => $s->user_id,
                    'color' => $s->color,
                    'own_hours' => $s->hours !== null,
                    'hours' => $s->workingHours(),
                    'service_ids' => $s->services->pluck('id')->values(),
                    'bookable_online' => $s->bookable_online,
                    'active' => $s->active,
                ]),
            'stores' => Store::where('organization_id', $organizationId)->orderBy('name')->get()
                ->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name, 'hours' => $s->bookingHours()]),
            'services' => CatalogItem::where('organization_id', $organizationId)
                ->where('type', CatalogItem::TYPE_SERVICE)
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'users' => User::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name']),
            'colors' => self::COLORS,
        ]);
    }

    public function storeStaff(Request $request): RedirectResponse
    {
        $data = $this->validatedStaff($request);

        DB::transaction(function () use ($request, $data) {
            $staff = StaffMember::create([...$this->staffAttributes($data), 'organization_id' => $request->user()->organization_id]);
            $staff->services()->sync($data['service_ids'] ?? []);
        });

        return back()->with('success', "Intervenant « {$data['name']} » ajouté.");
    }

    public function updateStaff(Request $request, StaffMember $staff): RedirectResponse
    {
        $this->authorizeOwn($request, $staff->organization_id);
        $data = $this->validatedStaff($request);

        DB::transaction(function () use ($staff, $data) {
            $staff->update($this->staffAttributes($data));
            $staff->services()->sync($data['service_ids'] ?? []);
        });

        return back()->with('success', "« {$staff->name} » mis à jour.");
    }

    /** Archiver / réactiver : un intervenant archivé n'est plus proposé, ses rendez-vous restent. */
    public function toggleStaff(Request $request, StaffMember $staff): RedirectResponse
    {
        $this->authorizeOwn($request, $staff->organization_id);
        $staff->update(['active' => ! $staff->active]);

        return back()->with('success', $staff->active ? "« {$staff->name} » réactivé." : "« {$staff->name} » archivé.");
    }

    public function closures(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        return Inertia::render('Settings/Closures', [
            'closures' => Closure::where('organization_id', $organizationId)
                ->whereDate('ends_on', '>=', today()->subDays(30)->toDateString())
                ->with('store:id,name')
                ->orderBy('starts_on')
                ->get()
                ->map(fn (Closure $c) => [
                    'id' => $c->id,
                    'label' => $c->label,
                    'starts_on' => $c->starts_on->toDateString(),
                    'ends_on' => $c->ends_on->toDateString(),
                    'store_id' => $c->store_id,
                    'store_name' => $c->store?->name,
                    'past' => $c->ends_on->lt(today()),
                ]),
            'stores' => Store::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name']),
            'year' => (int) today()->year,
        ]);
    }

    public function storeClosure(Request $request): RedirectResponse
    {
        $organizationId = $request->user()->organization_id;
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')->where('organization_id', $organizationId)],
        ], [
            'ends_on.after_or_equal' => 'La fin doit suivre le début.',
        ]);

        Closure::create([
            'organization_id' => $organizationId,
            'store_id' => $data['store_id'] ?? null,
            'label' => $data['label'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? $data['starts_on'],
        ]);

        return back()->with('success', "Fermeture « {$data['label']} » enregistrée.");
    }

    public function destroyClosure(Request $request, Closure $closure): RedirectResponse
    {
        $this->authorizeOwn($request, $closure->organization_id);
        $closure->delete();

        return back()->with('success', 'Fermeture supprimée.');
    }

    /**
     * Ajoute les jours fériés fixes du Gabon pour une année (sans doublon).
     * Les fêtes musulmanes (Aïd el-Fitr, Tabaski) changent chaque année :
     * elles sont à ajouter à la main.
     */
    public function holidays(Request $request): RedirectResponse
    {
        $organizationId = $request->user()->organization_id;
        $data = $request->validate(['year' => ['required', 'integer', 'min:2024', 'max:2100']]);
        $added = 0;

        foreach (self::gabonHolidays((int) $data['year']) as $date => $label) {
            $exists = Closure::where('organization_id', $organizationId)->whereNull('store_id')->whereDate('starts_on', $date)->exists();

            if (! $exists) {
                Closure::create(['organization_id' => $organizationId, 'store_id' => null, 'label' => $label, 'starts_on' => $date, 'ends_on' => $date]);
                $added++;
            }
        }

        return back()->with('success', "{$added} jour(s) férié(s) ajouté(s) pour {$data['year']}. Ajoutez à la main l’Aïd el-Fitr et la Tabaski.");
    }

    /** @return array<string, string> date AAAA-MM-JJ => libellé */
    public static function gabonHolidays(int $year): array
    {
        $easter = self::easter($year);

        $days = [
            "{$year}-01-01" => 'Jour de l’an',
            $easter->copy()->addDay()->toDateString() => 'Lundi de Pâques',
            "{$year}-04-17" => 'Journée des droits de la femme',
            "{$year}-05-01" => 'Fête du travail',
            $easter->copy()->addDays(50)->toDateString() => 'Lundi de Pentecôte',
            "{$year}-08-15" => 'Assomption',
            "{$year}-08-16" => 'Fête de l’indépendance',
            "{$year}-08-17" => 'Fête de l’indépendance',
            "{$year}-11-01" => 'Toussaint',
            "{$year}-12-25" => 'Noël',
        ];
        ksort($days);

        return $days;
    }

    /** Dimanche de Pâques (calendrier grégorien, algorithme de Meeus). */
    public static function easter(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day)->startOfDay();
    }

    /** @return array<string, mixed> */
    private function validatedStaff(Request $request): array
    {
        $organizationId = $request->user()->organization_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where('organization_id', $organizationId)],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $organizationId)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'own_hours' => ['required', 'boolean'],
            'hours' => ['nullable', 'array'],
            'hours.*' => ['nullable', 'array'],
            'hours.*.open' => ['required_with:hours.*', 'date_format:H:i'],
            'hours.*.close' => ['required_with:hours.*', 'date_format:H:i'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', Rule::exists('catalog_items', 'id')->where('organization_id', $organizationId)],
            'bookable_online' => ['required', 'boolean'],
        ]);

        foreach ($data['hours'] ?? [] as $day => $value) {
            if ($value !== null && $value['open'] >= $value['close']) {
                throw ValidationException::withMessages(["hours.{$day}" => 'L’heure de fin doit suivre l’heure de début.']);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function staffAttributes(array $data): array
    {
        $hours = null;

        if ($data['own_hours']) {
            $hours = [];
            foreach (range(1, 7) as $day) {
                $value = $data['hours'][$day] ?? null;
                $hours[$day] = $value ? ['open' => $value['open'], 'close' => $value['close']] : null;
            }
        }

        return [
            'name' => $data['name'],
            'store_id' => $data['store_id'],
            'user_id' => $data['user_id'] ?? null,
            'color' => $data['color'] ?? null,
            'hours' => $hours,
            'bookable_online' => $data['bookable_online'],
        ];
    }

    private function authorizeOwn(Request $request, int $organizationId): void
    {
        if ($organizationId !== $request->user()->organization_id) {
            throw new HttpException(403, "Cet élément n'appartient pas à votre entreprise.");
        }
    }
}

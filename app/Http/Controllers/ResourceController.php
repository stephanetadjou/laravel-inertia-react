<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\BookableResource;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Véhicules, salles et matériel (module « ressources ») réservés avec un
 * rendez-vous ; l'agenda refuse deux rendez-vous qui se chevauchent sur la
 * même ressource.
 */
class ResourceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $storeIds = PlanController::storeIds($user);

        $resources = BookableResource::where('organization_id', $user->organization_id)
            ->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $storeIds))
            ->with('store:id,name')
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        // Occupation des 7 derniers jours (heures de rendez-vous honorés ou prévus).
        $usage = Appointment::whereIn('resource_id', $resources->pluck('id'))
            ->whereIn('status', Appointment::BLOCKING_STATUSES)
            ->where('starts_at', '>=', now()->subDays(7))
            ->where('starts_at', '<', now()->addDays(7))
            ->get(['resource_id', 'starts_at', 'duration_minutes'])
            ->groupBy('resource_id');

        return Inertia::render('Resources/Index', [
            'resources' => $resources->map(fn (BookableResource $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'kind' => $r->kind,
                'kind_label' => BookableResource::KINDS[$r->kind] ?? $r->kind,
                'reference' => $r->reference,
                'store_id' => $r->store_id,
                'store' => $r->store?->name,
                'active' => $r->active,
                'past_hours' => round(collect($usage[$r->id] ?? [])->filter(fn ($a) => $a->starts_at->lt(now()))->sum('duration_minutes') / 60, 1),
                'next_hours' => round(collect($usage[$r->id] ?? [])->filter(fn ($a) => $a->starts_at->gte(now()))->sum('duration_minutes') / 60, 1),
            ]),
            'kinds' => BookableResource::KINDS,
            'stores' => Store::whereIn('id', $storeIds)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $resource = BookableResource::create([...$data, 'organization_id' => $request->user()->organization_id, 'active' => true]);

        return back()->with('success', "« {$resource->name} » ajouté.");
    }

    public function update(Request $request, BookableResource $resource): RedirectResponse
    {
        $this->authorizeOwn($request, $resource);
        $resource->update([...$this->validated($request), 'active' => $request->boolean('active', true)]);

        return back()->with('success', "« {$resource->name} » mis à jour.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(array_keys(BookableResource::KINDS))],
            'reference' => ['nullable', 'string', 'max:60'],
            'store_id' => ['nullable', 'integer', Rule::in(PlanController::storeIds($request->user()))],
        ]);
    }

    /**
     * Ressources proposées dans le formulaire de rendez-vous.
     *
     * @return list<array{id: int, name: string, store_id: int|null}>
     */
    public static function options(int $organizationId): array
    {
        return BookableResource::where('organization_id', $organizationId)->where('active', true)->orderBy('name')->get()
            ->map(fn (BookableResource $r) => ['id' => $r->id, 'name' => $r->label(), 'store_id' => $r->store_id])->all();
    }

    /**
     * Rendez-vous qui occupe déjà la ressource sur ce créneau (hors celui
     * qu'on modifie), null si elle est libre.
     */
    public static function conflict(int $resourceId, Carbon $start, int $minutes, ?int $exceptId = null): ?Appointment
    {
        $end = $start->copy()->addMinutes($minutes);

        // Chevauchement : début avant notre fin, fin après notre début (fin calculée en PHP : SQL portable).
        return Appointment::where('resource_id', $resourceId)
            ->whereIn('status', Appointment::BLOCKING_STATUSES)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->where('starts_at', '<', $end)
            ->where('starts_at', '>', $start->copy()->subHours(12))
            ->with('customer:id,name')
            ->get()
            ->first(fn (Appointment $a) => $a->starts_at->copy()->addMinutes($a->duration_minutes)->gt($start));
    }

    private function authorizeOwn(Request $request, BookableResource $resource): void
    {
        if ($resource->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Cette ressource n’appartient pas à votre entreprise.');
        }
    }
}

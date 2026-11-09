<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ProspectStageChange;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Suivi des prospects : étape commerciale d'une fiche avant son premier
 * achat. Les étapes de l'entreprise (Paramètres › Prospects) se rangent sur
 * 4 étapes internes fixes (à contacter, en discussion, en essai, gagné /
 * perdu) ; après le premier achat, le cycle de vie client prend le relais.
 *
 * Passages automatiques : une visite ou une démo honorée dans l'agenda fait
 * passer à l'étape « visite » / « demo » (si l'entreprise les a) ; la première
 * vente fait passer à l'étape gagnée.
 */
class ProspectPipeline
{
    public function __construct(private Organization $organization) {}

    public static function for(Organization $organization): self
    {
        return new self($organization);
    }

    public function enabled(): bool
    {
        return $this->organization->hasModule('prospects');
    }

    /** @return Collection<int, array{key: string, label: string, kind: string, delay_days: int}> */
    public function stages(): Collection
    {
        return collect($this->organization->moduleSettings('prospects')['stages'])
            ->filter(fn ($s) => is_array($s) && filled($s['key'] ?? null) && isset(Organization::PROSPECT_KINDS[$s['kind'] ?? '']))
            ->map(fn ($s) => ['key' => (string) $s['key'], 'label' => (string) ($s['label'] ?? $s['key']), 'kind' => (string) $s['kind'], 'delay_days' => (int) ($s['delay_days'] ?? 0)])
            ->values();
    }

    /** @return array{key: string, label: string, kind: string, delay_days: int}|null */
    public function stage(?string $key): ?array
    {
        return $key === null ? null : $this->stages()->firstWhere('key', $key);
    }

    public function firstStage(): array
    {
        return $this->stages()->first(fn ($s) => $s['kind'] === 'a_contacter') ?? $this->stages()->first();
    }

    public function wonStage(): ?array
    {
        return $this->stages()->firstWhere('kind', 'gagne');
    }

    /** Fait entrer une fiche dans le suivi (première étape), avec son commercial. */
    public function start(Customer $customer, ?User $owner = null, ?User $by = null): void
    {
        if ($owner !== null && $customer->owner_user_id === null) {
            $customer->owner_user_id = $owner->id;
        }

        $this->move($customer, $customer->purchases_count > 0 ? ($this->wonStage()['key'] ?? $this->firstStage()['key']) : $this->firstStage()['key'], $by);
    }

    /**
     * Change l'étape d'une fiche (motif obligatoire pour « perdu ») et trace
     * le passage, avec le commercial responsable à ce moment.
     */
    public function move(Customer $customer, string $key, ?User $by = null, ?string $lostReason = null): void
    {
        $stage = $this->stage($key) ?? throw ValidationException::withMessages(['stage' => 'Étape inconnue.']);

        if ($stage['kind'] === 'perdu' && blank($lostReason)) {
            throw ValidationException::withMessages(['lost_reason' => 'Indiquez pourquoi ce prospect est perdu.']);
        }

        if ($customer->prospect_stage === $key && $stage['kind'] !== 'perdu') {
            $customer->save();

            return;
        }

        $from = $customer->prospect_stage;
        $customer->forceFill([
            'prospect_stage' => $key,
            'prospect_stage_at' => now(),
            'lost_reason' => $stage['kind'] === 'perdu' ? trim((string) $lostReason) : null,
        ])->save();

        ProspectStageChange::create([
            'customer_id' => $customer->id,
            'user_id' => $by?->id,
            'owner_user_id' => $customer->owner_user_id,
            'from_stage' => $from,
            'to_stage' => $key,
            'to_kind' => $stage['kind'],
            'changed_at' => now(),
        ]);
    }

    /** Première vente : le prospect devient client (étape gagnée). */
    public function onFirstSale(Customer $customer): void
    {
        $won = $this->wonStage();
        $current = $this->stage($customer->prospect_stage);

        if ($won !== null && $current !== null && $current['kind'] !== 'gagne') {
            $this->move($customer, $won['key']);
        }
    }

    /**
     * Étapes atteintes par l'agenda, selon les préréglages des secteurs
     * (cf. App\Support\SectorPresets) : rendez-vous réservé, confirmé, venu ;
     * séance d'essai planifiée, faite. Une étape absente chez l'entreprise
     * est ignorée.
     */
    public const AGENDA_STAGES = [
        'booked' => ['essai' => 'essai_planifie', 'rdv' => 'rdv_reserve'],
        'confirmed' => ['essai' => 'essai_planifie', 'rdv' => 'rdv_confirme'],
        'done' => ['visite' => 'visite', 'demo' => 'demo', 'essai' => 'essai_fait', 'rdv' => 'venue'],
    ];

    /** Rendez-vous pris, confirmé ou honoré : passage à l'étape correspondante, sans jamais reculer. */
    public function onAppointmentEvent(Appointment $appointment, string $event): void
    {
        $this->advance($appointment, self::AGENDA_STAGES[$event][$appointment->kind ?? 'rdv'] ?? null);
    }

    /** Visite ou démo honorée : passage à l'étape correspondante, sans jamais reculer. */
    public function onAppointmentDone(Appointment $appointment): void
    {
        $this->onAppointmentEvent($appointment, 'done');
    }

    private function advance(Appointment $appointment, ?string $stageKey): void
    {
        $customer = $appointment->customer;
        $target = $this->stage($stageKey);

        if ($customer === null || $target === null) {
            return;
        }

        // Préréglage de secteur : un premier rendez-vous fait entrer la fiche (sans achat) dans le suivi.
        if ($customer->prospect_stage === null && $this->organization->sector_preset_applied_at !== null && (int) $customer->purchases_count === 0) {
            $this->start($customer);
        }

        if ($customer->prospect_stage === null) {
            return;
        }

        $order = $this->stages()->pluck('key')->flip();
        $current = $this->stage($customer->prospect_stage);
        if ($current !== null && in_array($current['kind'], ['gagne', 'perdu'], true)) {
            return;
        }
        if (($order[$target['key']] ?? 0) > ($order[$customer->prospect_stage] ?? -1)) {
            $this->move($customer, $target['key'], $appointment->staff ?? null);
        }
    }

    /** Jours depuis l'entrée dans l'étape ; en retard si le délai de relance est dépassé. */
    public function lateness(Customer $customer): array
    {
        $stage = $this->stage($customer->prospect_stage);
        $days = $customer->prospect_stage_at ? (int) $customer->prospect_stage_at->copy()->startOfDay()->diffInDays(today()) : 0;
        $delay = (int) ($stage['delay_days'] ?? 0);

        return ['days' => $days, 'late' => $delay > 0 && $days >= $delay && ! in_array($stage['kind'] ?? '', ['gagne', 'perdu'], true)];
    }
}

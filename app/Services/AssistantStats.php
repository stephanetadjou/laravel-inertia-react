<?php

namespace App\Services;

use App\Models\AiUsage;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\KnowledgeQuestion;
use App\Models\Organization;
use App\Models\Task;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppOutboundMessage;
use Illuminate\Support\Carbon;

/**
 * Tableau de suivi de l'Assistant IA (lecture seule) : messages reçus,
 * réponses données seul, passages à l'équipe, RDV et demandes obtenus, sur
 * la période et la période précédente de même durée.
 */
class AssistantStats
{
    public const BOOKED_NOTE = 'Pris sur WhatsApp par l’assistant ClientlyX';

    /**
     * @return array{days: int, current: array<string, int>, previous: array<string, int>, daily: list<array{day: string, received: int, answered: int, handoffs: int}>, voice_minutes: int, ai_questions: int}
     */
    public static function for(Organization $organization, int $days = 30): array
    {
        $end = now();
        $start = $end->copy()->subDays($days)->startOfDay();
        $previousStart = $start->copy()->subDays($days);

        return [
            'days' => $days,
            'current' => self::period($organization, $start, $end),
            'previous' => self::period($organization, $previousStart, $start),
            'daily' => self::daily($organization, 14),
            'hourly' => self::hourly($organization, $start, $end),
            'live' => self::live($organization),
            'voice_minutes' => AiUsage::voiceMinutesThisMonth($organization),
            'ai_questions' => AiUsage::questionsThisMonth($organization),
        ];
    }

    /** @return array<string, int> */
    private static function period(Organization $organization, Carbon $from, Carbon $to): array
    {
        $storeIds = $organization->stores()->pluck('id');
        $unknown = $organization->assistantSettings()['unknown_reply'];

        $inbound = WhatsAppInboundMessage::whereIn('store_id', $storeIds)->whereBetween('received_at', [$from, $to]);
        $answered = WhatsAppOutboundMessage::whereIn('store_id', $storeIds)->where('origin', 'assistant')
            ->where('type', 'text')->where('body', '!=', $unknown)->whereBetween('created_at', [$from, $to])->count();
        $handoffs = KnowledgeQuestion::where('organization_id', $organization->id)->whereBetween('created_at', [$from, $to])->count()
            + Task::where('organization_id', $organization->id)->where('kind', 'talk_to_human')->whereBetween('created_at', [$from, $to])->count();

        $booked = Appointment::whereIn('store_id', $storeIds)->where('notes', self::BOOKED_NOTE)->whereBetween('created_at', [$from, $to]);
        $conversations = (clone $inbound)->distinct()->count('contact_id');
        $appointments = (clone $booked)->count();

        return [
            'conversations' => $conversations,
            'received' => (clone $inbound)->count(),
            'voice' => (clone $inbound)->where('type', 'audio')->count(),
            'answered' => $answered,
            'handoffs' => $handoffs,
            'autonomy' => $answered + $handoffs > 0 ? (int) round(100 * $answered / ($answered + $handoffs)) : 0,
            'appointments' => $appointments,
            'conversion' => $conversations > 0 ? (int) round(100 * $appointments / $conversations) : 0,
            // CA des RDV pris par l'assistant : encaissé si la vente existe, sinon prix des prestations réservées.
            'revenue' => (int) round((clone $booked)->whereNotIn('status', ['cancelled', 'no_show'])->with('items:id,appointment_id,price', 'purchase:id,amount')->get()
                ->sum(fn (Appointment $a) => $a->purchase?->amount ?? $a->items->sum('price'))),
            'response_seconds' => self::responseSeconds($storeIds->all(), $from, $to),
            'hot' => Customer::where('organization_id', $organization->id)->where('temperature', 'hot')->whereBetween('temperature_at', [$from, $to])->count(),
            'requests' => Customer::where('organization_id', $organization->id)->whereNotNull('need_kind')->where('need_kind', '!=', 'rdv')
                ->whereBetween('need_at', [$from, $to])->count(),
        ];
    }

    /**
     * Temps de réponse moyen (secondes) : écart entre un message du client et
     * la réponse suivante (assistant, robot ou équipe), sur 500 messages au plus.
     *
     * @param  list<int>  $storeIds
     */
    private static function responseSeconds(array $storeIds, Carbon $from, Carbon $to): int
    {
        $inbound = WhatsAppInboundMessage::whereIn('store_id', $storeIds)->whereBetween('received_at', [$from, $to])
            ->latest('received_at')->limit(500)->get(['contact_id', 'received_at']);
        if ($inbound->isEmpty()) {
            return 0;
        }

        $outbound = WhatsAppOutboundMessage::whereIn('contact_id', $inbound->pluck('contact_id')->unique())
            ->where('created_at', '>=', $inbound->min('received_at'))->orderBy('created_at')->get(['contact_id', 'created_at'])
            ->groupBy('contact_id');

        $delays = $inbound->map(function ($in) use ($outbound) {
            $reply = ($outbound[$in->contact_id] ?? collect())->first(fn ($out) => $out->created_at->gte($in->received_at));

            return $reply && $reply->created_at->diffInSeconds($in->received_at, true) <= 86400
                ? $reply->created_at->diffInSeconds($in->received_at, true) : null;
        })->filter(fn ($d) => $d !== null);

        return $delays->isEmpty() ? 0 : (int) round($delays->avg());
    }

    /** @return list<int> messages reçus par heure de la journée (0 à 23) */
    private static function hourly(Organization $organization, Carbon $from, Carbon $to): array
    {
        $tz = $organization->timezone ?: config('app.timezone');
        $counts = WhatsAppInboundMessage::whereIn('store_id', $organization->stores()->pluck('id'))
            ->whereBetween('received_at', [$from, $to])->get(['received_at'])
            ->countBy(fn ($m) => (int) $m->received_at->copy()->setTimezone($tz)->format('G'));

        return array_map(fn ($h) => (int) ($counts[$h] ?? 0), range(0, 23));
    }

    /**
     * Fil en direct : les 12 derniers événements (message reçu, réponse de
     * l'assistant, RDV pris, prospect chaud).
     *
     * @return list<array{at: string, kind: string, name: string, text: string, contact_id: ?int}>
     */
    private static function live(Organization $organization): array
    {
        $storeIds = $organization->stores()->pluck('id');

        $in = WhatsAppInboundMessage::whereIn('store_id', $storeIds)->with('contact:id,name')->latest('received_at')->limit(12)->get()
            ->map(fn ($m) => ['at' => $m->received_at, 'kind' => 'in', 'name' => $m->contact?->name ?? '', 'text' => mb_strimwidth((string) ($m->body ?? '['.$m->type.']'), 0, 80, '…'), 'contact_id' => $m->contact_id]);
        $out = WhatsAppOutboundMessage::whereIn('store_id', $storeIds)->where('origin', 'assistant')->with('contact:id,name')->latest('created_at')->limit(12)->get()
            ->map(fn ($m) => ['at' => $m->created_at, 'kind' => 'assistant', 'name' => $m->contact?->name ?? '', 'text' => mb_strimwidth((string) $m->body, 0, 80, '…'), 'contact_id' => $m->contact_id]);
        $rdv = Appointment::whereIn('store_id', $storeIds)->where('notes', self::BOOKED_NOTE)->with('customer:id,name')->latest('created_at')->limit(12)->get()
            ->map(fn ($a) => ['at' => $a->created_at, 'kind' => 'rdv', 'name' => $a->customer?->name ?? '', 'text' => trim(($a->service ?? 'RDV').' · '.$a->starts_at->format('d/m H:i')), 'contact_id' => null]);

        return $in->concat($out)->concat($rdv)->sortByDesc('at')->take(12)
            ->map(fn ($e) => [...$e, 'at' => $e['at']->toIso8601String()])->values()->all();
    }

    /** @return list<array{day: string, received: int, answered: int, handoffs: int}> */
    private static function daily(Organization $organization, int $days): array
    {
        $storeIds = $organization->stores()->pluck('id');
        $from = now()->subDays($days - 1)->startOfDay();

        $count = fn ($query, string $column) => $query->where($column, '>=', $from)->get([$column])
            ->countBy(fn ($row) => Carbon::parse($row->{$column})->format('Y-m-d'));

        $received = $count(WhatsAppInboundMessage::whereIn('store_id', $storeIds), 'received_at');
        $answered = $count(WhatsAppOutboundMessage::whereIn('store_id', $storeIds)->where('origin', 'assistant')->where('type', 'text')
            ->where('body', '!=', $organization->assistantSettings()['unknown_reply']), 'created_at');
        $handoffs = $count(KnowledgeQuestion::where('organization_id', $organization->id), 'created_at');

        return collect(range(0, $days - 1))->map(function ($i) use ($from, $received, $answered, $handoffs) {
            $day = $from->copy()->addDays($i)->format('Y-m-d');

            return ['day' => $day, 'received' => $received[$day] ?? 0, 'answered' => $answered[$day] ?? 0, 'handoffs' => $handoffs[$day] ?? 0];
        })->all();
    }
}

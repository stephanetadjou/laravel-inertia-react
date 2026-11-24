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

        return [
            'conversations' => (clone $inbound)->distinct()->count('contact_id'),
            'received' => (clone $inbound)->count(),
            'voice' => (clone $inbound)->where('type', 'audio')->count(),
            'answered' => $answered,
            'handoffs' => $handoffs,
            'autonomy' => $answered + $handoffs > 0 ? (int) round(100 * $answered / ($answered + $handoffs)) : 0,
            'appointments' => Appointment::whereIn('store_id', $storeIds)->where('notes', self::BOOKED_NOTE)->whereBetween('created_at', [$from, $to])->count(),
            'requests' => Customer::where('organization_id', $organization->id)->whereNotNull('need_kind')->where('need_kind', '!=', 'rdv')
                ->whereBetween('need_at', [$from, $to])->count(),
        ];
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

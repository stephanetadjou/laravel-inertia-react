<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Questions IA (Haiku) consommées par une entreprise et par mois (plafond selon l'offre). */
class AiUsage extends Model
{
    protected $fillable = ['organization_id', 'month', 'questions', 'input_tokens', 'output_tokens', 'voice_notes', 'voice_seconds'];

    public static function voiceMinutesThisMonth(Organization $organization): int
    {
        return (int) ceil(((int) self::where('organization_id', $organization->id)->where('month', now()->format('Y-m'))->value('voice_seconds')) / 60);
    }

    public static function countVoiceNote(Organization $organization, int $seconds): void
    {
        $usage = self::firstOrCreate(['organization_id' => $organization->id, 'month' => now()->format('Y-m')]);
        $usage->increment('voice_notes', 1, ['voice_seconds' => $usage->voice_seconds + max(0, $seconds)]);
    }

    public static function questionsThisMonth(Organization $organization): int
    {
        return (int) self::where('organization_id', $organization->id)->where('month', now()->format('Y-m'))->value('questions');
    }

    public static function countQuestion(Organization $organization): void
    {
        $usage = self::firstOrCreate(['organization_id' => $organization->id, 'month' => now()->format('Y-m')]);
        $usage->increment('questions');
    }
}

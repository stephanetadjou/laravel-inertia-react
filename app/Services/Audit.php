<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal d'audit : qui a fait quoi, sur quoi, depuis quelle adresse.
 * Appelé par les contrôleurs et les traitements (imports, exports de données
 * personnelles, fusions de fiches, corrections de vente, marques, boutiques,
 * utilisateurs). Depuis un job, passer l'utilisateur à l'origine de l'action.
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(string $action, ?Model $subject = null, ?string $description = null, array $payload = [], ?User $user = null, ?int $organizationId = null): AuditLog
    {
        $user ??= auth()->user();
        $organizationId ??= $user?->organization_id ?? $subject?->getAttribute('organization_id');

        return AuditLog::create([
            'organization_id' => $organizationId,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description !== null ? mb_substr($description, 0, 255) : null,
            'payload' => $payload ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /**
     * Export de données : nombre de lignes et filtres appliqués, pour savoir
     * qui a sorti quelles données personnelles.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function export(string $what, int $rows, array $filters = []): void
    {
        self::record('export', null, "Export {$what} ({$rows} ligne(s))", [
            'what' => $what,
            'rows' => $rows,
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== []),
        ]);
    }
}

import { router, usePage } from '@inertiajs/react';

/*
 * Clients d'une analyse : « Exporter » (Excel, colonnes de relance) et
 * « Relancer » (crée un groupe et l'ouvre dans Actions du jour, avec les
 * liens WhatsApp préremplis ; le groupe sert aussi aux campagnes si l'API
 * est active). Les filtres sont ceux de Fiches clients, critères d'analyse
 * compris ; le serveur les borne au périmètre du rôle et journalise l'export.
 */

export type AudienceFilters = Record<string, string | number | null | undefined>;

export function audienceQuery(filters: AudienceFilters): string {
    return new URLSearchParams(
        Object.entries(filters)
            .filter(([, v]) => v !== null && v !== undefined && v !== '')
            .map(([k, v]) => [k, String(v)]),
    ).toString();
}

export function relaunch(filters: AudienceFilters, label: string) {
    const name = window.prompt('Nom du groupe de clients à relancer :', label);
    if (!name || !name.trim()) return;
    router.post('/audiences/group', { name: name.trim(), filters: Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== null && v !== undefined && v !== '')) });
}

export default function AudienceActions({ filters, label, compact = false }: { filters: AudienceFilters; label: string; compact?: boolean }) {
    const canExport = usePage<{ auth?: { user?: { can_export?: boolean } | null } }>().props.auth?.user?.can_export ?? false;
    const query = audienceQuery({ ...filters, label });
    const size = compact ? 'mdash-btn ghost sm' : 'mdash-btn ghost';

    return (
        <span className="maud-actions" style={{ display: 'inline-flex', gap: 6, flexWrap: 'wrap' }}>
            {canExport && (
                <a className={size} href={`/audiences/export?${query}&format=xlsx`} title={`Exporter les clients : ${label}`}>
                    {compact ? 'Clients ↓' : 'Exporter les clients'}
                </a>
            )}
            <button type="button" className={size} onClick={() => relaunch(filters, label)} title={`Créer un groupe et relancer : ${label}`}>
                {compact ? 'Relancer' : 'Créer un groupe / relancer'}
            </button>
        </span>
    );
}

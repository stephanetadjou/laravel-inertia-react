/*
 * Éléments communs du back-office plateforme Menentia (/admin).
 */

export interface AdminOrganization {
    id: number;
    name: string;
    sector: string | null;
    sector_label: string;
    offer: string;
    plan: string;
    plan_label: string;
    status: 'trial' | 'active' | 'suspended';
    status_label: string;
    trial_expired: boolean;
    created_at: string | null;
    trial_ends_at: string | null;
    users_count: number;
    customers_count: number;
    last_activity: string | null;
}

export interface Option {
    value: string;
    label: string;
}

export interface PlatformLogRow {
    id: number;
    at: string | null;
    user: string | null;
    action: string;
    description: string | null;
    organization?: string | null;
    organization_id?: number | null;
}

export function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

/** Pastille de statut d'une entreprise (essai, essai terminé, active, suspendue). */
export function StatusPill({ organization }: { organization: Pick<AdminOrganization, 'status' | 'status_label' | 'trial_expired'> }) {
    const tone = organization.status === 'suspended' ? 'bad' : organization.trial_expired ? 'warn' : organization.status === 'trial' ? 'info' : 'ok';

    return <span className={`mdash-pill ${tone}`}>{organization.status_label}</span>;
}

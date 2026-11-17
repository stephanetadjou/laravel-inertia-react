import { ReactNode } from 'react';

/* Éléments communs de l'espace « Location » (loueur de véhicules). */

export const field =
    'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

export const money = (n: number | null | undefined) => `${new Intl.NumberFormat('fr-FR').format(n ?? 0)} F`;

export function Field({ label, error, className = '', children }: { label: string; error?: string; className?: string; children: ReactNode }) {
    return (
        <label className={`block text-sm ${className}`}>
            <span className="mb-1 block font-medium">{label}</span>
            {children}
            {error && <span className="text-xs text-error-500">{error}</span>}
        </label>
    );
}

/** Lien WhatsApp prêt à partir (devis, confirmation, reçu) après une action. */
export function WhatsAppBanner({ url }: { url?: string | null }) {
    if (!url) return null;

    return (
        <div className="mdash-flash ok flex flex-wrap items-center justify-between gap-2">
            <span>Message prêt pour le client.</span>
            <a href={url} target="_blank" rel="noreferrer" className="mdash-btn brand sm">
                Envoyer sur WhatsApp
            </a>
        </div>
    );
}

export function Options({ options, empty }: { options: Record<string, string>; empty?: string }) {
    return (
        <>
            {empty !== undefined && <option value="">{empty}</option>}
            {Object.entries(options).map(([k, l]) => (
                <option key={k} value={k}>
                    {l}
                </option>
            ))}
        </>
    );
}

export const fmt = (iso: string | null | undefined) =>
    iso ? new Date(iso.replace(' ', 'T')).toLocaleString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';

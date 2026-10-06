import { Head, router, usePage } from '@inertiajs/react';
import { ReactNode, useEffect, useRef, useState } from 'react';

import { formatNumber } from '../dashboard/Charts';
import '../../pages/Dashboard/dashboard.css';
import './page.css';

/*
 * Kit commun des pages du back-office (style du tableau de bord, .mdash) :
 * en-tête avec titre, sous-titre, actions et barre de filtres, messages
 * flash, chiffres clés, panneaux, pagination, recherche et état vide.
 * Thème clair par défaut, palette sombre sous .dark (cf. dashboard.css).
 */

interface PageProps {
    title: string;
    subtitle?: ReactNode;
    actions?: ReactNode;
    filters?: ReactNode;
    className?: string;
    children: ReactNode;
}

export function Page({ title, subtitle, actions, filters, className = '', children }: PageProps) {
    const flash = usePage<{ flash?: { success: string | null; error: string | null } }>().props.flash;

    return (
        <div className={`mdash mpage ${className}`}>
            <Head title={title} />

            <header className="mdash-top mpage-top">
                <div className="mdash-top-in">
                    <div>
                        <h1>{title}</h1>
                        {subtitle && <small>{subtitle}</small>}
                    </div>
                    {actions && <div className="mpage-actions">{actions}</div>}
                </div>
                {filters && <div className="mpage-filters">{filters}</div>}
            </header>

            {flash?.success && <div className="mdash-flash ok">{flash.success}</div>}
            {flash?.error && <div className="mdash-flash bad">{flash.error}</div>}

            {children}
        </div>
    );
}

export interface Stat {
    label: string;
    value: number | string;
    hint?: string;
    tone?: 'bad' | 'ok' | 'warn';
}

/** Rangée de chiffres clés en haut de page. */
export function StatStrip({ items }: { items: Stat[] }) {
    return (
        <div className="mpage-stats">
            {items.map((s) => (
                <div key={s.label} className={`mpage-stat ${s.tone ?? ''}`}>
                    <span>{s.label}</span>
                    <b className="num">{typeof s.value === 'number' ? formatNumber(s.value) : s.value}</b>
                    {s.hint && <small>{s.hint}</small>}
                </div>
            ))}
        </div>
    );
}

export function Panel({ title, sub, actions, className = '', children }: { title?: ReactNode; sub?: ReactNode; actions?: ReactNode; className?: string; children: ReactNode }) {
    return (
        <section className={`mdash-panel mpage-panel ${className}`}>
            {(title || actions) && (
                <div className="mdash-ph">
                    <div>
                        {title && <h2>{title}</h2>}
                        {sub && <span className="mdash-sub">{sub}</span>}
                    </div>
                    {actions && <div className="mpage-actions">{actions}</div>}
                </div>
            )}
            {children}
        </section>
    );
}

export function Empty({ title, children }: { title: string; children?: ReactNode }) {
    return (
        <div className="mpage-empty">
            <b>{title}</b>
            {children && <span>{children}</span>}
        </div>
    );
}

/** Filtre segmenté (une seule valeur active). */
export function Seg<T extends string>({ label, value, options, onChange }: { label: string; value: T; options: { key: T; label: string }[]; onChange: (key: T) => void }) {
    return (
        <div className="mdash-fgroup">
            <span>{label}</span>
            <div className="mdash-seg" role="group" aria-label={label}>
                {options.map((o) => (
                    <button key={o.key} type="button" aria-pressed={value === o.key} onClick={() => onChange(o.key)}>
                        {o.label}
                    </button>
                ))}
            </div>
        </div>
    );
}

/** Liste déroulante de filtre ; la valeur vide signifie « tous ». */
export function SelectFilter({
    label,
    value,
    options,
    allLabel,
    onChange,
}: {
    label: string;
    value: string | number | null | undefined;
    options: { value: string | number; label: string }[];
    allLabel: string;
    onChange: (value: string | null) => void;
}) {
    return (
        <div className="mdash-fgroup">
            <span>{label}</span>
            <select aria-label={label} value={value ?? ''} onChange={(e) => onChange(e.target.value || null)}>
                <option value="">{allLabel}</option>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

/** Recherche avec délai de frappe (300 ms) avant d'appliquer le filtre. */
export function SearchFilter({ value, placeholder, onSearch, label = 'Recherche' }: { value: string; placeholder: string; onSearch: (q: string) => void; label?: string }) {
    const [text, setText] = useState(value);
    const first = useRef(true);

    useEffect(() => setText(value), [value]);

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        if (text === value) return;
        const timer = setTimeout(() => onSearch(text.trim()), 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [text]);

    return (
        <div className="mdash-fgroup">
            <span>{label}</span>
            <input type="search" aria-label={label} className="mpage-search" placeholder={placeholder} value={text} onChange={(e) => setText(e.target.value)} />
        </div>
    );
}

/**
 * Filtres en query string : applique les changements à l'URL courante
 * (valeurs vides retirées, retour en page 1) en gardant l'état de la page.
 */
export function useQueryFilters(path: string, current: Record<string, unknown>) {
    return (changes: Record<string, unknown>) => {
        const next: Record<string, string | number> = {};
        Object.entries({ ...current, ...changes, page: changes.page ?? null }).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '' && value !== false) {
                next[key] = typeof value === 'number' ? value : String(value);
            }
        });
        router.get(path, next, { preserveState: true, preserveScroll: true, replace: true });
    };
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

/** Pagination serveur (paginateur Laravel) : « 1–25 sur 120 » + précédent / suivant. */
export function Pager({ page, onPage, perPage, onPerPage, unit = 'éléments' }: { page: Paginated<unknown>; onPage: (n: number) => void; perPage?: number; onPerPage?: (n: number) => void; unit?: string }) {
    if (page.total === 0) return null;

    return (
        <div className="mpage-pager">
            <span>
                {formatNumber(page.from ?? 0)}–{formatNumber(page.to ?? 0)} sur {formatNumber(page.total)} {unit}
            </span>
            <div className="btns">
                {perPage !== undefined && onPerPage && (
                    <select aria-label="Lignes par page" value={perPage} onChange={(e) => onPerPage(Number(e.target.value))}>
                        {[25, 50, 100].map((n) => (
                            <option key={n} value={n}>
                                {n} / page
                            </option>
                        ))}
                    </select>
                )}
                <button type="button" className="mdash-btn ghost sm" disabled={page.current_page <= 1} onClick={() => onPage(page.current_page - 1)}>
                    ← Précédent
                </button>
                <button type="button" className="mdash-btn ghost sm" disabled={page.current_page >= page.last_page} onClick={() => onPage(page.current_page + 1)}>
                    Suivant →
                </button>
            </div>
        </div>
    );
}

export const PlusIcon = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" aria-hidden="true">
        <path d="M12 5v14M5 12h14" />
    </svg>
);

/** Date ISO (AAAA-MM-JJ ou date-heure) → « 12 sept. 2026 ». */
export function formatDay(value: string | null | undefined, withTime = false): string {
    if (!value) return '—';
    const date = new Date(value.length === 10 ? `${value}T12:00:00` : value);
    const day = date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    return withTime ? `${day} · ${date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}` : day;
}

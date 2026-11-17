import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { NavSection } from './navigation';
import { AppIcon } from './apps';

interface FoundCustomer {
    id: number;
    name: string | null;
    phone_e164: string;
}

/**
 * Recherche rapide ClientlyX (Ctrl+K ou « / ») : un client ou une application,
 * depuis n'importe quel écran. Le client d'abord : on tape un nom ou un
 * numéro, on ouvre sa fiche.
 */
export default function CommandPalette({ sections, open, onClose }: { sections: NavSection[]; open: boolean; onClose: () => void }) {
    const [q, setQ] = useState('');
    const [customers, setCustomers] = useState<FoundCustomer[]>([]);
    const [cursor, setCursor] = useState(0);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (open) {
            setQ('');
            setCustomers([]);
            setCursor(0);
            setTimeout(() => input.current?.focus(), 10);
        }
    }, [open]);

    useEffect(() => {
        if (q.trim().length < 2) {
            setCustomers([]);
            return;
        }
        const ctrl = new AbortController();
        const t = setTimeout(() => {
            fetch(`/apps/search?q=${encodeURIComponent(q.trim())}`, { headers: { Accept: 'application/json' }, signal: ctrl.signal })
                .then((r) => (r.ok ? r.json() : { customers: [] }))
                .then((d) => setCustomers(d.customers ?? []))
                .catch(() => undefined);
        }, 180);
        return () => {
            clearTimeout(t);
            ctrl.abort();
        };
    }, [q]);

    const norm = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const apps = useMemo(() => sections.filter((s) => s.key !== 'platform' && (q === '' || norm(s.name).includes(norm(q)))).slice(0, 6), [sections, q]);
    const results = [
        ...customers.map((c) => ({ key: `c${c.id}`, href: `/customers/${c.id}`, label: c.name || c.phone_e164, hint: c.phone_e164, app: undefined as NavSection | undefined })),
        ...apps.map((s) => ({ key: s.key, href: s.path, label: s.name, hint: 'Application', app: s })),
    ];

    if (!open) return null;

    const go = (href: string) => {
        onClose();
        router.visit(href);
    };

    return (
        <div className="fixed inset-0 z-[100001] flex items-start justify-center bg-[#0b1f3a]/50 px-4 pt-[12vh] backdrop-blur-sm" onMouseDown={onClose}>
            <div
                role="dialog"
                aria-label="Recherche rapide"
                className="w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-gray-900"
                onMouseDown={(e) => e.stopPropagation()}
            >
                <div className="flex items-center gap-3 border-b border-gray-100 px-4 dark:border-gray-800">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="text-gray-400" aria-hidden="true">
                        <circle cx="11" cy="11" r="7" />
                        <path d="m20 20-3.5-3.5" />
                    </svg>
                    <input
                        ref={input}
                        value={q}
                        onChange={(e) => {
                            setQ(e.target.value);
                            setCursor(0);
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Escape') onClose();
                            if (e.key === 'ArrowDown') {
                                e.preventDefault();
                                setCursor((c) => Math.min(c + 1, results.length - 1));
                            }
                            if (e.key === 'ArrowUp') {
                                e.preventDefault();
                                setCursor((c) => Math.max(c - 1, 0));
                            }
                            if (e.key === 'Enter' && results[cursor]) go(results[cursor].href);
                        }}
                        placeholder="Un client (nom, numéro) ou une application…"
                        className="h-14 w-full bg-transparent text-[15px] text-gray-900 outline-none placeholder:text-gray-400 dark:text-white"
                    />
                    <kbd className="rounded border border-gray-200 px-1.5 text-[11px] text-gray-400 dark:border-gray-700">Échap</kbd>
                </div>
                <ul className="max-h-[50vh] overflow-y-auto p-2">
                    {results.length === 0 && <li className="px-3 py-6 text-center text-sm text-gray-500">Aucun résultat</li>}
                    {results.map((r, i) => (
                        <li key={r.key}>
                            <button
                                type="button"
                                onMouseEnter={() => setCursor(i)}
                                onClick={() => go(r.href)}
                                className={`flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left ${i === cursor ? 'bg-blue-50 dark:bg-white/10' : ''}`}
                            >
                                {r.app ? (
                                    <AppIcon section={r.app} size="sm" />
                                ) : (
                                    <span className="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-cyan-400 to-blue-600 text-[13px] font-bold text-white">
                                        {r.label.charAt(0).toUpperCase()}
                                    </span>
                                )}
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[14px] font-medium text-gray-900 dark:text-white">{r.label}</span>
                                    <span className="block text-[12px] text-gray-500">{r.hint}</span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

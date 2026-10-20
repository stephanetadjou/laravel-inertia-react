import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/** Notifications « À traiter » partagées par le serveur (App\Services\NotificationService). */
export interface NotificationItem {
    key: string;
    tone: 'bad' | 'warn' | 'info';
    count: number;
    title: string;
    detail: string;
    href: string;
}

export interface Notifications {
    unread: { available: boolean; count: number };
    items: NotificationItem[];
    total: number;
}

export function useNotifications(): Notifications | null {
    return usePage<{ notifications?: Notifications | null }>().props.notifications ?? null;
}

const DOT: Record<NotificationItem['tone'], string> = {
    bad: 'bg-error-500',
    warn: 'bg-warning-500',
    info: 'bg-blue-light-500',
};

const UNREAD_TITLE = 'Messages clients non lus';
const UNREAD_PENDING = 'Disponible après la mise en place du webhook Meta : les réponses des clients apparaîtront ici.';

/** Cloche de l'en-tête (toutes les pages) : compteur + menu déroulant. */
export function NotificationBell() {
    const notifications = useNotifications();
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const close = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false);
        const escape = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', escape);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    if (!notifications) return null;

    const { total, items, unread } = notifications;

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                aria-label={total > 0 ? `Notifications : ${total} à traiter` : 'Notifications'}
                className="relative flex h-11 w-11 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white"
            >
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                    <path d="M13.7 21a2 2 0 0 1-3.4 0" />
                </svg>
                {total > 0 && (
                    <span className="absolute -top-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-error-500 px-1 text-[11px] font-semibold text-white">
                        {total > 99 ? '99+' : total}
                    </span>
                )}
            </button>

            {open && (
                <div className="absolute right-0 mt-2 w-[min(360px,calc(100vw-24px))] rounded-xl border border-gray-200 bg-white shadow-theme-lg dark:border-gray-800 dark:bg-gray-900">
                    <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                        <span className="text-sm font-semibold text-gray-800 dark:text-white/90">À traiter</span>
                        <Link href="/dashboard" className="text-xs font-medium text-brand-text" onClick={() => setOpen(false)}>
                            Tableau de bord
                        </Link>
                    </div>
                    <ul className="max-h-[60vh] overflow-y-auto py-1">
                        {/* Messages non lus : affichés seulement une fois les messages entrants disponibles. */}
                        {unread.available && (
                        <li className="flex gap-3 px-4 py-2.5">
                            <span className={`mt-1.5 h-2 w-2 flex-none rounded-full ${unread.count > 0 ? 'bg-error-500' : 'bg-gray-300 dark:bg-gray-600'}`} />
                            <span className="min-w-0">
                                <span className="block text-sm font-medium text-gray-800 dark:text-white/90">
                                    {UNREAD_TITLE} : {unread.available ? unread.count : '—'}
                                </span>
                                {!unread.available && <span className="block text-xs text-gray-500 dark:text-gray-400">{UNREAD_PENDING}</span>}
                            </span>
                        </li>
                        )}
                        {items.map((item) => (
                            <li key={item.key}>
                                <Link
                                    href={item.href}
                                    onClick={() => setOpen(false)}
                                    className="flex gap-3 px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-white/5"
                                >
                                    <span className={`mt-1.5 h-2 w-2 flex-none rounded-full ${DOT[item.tone]}`} />
                                    <span className="min-w-0">
                                        <span className="block text-sm font-medium text-gray-800 dark:text-white/90">{item.title}</span>
                                        <span className="block text-xs text-gray-500 dark:text-gray-400">{item.detail}</span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                        {items.length === 0 && <li className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">Rien d'autre à traiter. 👍</li>}
                    </ul>
                </div>
            )}
        </div>
    );
}

/** Encart « À traiter » du tableau de bord (style .mdash). */
export function NotificationPanel() {
    const notifications = useNotifications();
    if (!notifications) return null;

    const { items, unread } = notifications;

    return (
        <section className="mdash-panel mnotif" aria-label="À traiter">
            <div className="mdash-ph">
                <h2>À traiter</h2>
                <span className="mdash-sub">{items.length === 0 ? 'Tout est à jour' : 'Cliquez pour ouvrir la page concernée'}</span>
            </div>
            <ul className="mnotif-list">
                {unread.available && (
                <li className={`mnotif-item ${unread.count > 0 ? 'bad' : 'idle'}`}>
                    <span className="dot" />
                    <span className="txt">
                        <b>
                            {UNREAD_TITLE} : {unread.available ? unread.count : '—'}
                        </b>
                        {!unread.available && <small>{UNREAD_PENDING}</small>}
                    </span>
                </li>
                )}
                {items.map((item) => (
                    <li key={item.key}>
                        <Link href={item.href} className={`mnotif-item ${item.tone}`}>
                            <span className="dot" />
                            <span className="txt">
                                <b>{item.title}</b>
                                <small>{item.detail}</small>
                            </span>
                            <span className="go" aria-hidden="true">
                                →
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

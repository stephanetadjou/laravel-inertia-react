import { useEffect, useState } from 'react';

/*
 * Recherche d'une fiche client existante (nom, code ou numéro), pour les
 * formulaires des modules : échéancier, abonnement, inscription à un cours.
 * Même recherche que « Ajouter une vente » (/purchases/manual/customers).
 */

export interface PickedCustomer {
    id: number;
    name: string;
    phone: string | null;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

export default function CustomerPicker({ value, onChange, placeholder = 'Nom, code client ou numéro' }: { value: PickedCustomer | null; onChange: (c: PickedCustomer | null) => void; placeholder?: string }) {
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<PickedCustomer[]>([]);

    useEffect(() => {
        const text = query.trim();
        if (text.length < 2) {
            setFound([]);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(`/purchases/manual/customers?q=${encodeURIComponent(text)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : []))
                .then((rows: PickedCustomer[]) => setFound(rows))
                .catch(() => undefined);
        }, 200);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    if (value) {
        return (
            <div className="flex items-center justify-between rounded-lg border border-brand-300 bg-brand-50 px-3 py-2 text-sm dark:bg-white/10">
                <span>
                    <b>{value.name}</b> <span className="mono text-gray-500">{value.phone ?? ''}</span>
                </span>
                <button type="button" className="text-xs text-brand-text hover:underline" onClick={() => onChange(null)}>
                    Changer
                </button>
            </div>
        );
    }

    return (
        <div className="relative">
            <input className={field} value={query} onChange={(e) => setQuery(e.target.value)} placeholder={placeholder} aria-label="Rechercher un client" autoComplete="off" />
            {found.length > 0 && (
                <ul className="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-200 bg-white text-sm shadow-theme-md dark:border-gray-700 dark:bg-gray-900">
                    {found.map((c) => (
                        <li key={c.id}>
                            <button
                                type="button"
                                className="flex w-full justify-between px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-white/5"
                                onClick={() => {
                                    onChange(c);
                                    setQuery('');
                                    setFound([]);
                                }}
                            >
                                <span>{c.name}</span>
                                <span className="mono text-gray-500">{c.phone ?? ''}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

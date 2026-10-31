import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';
import { availableIn, CatalogEntry } from '../../components/appointments/AppointmentFormModal';

/*
 * Ventes › Ajouter une vente : un ticket saisi à la main, traité comme un
 * ticket importé (fiche client, segments, points de fidélité, journal).
 * Client existant, nouveau client (nom + téléphone) ou vente anonyme ;
 * lignes du catalogue (secteurs sur rendez-vous) ou famille + article
 * (commerce), avec quantité, prix unitaire et remise.
 */

export interface ManualSaleOptions {
    retail: boolean;
    stores: { id: number; name: string }[];
    catalog: CatalogEntry[];
    families: { id: number; name: string; parent: string | null }[];
    family_label: string;
    payment_methods: Record<string, string>;
    currency: string;
}

interface Line {
    catalog_item_id: number | null;
    category_id: number | '';
    article: string;
    name: string;
    quantity: number;
    unit_price: number;
    discount: number;
}

interface Found {
    id: number;
    name: string;
    phone: string | null;
}

type Mode = 'existing' | 'new' | 'anonymous';

const MODES: { key: Mode; label: string }[] = [
    { key: 'existing', label: 'Client existant' },
    { key: 'new', label: 'Nouveau client' },
    { key: 'anonymous', label: 'Vente anonyme' },
];

const emptyLine = (): Line => ({ catalog_item_id: null, category_id: '', article: '', name: '', quantity: 1, unit_price: 0, discount: 0 });

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function today(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export default function ManualSaleModal({ options, onClose }: { options: ManualSaleOptions; onClose: () => void }) {
    const money = (n: number) => `${n.toLocaleString('fr-FR')} ${options.currency}`;
    const { data, setData, post, processing, errors, transform } = useForm({
        customer_mode: 'existing' as Mode,
        customer_id: null as number | null,
        name: '',
        phone: '',
        buyer_name: '',
        store_id: options.stores.length === 1 ? options.stores[0].id : ('' as number | ''),
        purchased_at: today(),
        payment_method: 'cash',
        receipt_number: '',
        note: '',
        lines: [emptyLine()],
    });
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<Found[]>([]);
    const [chosen, setChosen] = useState<Found | null>(null);

    // Recherche d'un client du périmètre, 300 ms après la frappe.
    useEffect(() => {
        if (data.customer_mode !== 'existing' || query.trim().length < 2) {
            setFound([]);
            return;
        }
        const timer = setTimeout(() => {
            fetch(`/purchases/manual/customers?q=${encodeURIComponent(query.trim())}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then((r) => (r.ok ? r.json() : []))
                .then(setFound)
                .catch(() => setFound([]));
        }, 300);
        return () => clearTimeout(timer);
    }, [query, data.customer_mode]);

    const lineTotal = (l: Line) => Math.max(0, l.quantity * l.unit_price - l.discount);
    const total = data.lines.reduce((sum, l) => sum + lineTotal(l), 0);
    const offer = data.store_id ? availableIn(options.catalog, Number(data.store_id)) : options.catalog;
    const errorList = Object.values(errors);

    // Familles groupées par catégorie : la catégorie elle-même d'abord, puis ses familles.
    const parents = new Set(options.families.map((f) => f.parent).filter(Boolean));
    const familyGroups = [
        ...[...parents].sort((a, b) => a!.localeCompare(b!, 'fr')).map((parent) => ({
            label: parent!,
            items: [
                ...options.families.filter((f) => f.name === parent && !f.parent).map((f) => ({ id: f.id, label: `${f.name} (toute la catégorie)` })),
                ...options.families.filter((f) => f.parent === parent).map((f) => ({ id: f.id, label: f.name })),
            ],
        })),
        { label: 'Autres familles', items: options.families.filter((f) => !f.parent && !parents.has(f.name)).map((f) => ({ id: f.id, label: f.name })) },
    ].filter((g) => g.items.length > 0);

    function update(index: number, changes: Partial<Line>) {
        setData('lines', data.lines.map((l, i) => (i === index ? { ...l, ...changes } : l)));
    }

    function pickCatalog(index: number, value: string) {
        if (value === '') {
            update(index, { catalog_item_id: null });
            return;
        }
        const item = options.catalog.find((c) => c.id === Number(value));
        if (item) update(index, { catalog_item_id: item.id, name: item.name, unit_price: item.price });
    }

    transform((d) => ({
        ...d,
        lines: d.lines.map((l) =>
            options.retail
                ? { category_id: l.category_id === '' ? null : l.category_id, article: l.article || null, quantity: l.quantity, unit_price: l.unit_price, discount: l.discount }
                : { catalog_item_id: l.catalog_item_id, name: l.name || null, quantity: l.quantity, unit_price: l.unit_price, discount: l.discount },
        ),
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/purchases/manual', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-3xl p-6">
            <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Ajouter une vente</h3>
            <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
                Un ticket saisi à la main, compté comme un ticket de caisse : historique du client, segments et points de fidélité.
            </p>

            <form onSubmit={submit} className="space-y-5">
                <fieldset>
                    <legend className="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">Client</legend>
                    <div className="mdash-seg mb-3" role="group" aria-label="Client">
                        {MODES.map((m) => (
                            <button key={m.key} type="button" aria-pressed={data.customer_mode === m.key} onClick={() => setData('customer_mode', m.key)}>
                                {m.label}
                            </button>
                        ))}
                    </div>

                    {data.customer_mode === 'existing' &&
                        (chosen ? (
                            <div className="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                <span>
                                    <b>{chosen.name}</b> {chosen.phone && <span className="text-gray-500">· +{chosen.phone}</span>}
                                </span>
                                <button
                                    type="button"
                                    className="mdash-link"
                                    onClick={() => {
                                        setChosen(null);
                                        setData('customer_id', null);
                                    }}
                                >
                                    Changer
                                </button>
                            </div>
                        ) : (
                            <div className="relative">
                                <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Nom, téléphone ou code client" aria-label="Rechercher un client" className={fieldClasses()} />
                                {found.length > 0 && (
                                    <ul className="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-200 bg-white shadow-theme-md dark:border-gray-700 dark:bg-gray-900">
                                        {found.map((c) => (
                                            <li key={c.id}>
                                                <button
                                                    type="button"
                                                    className="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5"
                                                    onClick={() => {
                                                        setChosen(c);
                                                        setData('customer_id', c.id);
                                                        setFound([]);
                                                    }}
                                                >
                                                    <b>{c.name}</b> {c.phone && <span className="text-gray-500">· +{c.phone}</span>}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {query.trim().length >= 2 && found.length === 0 && (
                                    <p className="mt-1 text-xs text-gray-500">
                                        Aucun client trouvé ?{' '}
                                        <button type="button" className="mdash-link" onClick={() => setData((d) => ({ ...d, customer_mode: 'new', name: /\d/.test(query) ? '' : query, phone: /\d/.test(query) ? query : '' }))}>
                                            Créer un nouveau client
                                        </button>
                                    </p>
                                )}
                            </div>
                        ))}

                    {data.customer_mode === 'new' && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Nom du client" aria-label="Nom du client" className={fieldClasses()} />
                            <input value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="Téléphone (ex. 077 12 34 56)" aria-label="Téléphone" inputMode="tel" className={fieldClasses()} />
                        </div>
                    )}

                    {data.customer_mode === 'anonymous' && (
                        <input value={data.buyer_name} onChange={(e) => setData('buyer_name', e.target.value)} placeholder="Nom de l’acheteur (facultatif)" aria-label="Nom de l’acheteur" className={fieldClasses()} />
                    )}
                </fieldset>

                <div className="grid gap-3 sm:grid-cols-4">
                    <label className="text-sm text-gray-600 dark:text-gray-400 sm:col-span-2">
                        Point de vente
                        <select value={data.store_id} onChange={(e) => setData('store_id', e.target.value ? Number(e.target.value) : '')} className={`${fieldClasses()} mt-1`}>
                            {options.stores.length > 1 && <option value="">Choisir…</option>}
                            {options.stores.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm text-gray-600 dark:text-gray-400">
                        Date
                        <input type="date" value={data.purchased_at} max={today()} onChange={(e) => setData('purchased_at', e.target.value)} className={`${fieldClasses()} mt-1`} />
                    </label>
                    <label className="text-sm text-gray-600 dark:text-gray-400">
                        Paiement
                        <select value={data.payment_method} onChange={(e) => setData('payment_method', e.target.value)} className={`${fieldClasses()} mt-1`}>
                            {Object.entries(options.payment_methods).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs text-gray-500">
                                {options.retail ? (
                                    <>
                                        <th className="pb-2 font-medium">{options.family_label}</th>
                                        <th className="pb-2 font-medium">Article (facultatif)</th>
                                    </>
                                ) : (
                                    <th className="pb-2 font-medium">Prestation ou produit</th>
                                )}
                                <th className="w-16 pb-2 font-medium">Qté</th>
                                <th className="w-28 pb-2 font-medium">Prix unitaire</th>
                                <th className="w-24 pb-2 font-medium">Remise</th>
                                <th className="w-28 pb-2 text-right font-medium">Montant</th>
                                <th className="w-8" />
                            </tr>
                        </thead>
                        <tbody>
                            {data.lines.map((line, index) => (
                                <tr key={index} className="border-t border-gray-100 align-top dark:border-gray-800">
                                    {options.retail ? (
                                        <>
                                            <td className="py-2 pr-2">
                                                <select aria-label={options.family_label} value={line.category_id} onChange={(e) => update(index, { category_id: e.target.value ? Number(e.target.value) : '' })} className={`${fieldClasses()} min-w-44`}>
                                                    <option value="">Choisir…</option>
                                                    {familyGroups.map((g) => (
                                                        <optgroup key={g.label} label={g.label}>
                                                            {g.items.map((f) => (
                                                                <option key={f.id} value={f.id}>
                                                                    {f.label}
                                                                </option>
                                                            ))}
                                                        </optgroup>
                                                    ))}
                                                </select>
                                            </td>
                                            <td className="py-2 pr-2">
                                                <input aria-label="Article" value={line.article} onChange={(e) => update(index, { article: e.target.value })} placeholder="ex. Robe portefeuille" className={fieldClasses()} />
                                            </td>
                                        </>
                                    ) : (
                                        <td className="space-y-1 py-2 pr-2">
                                            <select aria-label="Prestation ou produit" value={line.catalog_item_id ?? ''} onChange={(e) => pickCatalog(index, e.target.value)} className={fieldClasses()}>
                                                <option value="">Ligne libre…</option>
                                                {offer.map((c) => (
                                                    <option key={c.id} value={c.id}>
                                                        {c.name} · {c.type === 'product' ? 'produit' : 'prestation'}
                                                    </option>
                                                ))}
                                            </select>
                                            {line.catalog_item_id === null && (
                                                <input aria-label="Libellé" value={line.name} onChange={(e) => update(index, { name: e.target.value })} placeholder="Libellé" className={fieldClasses()} />
                                            )}
                                        </td>
                                    )}
                                    <td className="py-2 pr-2">
                                        <input aria-label="Quantité" type="number" min={1} value={line.quantity} onChange={(e) => update(index, { quantity: Math.max(1, Number(e.target.value)) })} className={fieldClasses()} />
                                    </td>
                                    <td className="py-2 pr-2">
                                        <input aria-label="Prix unitaire" type="number" min={0} step={100} value={line.unit_price} onChange={(e) => update(index, { unit_price: Math.max(0, Number(e.target.value)) })} className={fieldClasses()} />
                                    </td>
                                    <td className="py-2 pr-2">
                                        <input aria-label="Remise" type="number" min={0} step={100} value={line.discount} onChange={(e) => update(index, { discount: Math.max(0, Number(e.target.value)) })} className={fieldClasses()} />
                                    </td>
                                    <td className="py-2 pt-4 text-right font-medium tabular-nums">{money(lineTotal(line))}</td>
                                    <td className="py-2 text-right">
                                        {data.lines.length > 1 && (
                                            <button type="button" aria-label={`Retirer la ligne ${index + 1}`} className="h-7 w-7 rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10" onClick={() => setData('lines', data.lines.filter((_, i) => i !== index))}>
                                                ×
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <button type="button" className="mdash-link text-sm" onClick={() => setData('lines', [...data.lines, emptyLine()])}>
                    + Ajouter une ligne
                </button>

                <div className="grid gap-3 sm:grid-cols-3">
                    <label className="text-sm text-gray-600 dark:text-gray-400">
                        N° de ticket (facultatif)
                        <input value={data.receipt_number} onChange={(e) => setData('receipt_number', e.target.value)} className={`${fieldClasses()} mt-1`} />
                    </label>
                    <label className="text-sm text-gray-600 dark:text-gray-400 sm:col-span-2">
                        Note (facultatif)
                        <input value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="ex. commande par téléphone" className={`${fieldClasses()} mt-1`} />
                    </label>
                </div>

                {errorList.length > 0 && (
                    <ul className="space-y-0.5 text-sm text-error-500">
                        {[...new Set(errorList)].map((message) => (
                            <li key={message}>{message}</li>
                        ))}
                    </ul>
                )}

                <div className="flex flex-wrap items-center justify-between gap-4 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <div>
                        <span className="block text-xs text-gray-500">Total</span>
                        <b className="text-2xl tabular-nums text-gray-900 dark:text-white">{money(total)}</b>
                    </div>
                    <div className="flex gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Annuler
                        </Button>
                        <Button disabled={processing}>{processing ? 'Enregistrement…' : 'Enregistrer la vente'}</Button>
                    </div>
                </div>
            </form>
        </Modal>
    );
}

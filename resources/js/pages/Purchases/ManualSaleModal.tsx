import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

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
    code: string | null;
    segment: string | null;
    last_purchase_at: string | null;
}

/** +24177123456 → « +241 77 12 34 56 ». */
function formatPhone(phone: string | null): string {
    if (!phone) return '';
    const local = phone.startsWith('241') ? phone.slice(3) : phone;
    return `${phone.startsWith('241') ? '+241 ' : '+'}${local.replace(/(\d{2})(?=\d)/g, '$1 ')}`;
}

function formatDay(day: string): string {
    return new Date(`${day}T12:00:00`).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
}

/** Téléphone, code client, segment et dernier achat d'une suggestion ou du client choisi. */
function CustomerMeta({ customer }: { customer: Found }) {
    const parts = [
        formatPhone(customer.phone),
        customer.code ? `code ${customer.code}` : null,
        customer.segment,
        customer.last_purchase_at ? `dernier achat le ${formatDay(customer.last_purchase_at)}` : 'aucun achat',
    ].filter(Boolean);

    return <span className="block text-xs text-gray-500 dark:text-gray-400">{parts.join(' · ')}</span>;
}

/**
 * Recherche d'un client existant : suggestions dès 2 caractères (nom,
 * téléphone même partiel, code client ; sans accents ni majuscules), dans le
 * périmètre de l'utilisateur. Flèches pour se déplacer, Entrée pour choisir,
 * Échap pour fermer la liste.
 */
function CustomerSearch({ onPick, onCreate }: { onPick: (c: Found) => void; onCreate: (text: string) => void }) {
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<Found[]>([]);
    const [searched, setSearched] = useState('');
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => input.current?.focus(), []);

    useEffect(() => {
        const text = query.trim();
        if (text.length < 2) {
            setFound([]);
            setSearched('');
            return;
        }
        setLoading(true);
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(`/purchases/manual/customers?q=${encodeURIComponent(text)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : []))
                .then((rows: Found[]) => {
                    setFound(rows);
                    setSearched(text);
                    setActive(0);
                    setOpen(true);
                    setLoading(false);
                })
                .catch(() => undefined);
        }, 200);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    const pick = (c: Found) => {
        setOpen(false);
        onPick(c);
    };

    const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowDown' && found.length > 0) {
            e.preventDefault();
            setOpen(true);
            setActive((i) => (i + 1) % found.length);
        } else if (e.key === 'ArrowUp' && found.length > 0) {
            e.preventDefault();
            setActive((i) => (i - 1 + found.length) % found.length);
        } else if (e.key === 'Enter') {
            // Entrée ne soumet jamais le formulaire depuis la recherche.
            e.preventDefault();
            if (open && found[active]) pick(found[active]);
        } else if (e.key === 'Escape' && open) {
            // Échap ferme la liste, pas la fenêtre.
            e.preventDefault();
            e.stopPropagation();
            setOpen(false);
        }
    };

    const showEmpty = open && !loading && searched !== '' && searched === query.trim() && found.length === 0;

    return (
        <div className="relative">
            <input
                ref={input}
                role="combobox"
                aria-expanded={open && found.length > 0}
                aria-controls="manual-sale-customers"
                aria-activedescendant={open && found[active] ? `manual-sale-customer-${found[active].id}` : undefined}
                aria-autocomplete="list"
                aria-label="Rechercher un client"
                value={query}
                onChange={(e) => {
                    setQuery(e.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onBlur={() => setTimeout(() => setOpen(false), 150)}
                onKeyDown={onKeyDown}
                placeholder="Rechercher par nom, téléphone ou code client"
                autoComplete="off"
                className={`${fieldClasses()} py-2.5 text-[15px]`}
            />
            {loading && query.trim().length >= 2 && <span className="absolute right-3 top-3 text-xs text-gray-400">Recherche…</span>}

            {open && found.length > 0 && (
                <ul id="manual-sale-customers" role="listbox" className="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-xl border border-gray-200 bg-white py-1 shadow-theme-lg dark:border-gray-700 dark:bg-gray-900">
                    {found.map((c, i) => (
                        <li
                            key={c.id}
                            id={`manual-sale-customer-${c.id}`}
                            role="option"
                            aria-selected={i === active}
                            className={`cursor-pointer px-3 py-2 text-sm ${i === active ? 'bg-brand-50 dark:bg-white/10' : ''}`}
                            onMouseEnter={() => setActive(i)}
                            onMouseDown={(e) => {
                                e.preventDefault();
                                pick(c);
                            }}
                        >
                            <b className="text-gray-900 dark:text-white">{c.name}</b>
                            <CustomerMeta customer={c} />
                        </li>
                    ))}
                </ul>
            )}

            {showEmpty && (
                <div className="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm dark:border-gray-700">
                    <span className="text-gray-600 dark:text-gray-400">Aucun client trouvé pour « {searched} ».</span>
                    <button type="button" className="mdash-link" onMouseDown={(e) => e.preventDefault()} onClick={() => onCreate(searched)}>
                        Créer un nouveau client avec « {searched} »
                    </button>
                </div>
            )}

            <p className="mt-1 text-xs text-gray-500">Dès 2 caractères : nom, téléphone (même partiel) ou code client.</p>
        </div>
    );
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
    const [chosen, setChosen] = useState<Found | null>(null);

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
                            <div className="flex items-center justify-between gap-3 rounded-xl border border-brand-200 bg-brand-25 px-4 py-3 text-sm dark:border-brand-800 dark:bg-white/[0.03]">
                                <div>
                                    <b className="text-gray-900 dark:text-white">{chosen.name}</b>
                                    <CustomerMeta customer={chosen} />
                                </div>
                                <button
                                    type="button"
                                    className="mdash-btn ghost sm"
                                    onClick={() => {
                                        setChosen(null);
                                        setData('customer_id', null);
                                    }}
                                >
                                    Changer
                                </button>
                            </div>
                        ) : (
                            <CustomerSearch
                                onPick={(c) => {
                                    setChosen(c);
                                    setData('customer_id', c.id);
                                }}
                                onCreate={(text) => setData((d) => ({ ...d, customer_mode: 'new', name: /\d/.test(text) ? '' : text, phone: /\d/.test(text) ? text : '' }))}
                            />
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

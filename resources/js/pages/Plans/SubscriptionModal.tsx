import { useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import { Modal } from '../../components/ui/modal';
import { formatNumber } from '../../components/dashboard/Charts';
import CustomerPicker, { PickedCustomer } from '../../components/common/CustomerPicker';

/*
 * Nouvel abonnement : la personne (une personne suivie fait payer son
 * responsable), la formule, la date de début, les options. L'échéancier est
 * préparé automatiquement (mensualités, frais d'inscription, options).
 */

export interface PlanOption {
    id: number;
    name: string;
    catalog_item_id: number | null;
    price: number;
    period: 'month' | 'season' | 'sessions' | 'once';
    sessions_count: number | null;
    fees: { label: string; amount: number }[];
    options: { label: string; amount: number; recurring: boolean }[];
    description: string | null;
    active: boolean;
    price_label: string;
}

export interface SubscriptionFormOptions {
    planOptions: PlanOption[];
    stores: { id: number; name: string }[];
    season: { start: string; end: string };
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

export default function SubscriptionModal({ options, customer: initial, onClose }: { options: SubscriptionFormOptions; customer?: PickedCustomer | null; onClose: () => void }) {
    const [customer, setCustomer] = useState<PickedCustomer | null>(initial ?? null);
    const { data, setData, post, processing, errors } = useForm({
        customer_id: initial ? String(initial.id) : '',
        plan_id: options.planOptions[0] ? String(options.planOptions[0].id) : '',
        store_id: options.stores.length === 1 ? String(options.stores[0].id) : '',
        starts_on: new Date().toISOString().slice(0, 10),
        ends_on: '',
        options: [] as number[],
        season_installments: '1',
    });
    const plan = options.planOptions.find((p) => String(p.id) === data.plan_id);
    const recurring = plan ? data.options.reduce((sum, i) => sum + (plan.options[i]?.recurring ? plan.options[i].amount : 0), 0) : 0;
    const upfront = plan ? plan.fees.reduce((s, f) => s + f.amount, 0) + data.options.reduce((sum, i) => sum + (plan.options[i] && !plan.options[i].recurring ? plan.options[i].amount : 0), 0) : 0;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/subscriptions', { preserveScroll: true, onSuccess: onClose });
    };

    if (options.planOptions.length === 0) {
        return (
            <Modal isOpen={true} onClose={onClose} className="max-w-md p-6">
                <h2 className="text-lg font-semibold">Aucune formule</h2>
                <p className="mt-2 text-sm text-gray-600">Créez d’abord une formule (prix, période, frais d’inscription) dans Ventes › Formules.</p>
                <div className="mt-4 flex justify-end gap-2">
                    <a href="/plans" className="mdash-btn brand">
                        Créer une formule
                    </a>
                </div>
            </Modal>
        );
    }

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-xl p-6">
            <form onSubmit={submit} className="space-y-3 text-sm">
                <h2 className="text-lg font-semibold">Nouvel abonnement</h2>
                <label className="block">
                    <span className="mb-1 block font-medium">Qui s’abonne ?</span>
                    <CustomerPicker
                        value={customer}
                        onChange={(c) => {
                            setCustomer(c);
                            setData('customer_id', c ? String(c.id) : '');
                        }}
                    />
                    <span className="mt-1 block text-xs text-gray-500">Pour un enfant, choisissez l’enfant : son responsable paiera.</span>
                    {errors.customer_id && <span className="text-xs text-error-500">{errors.customer_id}</span>}
                </label>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label className="sm:col-span-2">
                        <span className="mb-1 block font-medium">Formule</span>
                        <select className={field} value={data.plan_id} onChange={(e) => setData((d) => ({ ...d, plan_id: e.target.value, options: [] }))}>
                            {options.planOptions.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name} — {p.price_label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Début</span>
                        <input type="date" className={field} value={data.starts_on} onChange={(e) => setData('starts_on', e.target.value)} required />
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Fin (facultatif)</span>
                        <input type="date" className={field} value={data.ends_on} onChange={(e) => setData('ends_on', e.target.value)} />
                        <span className="mt-1 block text-xs text-gray-500">{plan && (plan.period === 'month' || plan.period === 'season') ? `par défaut, fin de saison (${new Date(`${options.season.end}T12:00:00`).toLocaleDateString('fr-FR')})` : 'sans fin par défaut'}</span>
                        {errors.ends_on && <span className="text-xs text-error-500">{errors.ends_on}</span>}
                    </label>
                    {plan?.period === 'season' && (
                        <label>
                            <span className="mb-1 block font-medium">Payer la saison en</span>
                            <select className={field} value={data.season_installments} onChange={(e) => setData('season_installments', e.target.value)}>
                                {[1, 2, 3, 4, 6, 9, 10].map((n) => (
                                    <option key={n} value={n}>
                                        {n === 1 ? 'une fois' : `${n} tranches`}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    {options.stores.length > 1 && (
                        <label>
                            <span className="mb-1 block font-medium">Lieu</span>
                            <select className={field} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} required>
                                <option value="">—</option>
                                {options.stores.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                            {errors.store_id && <span className="text-xs text-error-500">{errors.store_id}</span>}
                        </label>
                    )}
                </div>
                {plan && plan.options.length > 0 && (
                    <fieldset>
                        <legend className="mb-1 font-medium">Options</legend>
                        {plan.options.map((o, i) => (
                            <label key={i} className="flex items-center gap-2">
                                <input type="checkbox" checked={data.options.includes(i)} onChange={(e) => setData('options', e.target.checked ? [...data.options, i] : data.options.filter((x) => x !== i))} />
                                {o.label} — {formatNumber(o.amount)} F{o.recurring ? (plan.period === 'month' ? ' / mois' : ' par tranche') : ' (une fois)'}
                            </label>
                        ))}
                    </fieldset>
                )}
                {plan && (
                    <p className="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                        À payer au début : {formatNumber(upfront + (plan.period === 'month' || plan.period === 'season' ? 0 : plan.price))} F{upfront > 0 ? ' (frais et options)' : ''}
                        {plan.period === 'month' && ` · puis ${formatNumber(plan.price + recurring)} F chaque mois`}
                        {plan.period === 'season' && ` · saison : ${formatNumber(plan.price + recurring)} F`}
                    </p>
                )}
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing || !data.customer_id}>
                        {processing ? 'Enregistrement…' : 'Abonner'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

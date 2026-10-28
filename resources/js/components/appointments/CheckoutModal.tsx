import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import Button from '../ui/button/Button';
import { Modal } from '../ui/modal';
import { AppointmentRow, CatalogEntry, availableIn, formatPrice } from './AppointmentFormModal';

/*
 * Encaisser un rendez-vous : les prestations du rendez-vous sont reprises,
 * on ajoute les produits vendus sur place (ou une ligne libre), on ajuste
 * quantités et prix. La vente est enregistrée et le rendez-vous passe à
 * « Honoré ».
 */

interface Line {
    catalog_item_id: number | null;
    name: string;
    quantity: number;
    unit_price: number;
    type: 'service' | 'product' | null;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-2.5 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

export default function CheckoutModal({
    appointment,
    catalog,
    paymentMethods = {},
    onClose,
}: {
    appointment: AppointmentRow;
    catalog: CatalogEntry[];
    paymentMethods?: Record<string, string>;
    onClose: () => void;
}) {
    const initial: Line[] = appointment.items.length
        ? appointment.items.map((i) => ({ catalog_item_id: i.catalog_item_id, name: i.name, quantity: 1, unit_price: i.price, type: 'service' }))
        : appointment.service
          ? [{ catalog_item_id: null, name: appointment.service, quantity: 1, unit_price: 0, type: null }]
          : [];

    const { data, setData, post, processing, errors } = useForm({
        purchased_at: appointment.starts_at.slice(0, 10),
        payment_method: 'cash',
        lines: initial,
    });

    const offer = availableIn(catalog, appointment.store_id);
    const total = data.lines.reduce((sum, l) => sum + l.quantity * l.unit_price, 0);
    const lineErrors = Object.entries(errors).filter(([key]) => key.startsWith('lines'));

    function update(index: number, changes: Partial<Line>) {
        setData('lines', data.lines.map((l, i) => (i === index ? { ...l, ...changes } : l)));
    }

    function add(value: string) {
        if (!value) return;
        if (value === 'free') {
            setData('lines', [...data.lines, { catalog_item_id: null, name: '', quantity: 1, unit_price: 0, type: null }]);
            return;
        }
        const item = catalog.find((c) => c.id === Number(value));
        if (item) setData('lines', [...data.lines, { catalog_item_id: item.id, name: item.name, quantity: 1, unit_price: item.price, type: item.type }]);
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/appointments/${appointment.id}/checkout`, { preserveScroll: true, onSuccess: onClose });
    };

    const services = offer.filter((c) => c.type === 'service');
    const products = offer.filter((c) => c.type === 'product');

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-2xl p-6">
            <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Encaisser</h3>
            <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
                {appointment.customer_name} · {appointment.store_name}. La vente s'ajoute à l'historique d'achat du client et le rendez-vous passe à « Honoré ».
            </p>

            <form onSubmit={submit} className="space-y-4">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs text-gray-500">
                                <th className="pb-2 font-medium">Article</th>
                                <th className="w-20 pb-2 font-medium">Qté</th>
                                <th className="w-32 pb-2 font-medium">Prix unitaire</th>
                                <th className="w-28 pb-2 text-right font-medium">Montant</th>
                                <th className="w-8" />
                            </tr>
                        </thead>
                        <tbody>
                            {data.lines.map((line, index) => (
                                <tr key={index} className="border-t border-gray-100 dark:border-gray-800">
                                    <td className="py-2 pr-2">
                                        {line.catalog_item_id ? (
                                            <span>
                                                {line.name}
                                                <span className="ml-1.5 text-xs text-gray-500">{line.type === 'product' ? 'produit' : 'prestation'}</span>
                                            </span>
                                        ) : (
                                            <input aria-label="Libellé" value={line.name} onChange={(e) => update(index, { name: e.target.value })} placeholder="Libellé" className={fieldClasses()} />
                                        )}
                                    </td>
                                    <td className="py-2 pr-2">
                                        <input aria-label="Quantité" type="number" min={1} value={line.quantity} onChange={(e) => update(index, { quantity: Math.max(1, Number(e.target.value)) })} className={fieldClasses()} />
                                    </td>
                                    <td className="py-2 pr-2">
                                        <input aria-label="Prix unitaire" type="number" min={0} step={500} value={line.unit_price} onChange={(e) => update(index, { unit_price: Math.max(0, Number(e.target.value)) })} className={fieldClasses()} />
                                    </td>
                                    <td className="py-2 text-right font-medium tabular-nums">{formatPrice(line.quantity * line.unit_price)}</td>
                                    <td className="py-2 text-right">
                                        <button type="button" aria-label={`Retirer ${line.name}`} className="h-7 w-7 rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10" onClick={() => setData('lines', data.lines.filter((_, i) => i !== index))}>
                                            ×
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <select aria-label="Ajouter un article" value="" onChange={(e) => add(e.target.value)} className={fieldClasses()}>
                    <option value="">+ Ajouter une prestation ou un produit</option>
                    {products.length > 0 && (
                        <optgroup label="Produits">
                            {products.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name} · {formatPrice(c.price)}
                                </option>
                            ))}
                        </optgroup>
                    )}
                    {services.length > 0 && (
                        <optgroup label="Prestations">
                            {services.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name} · {formatPrice(c.price)}
                                </option>
                            ))}
                        </optgroup>
                    )}
                    <option value="free">Ligne libre…</option>
                </select>

                {lineErrors.length > 0 && <p className="text-sm text-error-500">{lineErrors[0][1]}</p>}

                <div className="flex flex-wrap items-end justify-between gap-4 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <label className="text-sm text-gray-600 dark:text-gray-400">
                        Date de la vente
                        <input type="date" value={data.purchased_at} onChange={(e) => setData('purchased_at', e.target.value)} className={`${fieldClasses()} mt-1 w-44`} />
                    </label>
                    {Object.keys(paymentMethods).length > 0 && (
                        <label className="text-sm text-gray-600 dark:text-gray-400">
                            Paiement
                            <select value={data.payment_method} onChange={(e) => setData('payment_method', e.target.value)} className={`${fieldClasses()} mt-1 w-44`}>
                                {Object.entries(paymentMethods).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <div className="text-right">
                        <span className="block text-xs text-gray-500">Total</span>
                        <b className="text-2xl tabular-nums text-gray-900 dark:text-white">{formatPrice(total)}</b>
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing || data.lines.length === 0}>{processing ? 'Enregistrement…' : `Encaisser ${formatPrice(total)}`}</Button>
                </div>
            </form>
        </Modal>
    );
}

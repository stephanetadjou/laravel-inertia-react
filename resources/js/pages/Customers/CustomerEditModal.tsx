import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

import { Modal } from '../../components/ui/modal';
import Button from '../../components/ui/button/Button';

export interface EditableCustomer {
    id: number;
    name: string;
    phone_e164: string;
    gender: 'H' | 'F' | null;
    birthdate: string | null;
}

export interface EditableContact {
    id: number;
    store_name: string;
    consent_status: 'unknown' | 'opt_in' | 'opt_out';
    editable: boolean;
}

interface CustomerEditModalProps {
    customer: EditableCustomer | null;
    contacts: EditableContact[];
    isOpen: boolean;
    onClose: () => void;
}

type Consent = EditableContact['consent_status'];

const CONSENT_OPTIONS: { value: Consent; label: string; active: string }[] = [
    { value: 'opt_in', label: 'Opt-in', active: 'border-success-500 bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400' },
    { value: 'unknown', label: 'Inconnu', active: 'border-gray-400 bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200' },
    { value: 'opt_out', label: 'Opt-out', active: 'border-error-500 bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400' },
];

const field =
    'w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
const label = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300';
const legend = 'mb-3 text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400';

/**
 * Modification d'une fiche client (page Fiches clients et fiche 360°) :
 * identité du Groupe (nom confirmé, numéro — l'ancien est conservé) et
 * consentement WhatsApp de chaque boutique du périmètre.
 */
export default function CustomerEditModal({ customer, contacts, isOpen, onClose }: CustomerEditModalProps) {
    const { data, setData, put, processing, errors, clearErrors, transform } = useForm({
        name: '',
        phone_e164: '',
        gender: '',
        birthdate: '',
        consents: {} as Record<number, Consent>,
    });

    // Recharge le formulaire à chaque ouverture, sur le client choisi.
    useEffect(() => {
        if (!isOpen || !customer) return;
        clearErrors();
        setData({
            name: customer.name,
            phone_e164: customer.phone_e164,
            gender: customer.gender ?? '',
            birthdate: customer.birthdate ?? '',
            consents: Object.fromEntries(contacts.map((c) => [c.id, c.consent_status])),
        });
    }, [isOpen, customer?.id]);

    if (!customer) return null;

    const editableContacts = contacts.filter((c) => c.editable);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // N'envoie que les consentements modifiables et réellement changés.
        transform((d) => ({
            ...d,
            consents: Object.fromEntries(
                editableContacts.filter((c) => d.consents[c.id] && d.consents[c.id] !== c.consent_status).map((c) => [c.id, d.consents[c.id]]),
            ),
        }));
        put(`/customers/${customer.id}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} className="max-w-xl p-6 sm:p-8">
            <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Modifier le client</h3>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                Fiche du Groupe : le nom saisi est confirmé et ne sera plus remplacé par celui d'un nouveau contact.
            </p>

            <form onSubmit={submit} className="space-y-6">
                <fieldset className="space-y-4">
                    <legend className={legend}>Identité</legend>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label htmlFor="ce_name" className={label}>
                                Nom
                            </label>
                            <input id="ce_name" className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                        </div>
                        <div className="sm:col-span-2">
                            <label htmlFor="ce_phone" className={label}>
                                Numéro WhatsApp
                            </label>
                            <input id="ce_phone" className={field} value={data.phone_e164} onChange={(e) => setData('phone_e164', e.target.value)} />
                            <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                                Un nouveau numéro s'applique à toutes les boutiques du client ; l'ancien est conservé (plus utilisé).
                            </p>
                            {errors.phone_e164 && <p className="mt-1.5 text-sm text-error-500">{errors.phone_e164}</p>}
                        </div>
                        <div>
                            <label htmlFor="ce_gender" className={label}>
                                Sexe
                            </label>
                            <select id="ce_gender" className={field} value={data.gender} onChange={(e) => setData('gender', e.target.value)}>
                                <option value="">—</option>
                                <option value="F">Femme</option>
                                <option value="H">Homme</option>
                            </select>
                        </div>
                        <div>
                            <label htmlFor="ce_birth" className={label}>
                                Date de naissance
                            </label>
                            <input id="ce_birth" type="date" className={field} value={data.birthdate} onChange={(e) => setData('birthdate', e.target.value)} />
                        </div>
                    </div>
                </fieldset>

                {contacts.length > 0 && (
                    <fieldset>
                        <legend className={legend}>Consentement WhatsApp par boutique</legend>
                        <div className="divide-y divide-gray-100 rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                            {contacts.map((c) => (
                                <div key={c.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <span className="text-sm font-medium text-gray-800 dark:text-white/90">{c.store_name}</span>
                                    <div className="flex gap-1.5" role="radiogroup" aria-label={`Consentement chez ${c.store_name}`}>
                                        {CONSENT_OPTIONS.map((o) => {
                                            const active = data.consents[c.id] === o.value;
                                            return (
                                                <button
                                                    key={o.value}
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={active}
                                                    disabled={!c.editable}
                                                    onClick={() => setData('consents', { ...data.consents, [c.id]: o.value })}
                                                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-50 ${
                                                        active ? o.active : 'border-gray-200 text-gray-500 hover:border-gray-300 dark:border-gray-800 dark:text-gray-400'
                                                    }`}
                                                >
                                                    {o.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            Opt-in uniquement si le client a donné son accord (ex. réponse « OUI » au message de bienvenue). Seuls les opt-in reçoivent les
                            campagnes.
                        </p>
                    </fieldset>
                )}

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing || !data.name}>{processing ? 'Enregistrement…' : 'Enregistrer'}</Button>
                </div>
            </form>
        </Modal>
    );
}

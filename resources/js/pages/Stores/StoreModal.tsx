import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

import { Modal } from '../../components/ui/modal';
import Button from '../../components/ui/button/Button';

export interface StoreRow {
    id: number;
    name: string;
    brand_id: number | null;
    brand_name: string | null;
    contacts_count: number;
    whatsapp_phone_number_id: string | null;
    has_token: boolean;
    connection: 'own' | 'shared' | 'none';
}

interface StoreModalProps {
    /** null = création d'une boutique ; sinon modification. */
    store: StoreRow | null;
    isOpen: boolean;
    onClose: () => void;
    canManage: boolean;
    brands: { id: number; name: string }[];
    sharedAccountConfigured: boolean;
}

const field =
    'w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden disabled:opacity-60 dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
const label = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300';
const hint = 'mt-1.5 text-xs text-gray-500 dark:text-gray-400';

export default function StoreModal({ store, isOpen, onClose, canManage, brands, sharedAccountConfigured }: StoreModalProps) {
    const isEditing = store !== null;
    const { data, setData, post, put, transform, processing, errors, reset, clearErrors } = useForm({
        name: '',
        brand_id: '',
        mode: 'shared' as 'shared' | 'own',
        whatsapp_phone_number_id: '',
        whatsapp_access_token: '',
    });

    // Recharge le formulaire à chaque ouverture, sur la boutique choisie.
    useEffect(() => {
        if (!isOpen) return;
        clearErrors();
        setData({
            name: store?.name ?? '',
            brand_id: String(store?.brand_id ?? brands[0]?.id ?? ''),
            mode: store && (store.whatsapp_phone_number_id || store.has_token) ? 'own' : 'shared',
            whatsapp_phone_number_id: store?.whatsapp_phone_number_id ?? '',
            whatsapp_access_token: '',
        });
    }, [isOpen, store?.id]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        };

        if (!isEditing) {
            transform((d) => ({ name: d.name, brand_id: Number(d.brand_id) }));
            post('/stores', options);
            return;
        }

        // Nom et marque ne sont envoyés que par les rôles qui gèrent l'organisation.
        transform((d) => ({
            ...(canManage ? { name: d.name, brand_id: Number(d.brand_id) } : {}),
            use_shared_account: d.mode === 'shared',
            whatsapp_phone_number_id: d.mode === 'own' ? d.whatsapp_phone_number_id : '',
            whatsapp_access_token: d.mode === 'own' ? d.whatsapp_access_token : '',
        }));
        put(`/stores/${store.id}`, options);
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} className="max-w-xl p-6 sm:p-8">
            <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">{isEditing ? 'Modifier la boutique' : 'Nouvelle boutique'}</h3>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                {isEditing ? `${store.name}${store.brand_name ? ` · ${store.brand_name}` : ''}` : 'Rattachée à une marque de votre organisation.'}
            </p>

            <form onSubmit={submit} className="space-y-6">
                <fieldset className="space-y-4">
                    <legend className="mb-3 text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">Informations</legend>
                    <div>
                        <label htmlFor="store_name" className={label}>
                            Nom de la boutique
                        </label>
                        <input
                            id="store_name"
                            type="text"
                            value={data.name}
                            disabled={isEditing && !canManage}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="ex. Adidas Glass"
                            className={field}
                        />
                        {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                    </div>
                    {canManage ? (
                        <div>
                            <label htmlFor="store_brand" className={label}>
                                Marque
                            </label>
                            <select id="store_brand" value={data.brand_id} onChange={(e) => setData('brand_id', e.target.value)} className={field}>
                                {brands.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </select>
                            {errors.brand_id && <p className="mt-1.5 text-sm text-error-500">{errors.brand_id}</p>}
                        </div>
                    ) : (
                        isEditing && <p className={hint}>Le nom et la marque ne peuvent être modifiés que par un administrateur de l'organisation.</p>
                    )}
                </fieldset>

                {isEditing && (
                    <fieldset className="space-y-4">
                        <legend className="mb-3 text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">Connexion WhatsApp</legend>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {(
                                [
                                    ['shared', 'Compte partagé', "Envoie depuis le numéro commun de l'organisation (.env)."],
                                    ['own', 'Numéro propre', 'Numéro WhatsApp Business dédié à cette boutique.'],
                                ] as const
                            ).map(([value, title, desc]) => (
                                <label
                                    key={value}
                                    className={`flex cursor-pointer gap-3 rounded-xl border p-3 text-sm transition ${
                                        data.mode === value
                                            ? 'border-brand-500 bg-brand-50 dark:border-brand-400 dark:bg-brand-500/10'
                                            : 'border-gray-200 hover:border-gray-300 dark:border-gray-800 dark:hover:border-gray-700'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="mode"
                                        value={value}
                                        checked={data.mode === value}
                                        onChange={() => setData('mode', value)}
                                        className="mt-0.5 accent-brand-500"
                                    />
                                    <span>
                                        <span className="block font-medium text-gray-800 dark:text-white/90">{title}</span>
                                        <span className="block text-xs text-gray-500 dark:text-gray-400">{desc}</span>
                                    </span>
                                </label>
                            ))}
                        </div>

                        {data.mode === 'shared' && !sharedAccountConfigured && (
                            <p className="rounded-lg bg-warning-50 px-3 py-2 text-xs text-warning-700 dark:bg-warning-500/15 dark:text-warning-400">
                                Aucun compte partagé n'est configuré (META_WHATSAPP_TOKEN / META_PHONE_NUMBER_ID) : la boutique ne pourra pas envoyer de
                                messages.
                            </p>
                        )}

                        {data.mode === 'own' && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label htmlFor="store_phone_id" className={label}>
                                        Phone Number ID
                                    </label>
                                    <input
                                        id="store_phone_id"
                                        type="text"
                                        inputMode="numeric"
                                        value={data.whatsapp_phone_number_id}
                                        onChange={(e) => setData('whatsapp_phone_number_id', e.target.value)}
                                        placeholder="ex. 1234567890123"
                                        className={field}
                                    />
                                    <p className={hint}>Meta Business › WhatsApp › Numéros de téléphone.</p>
                                    {errors.whatsapp_phone_number_id && <p className="mt-1.5 text-sm text-error-500">{errors.whatsapp_phone_number_id}</p>}
                                </div>
                                <div>
                                    <label htmlFor="store_token" className={label}>
                                        Access token
                                    </label>
                                    <input
                                        id="store_token"
                                        type="password"
                                        autoComplete="new-password"
                                        value={data.whatsapp_access_token}
                                        onChange={(e) => setData('whatsapp_access_token', e.target.value)}
                                        placeholder={store.has_token ? '•••••••• (inchangé)' : 'Token permanent'}
                                        className={field}
                                    />
                                    <p className={hint}>
                                        {store.has_token ? 'Laisser vide pour garder le token enregistré.' : 'Sans token, le token partagé est utilisé.'}
                                    </p>
                                </div>
                            </div>
                        )}
                    </fieldset>
                )}

                {!isEditing && <p className={hint}>La connexion WhatsApp se configure ensuite avec « Modifier ».</p>}

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing || !data.name}>{processing ? 'Enregistrement…' : isEditing ? 'Enregistrer' : 'Créer la boutique'}</Button>
                </div>
            </form>
        </Modal>
    );
}

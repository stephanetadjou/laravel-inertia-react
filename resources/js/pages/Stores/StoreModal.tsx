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
}

const field =
    'w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden disabled:opacity-60 dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
const label = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300';
const hint = 'mt-1.5 text-xs text-gray-500 dark:text-gray-400';

export default function StoreModal({ store, isOpen, onClose, canManage, brands }: StoreModalProps) {
    const isEditing = store !== null;
    const { data, setData, post, put, transform, processing, errors, reset, clearErrors } = useForm({
        name: '',
        brand_id: '',
    });

    // Recharge le formulaire à chaque ouverture, sur la boutique choisie.
    useEffect(() => {
        if (!isOpen) return;
        clearErrors();
        setData({
            name: store?.name ?? '',
            brand_id: String(store?.brand_id ?? brands[0]?.id ?? ''),
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

        transform((d) => ({ name: d.name, brand_id: Number(d.brand_id) }));
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

                <p className={hint}>La connexion WhatsApp de la boutique se configure dans Paramètres → WhatsApp.</p>

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

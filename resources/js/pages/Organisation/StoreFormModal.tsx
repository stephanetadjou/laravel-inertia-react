import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { Modal } from '../../components/ui/modal';
import Button from '../../components/ui/button/Button';

export interface StoreFormValues {
    id: number;
    name: string;
    code: string | null;
    city: string | null;
    is_active: boolean;
}

interface StoreFormModalProps {
    isOpen: boolean;
    onClose: () => void;
    brandId: number;
    brandName: string;
    /** Point de vente à modifier ; absent = création. */
    store?: StoreFormValues;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

const labelClasses = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300';

export default function StoreFormModal({ isOpen, onClose, brandId, brandName, store }: StoreFormModalProps) {
    const { data, setData, post, put, processing, errors, reset } = useForm({
        brand_id: String(brandId),
        name: store?.name ?? '',
        code: store?.code ?? '',
        city: store?.city ?? '',
        is_active: store?.is_active ?? true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset('name', 'code', 'city');
                onClose();
            },
        };
        if (store) put(`/stores/${store.id}`, options);
        else post('/stores', options);
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} className="max-w-md p-6">
            <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">{store ? 'Modifier le point de vente' : 'Nouveau point de vente'}</h3>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">Sous la marque « {brandName} ».</p>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="store_name" className={labelClasses}>
                        Nom du point de vente
                    </label>
                    <input
                        id="store_name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="ex. Centre-ville"
                        className={fieldClasses()}
                        autoFocus
                    />
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label htmlFor="store_code" className={labelClasses}>
                            Code
                        </label>
                        <input id="store_code" type="text" value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="ex. LBV01" className={fieldClasses()} />
                        {errors.code && <p className="mt-1.5 text-sm text-error-500">{errors.code}</p>}
                    </div>
                    <div>
                        <label htmlFor="store_city" className={labelClasses}>
                            Ville
                        </label>
                        <input id="store_city" type="text" value={data.city} onChange={(e) => setData('city', e.target.value)} placeholder="ex. Libreville" className={fieldClasses()} />
                        {errors.city && <p className="mt-1.5 text-sm text-error-500">{errors.city}</p>}
                    </div>
                </div>
                <p className="-mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Le code est celui de votre logiciel de caisse : il permet de reconnaître le point de vente dans les fichiers de ventes importés.
                </p>

                {store && (
                    <label className="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" className="mt-1 h-4 w-4" checked={!data.is_active} onChange={(e) => setData('is_active', !e.target.checked)} />
                        <span>
                            Point de vente fermé
                            <span className="block text-xs text-gray-500 dark:text-gray-400">Son historique de ventes reste dans les analyses.</span>
                        </span>
                    </label>
                )}

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing || !data.name}>{processing ? 'Enregistrement...' : store ? 'Enregistrer' : 'Créer le point de vente'}</Button>
                </div>
            </form>
        </Modal>
    );
}

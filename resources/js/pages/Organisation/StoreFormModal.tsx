import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { Modal } from '../../components/ui/modal';
import Button from '../../components/ui/button/Button';

interface StoreFormModalProps {
    isOpen: boolean;
    onClose: () => void;
    brandId: number;
    brandName: string;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

export default function StoreFormModal({ isOpen, onClose, brandId, brandName }: StoreFormModalProps) {
    const { data, setData, post, processing, errors, reset } = useForm({ brand_id: String(brandId), name: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/stores', {
            preserveScroll: true,
            onSuccess: () => {
                reset('name');
                onClose();
            },
        });
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} className="max-w-md p-6">
            <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Nouvelle boutique</h3>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">Sous la marque « {brandName} ».</p>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="store_name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Nom de la boutique
                    </label>
                    <input
                        id="store_name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="ex. Adidas Glass"
                        className={fieldClasses()}
                        autoFocus
                    />
                    <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                        La connexion WhatsApp (numéro + token) se configure ensuite depuis la page Marques &amp; boutiques (bouton « Modifier »).
                    </p>
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing || !data.name}>{processing ? 'Création...' : 'Créer la boutique'}</Button>
                </div>
            </form>
        </Modal>
    );
}

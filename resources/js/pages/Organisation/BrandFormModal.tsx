import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { Modal } from '../../components/ui/modal';
import Button from '../../components/ui/button/Button';

export interface BrandFormValues {
    id: number;
    name: string;
}

interface BrandFormModalProps {
    isOpen: boolean;
    onClose: () => void;
    brand: BrandFormValues | null;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

export default function BrandFormModal({ isOpen, onClose, brand }: BrandFormModalProps) {
    const isEditing = brand !== null;
    const { data, setData, post, put, processing, errors, reset } = useForm({ name: brand?.name ?? '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); onClose(); } };
        if (isEditing) {
            put(`/brands/${brand.id}`, options);
        } else {
            post('/brands', options);
        }
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} className="max-w-md p-6">
            <h3 className="mb-6 text-lg font-semibold text-gray-800 dark:text-white/90">
                {isEditing ? 'Renommer la marque' : 'Nouvelle marque'}
            </h3>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="brand_name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Nom de la marque
                    </label>
                    <input
                        id="brand_name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="ex. Adidas, Carré, Mango..."
                        className={fieldClasses()}
                        autoFocus
                    />
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing}>{processing ? 'Enregistrement...' : isEditing ? 'Renommer' : 'Créer la marque'}</Button>
                </div>
            </form>
        </Modal>
    );
}

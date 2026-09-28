import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { Modal } from '../../components/ui/modal';
import Button from '../../components/ui/button/Button';

interface StoreOption {
    id: number;
    name: string;
}

interface NewContactModalProps {
    isOpen: boolean;
    onClose: () => void;
    stores: StoreOption[];
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

export default function NewContactModal({ isOpen, onClose, stores }: NewContactModalProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        store_id: stores[0] ? stores[0].id.toString() : '',
        name: '',
        phone_e164: '',
        gender: '',
        birthdate: '',
        consent_status: 'unknown',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/contacts', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    function close() {
        reset();
        onClose();
    }

    return (
        <Modal isOpen={isOpen} onClose={close} className="max-w-md p-6">
            <h3 className="mb-6 text-lg font-semibold text-gray-800 dark:text-white/90">Nouveau contact</h3>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="new_store_id" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Boutique
                    </label>
                    <select
                        id="new_store_id"
                        value={data.store_id}
                        onChange={(e) => setData('store_id', e.target.value)}
                        className={fieldClasses()}
                    >
                        {stores.map((store) => (
                            <option key={store.id} value={store.id}>
                                {store.name}
                            </option>
                        ))}
                    </select>
                    {errors.store_id && <p className="mt-1.5 text-sm text-error-500">{errors.store_id}</p>}
                </div>

                <div>
                    <label htmlFor="new_name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Nom
                    </label>
                    <input
                        id="new_name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className={fieldClasses()}
                        autoFocus
                    />
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>

                <div>
                    <label htmlFor="new_phone_e164" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Téléphone
                    </label>
                    <input
                        id="new_phone_e164"
                        type="text"
                        value={data.phone_e164}
                        onChange={(e) => setData('phone_e164', e.target.value)}
                        placeholder="077000000"
                        className={fieldClasses()}
                    />
                    {errors.phone_e164 && <p className="mt-1.5 text-sm text-error-500">{errors.phone_e164}</p>}
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label htmlFor="new_gender" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Sexe
                        </label>
                        <select id="new_gender" value={data.gender} onChange={(e) => setData('gender', e.target.value)} className={fieldClasses()}>
                            <option value="">—</option>
                            <option value="H">Homme</option>
                            <option value="F">Femme</option>
                        </select>
                    </div>

                    <div>
                        <label htmlFor="new_birthdate" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Naissance
                        </label>
                        <input
                            id="new_birthdate"
                            type="date"
                            value={data.birthdate}
                            onChange={(e) => setData('birthdate', e.target.value)}
                            className={fieldClasses()}
                        />
                    </div>
                </div>

                <div>
                    <label htmlFor="new_consent_status" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Consentement
                    </label>
                    <select
                        id="new_consent_status"
                        value={data.consent_status}
                        onChange={(e) => setData('consent_status', e.target.value)}
                        className={fieldClasses()}
                    >
                        <option value="unknown">unknown</option>
                        <option value="opt_in">opt_in</option>
                        <option value="opt_out">opt_out</option>
                    </select>
                    {errors.consent_status && <p className="mt-1.5 text-sm text-error-500">{errors.consent_status}</p>}
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={close}>
                        Annuler
                    </Button>
                    <Button disabled={processing || !data.name || !data.phone_e164}>{processing ? 'Création...' : 'Créer le contact'}</Button>
                </div>
            </form>
        </Modal>
    );
}

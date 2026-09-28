import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { Modal } from '../../components/ui/modal';
import Button from '../../components/ui/button/Button';

export interface ContactFormValues {
    id: number;
    name: string;
    phone_e164: string;
    gender: string | null;
    birthdate: string | null;
    consent_status: string;
}

interface ContactFormModalProps {
    isOpen: boolean;
    onClose: () => void;
    contact: ContactFormValues;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

export default function ContactFormModal({ isOpen, onClose, contact }: ContactFormModalProps) {
    const { data, setData, put, processing, errors, reset } = useForm({
        name: contact.name,
        phone_e164: contact.phone_e164,
        gender: contact.gender ?? '',
        birthdate: contact.birthdate ?? '',
        consent_status: contact.consent_status,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/contacts/${contact.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} className="max-w-md p-6">
            <h3 className="mb-6 text-lg font-semibold text-gray-800 dark:text-white/90">Modifier le contact</h3>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Nom
                    </label>
                    <input
                        id="name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className={fieldClasses()}
                        autoFocus
                    />
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>

                <div>
                    <label htmlFor="phone_e164" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Téléphone (E.164)
                    </label>
                    <input
                        id="phone_e164"
                        type="text"
                        value={data.phone_e164}
                        onChange={(e) => setData('phone_e164', e.target.value)}
                        placeholder="24177000000"
                        className={fieldClasses()}
                    />
                    {errors.phone_e164 && <p className="mt-1.5 text-sm text-error-500">{errors.phone_e164}</p>}
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label htmlFor="gender" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Sexe
                        </label>
                        <select id="gender" value={data.gender} onChange={(e) => setData('gender', e.target.value)} className={fieldClasses()}>
                            <option value="">—</option>
                            <option value="H">Homme</option>
                            <option value="F">Femme</option>
                        </select>
                    </div>

                    <div>
                        <label htmlFor="birthdate" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Naissance
                        </label>
                        <input
                            id="birthdate"
                            type="date"
                            value={data.birthdate}
                            onChange={(e) => setData('birthdate', e.target.value)}
                            className={fieldClasses()}
                        />
                    </div>
                </div>

                <div>
                    <label htmlFor="consent_status" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Consentement
                    </label>
                    <select
                        id="consent_status"
                        value={data.consent_status}
                        onChange={(e) => setData('consent_status', e.target.value)}
                        className={fieldClasses()}
                    >
                        <option value="opt_in">opt_in</option>
                        <option value="opt_out">opt_out</option>
                        <option value="unknown">unknown</option>
                    </select>
                    {errors.consent_status && <p className="mt-1.5 text-sm text-error-500">{errors.consent_status}</p>}
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing}>{processing ? 'Enregistrement...' : 'Enregistrer'}</Button>
                </div>
            </form>
        </Modal>
    );
}

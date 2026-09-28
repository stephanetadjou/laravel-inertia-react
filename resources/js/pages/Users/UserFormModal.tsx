import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { Modal } from '../../components/ui/modal';
import Button from '../../components/ui/button/Button';

export interface UserFormValues {
    id: number;
    name: string;
    email: string;
    role_id: number;
    brand_id: number | null;
    store_id: number | null;
}

interface BrandOption {
    id: number;
    name: string;
}

interface StoreOption {
    id: number;
    name: string;
    brand_id: number;
}

export interface RoleOption {
    id: number;
    name: string;
    scope: 'organization' | 'brand' | 'store';
}

interface UserFormModalProps {
    isOpen: boolean;
    onClose: () => void;
    user: UserFormValues | null;
    brands: BrandOption[];
    stores: StoreOption[];
    roles: RoleOption[];
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

export default function UserFormModal({ isOpen, onClose, user, brands, stores, roles }: UserFormModalProps) {
    const isEditing = user !== null;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        password: '',
        role_id: user?.role_id ?? roles[0]?.id ?? '',
        brand_id: user?.brand_id ? String(user.brand_id) : (brands[0] ? String(brands[0].id) : ''),
        store_id: user?.store_id ? String(user.store_id) : (stores[0] ? String(stores[0].id) : ''),
    });

    const selectedRole = roles.find((role) => role.id === Number(data.role_id));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        };

        if (isEditing) {
            put(`/users/${user.id}`, options);
        } else {
            post('/users', options);
        }
    };

    function close() {
        reset();
        onClose();
    }

    return (
        <Modal isOpen={isOpen} onClose={close} className="max-w-md p-6">
            <h3 className="mb-6 text-lg font-semibold text-gray-800 dark:text-white/90">
                {isEditing ? 'Modifier l\'utilisateur' : 'Nouvel utilisateur'}
            </h3>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="user_name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Nom
                    </label>
                    <input
                        id="user_name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className={fieldClasses()}
                        autoFocus
                    />
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>

                <div>
                    <label htmlFor="user_email" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Email
                    </label>
                    <input
                        id="user_email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className={fieldClasses()}
                    />
                    {errors.email && <p className="mt-1.5 text-sm text-error-500">{errors.email}</p>}
                </div>

                <div>
                    <label htmlFor="user_password" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {isEditing ? 'Nouveau mot de passe (optionnel)' : 'Mot de passe'}
                    </label>
                    <input
                        id="user_password"
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        placeholder={isEditing ? 'Laisser vide pour ne pas changer' : 'Au moins 8 caractères'}
                        className={fieldClasses()}
                    />
                    {errors.password && <p className="mt-1.5 text-sm text-error-500">{errors.password}</p>}
                </div>

                <div>
                    <label htmlFor="user_role" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Rôle
                    </label>
                    {roles.length === 0 ? (
                        <p className="text-sm text-error-500">Aucun rôle créé — créez-en un depuis Rôles d'abord.</p>
                    ) : (
                        <select
                            id="user_role"
                            value={data.role_id}
                            onChange={(e) => setData('role_id', Number(e.target.value))}
                            className={fieldClasses()}
                        >
                            {roles.map((role) => (
                                <option key={role.id} value={role.id}>
                                    {role.name}
                                </option>
                            ))}
                        </select>
                    )}
                    {errors.role_id && <p className="mt-1.5 text-sm text-error-500">{errors.role_id}</p>}
                </div>

                {selectedRole?.scope === 'brand' && (
                    <div>
                        <label htmlFor="user_brand_id" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Marque
                        </label>
                        {brands.length === 0 ? (
                            <p className="text-sm text-error-500">Aucune marque créée — créez-en une depuis Organisation d'abord.</p>
                        ) : (
                            <select
                                id="user_brand_id"
                                value={data.brand_id}
                                onChange={(e) => setData('brand_id', e.target.value)}
                                className={fieldClasses()}
                            >
                                {brands.map((brand) => (
                                    <option key={brand.id} value={brand.id}>
                                        {brand.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        {errors.brand_id && <p className="mt-1.5 text-sm text-error-500">{errors.brand_id}</p>}
                    </div>
                )}

                {selectedRole?.scope === 'store' && (
                    <div>
                        <label htmlFor="user_store_id" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Boutique
                        </label>
                        {stores.length === 0 ? (
                            <p className="text-sm text-error-500">Aucune boutique créée — créez-en une depuis Organisation d'abord.</p>
                        ) : (
                            <select
                                id="user_store_id"
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
                        )}
                        {errors.store_id && <p className="mt-1.5 text-sm text-error-500">{errors.store_id}</p>}
                    </div>
                )}

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={close}>
                        Annuler
                    </Button>
                    <Button disabled={processing}>{processing ? 'Enregistrement...' : isEditing ? 'Enregistrer' : 'Créer'}</Button>
                </div>
            </form>
        </Modal>
    );
}

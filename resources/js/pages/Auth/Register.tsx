import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import Button from '../../components/ui/button/Button';

type Field = 'organization_name' | 'store_name' | 'name' | 'email' | 'password' | 'password_confirmation';

const inputClass =
    'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

export default function Register() {
    const { data, setData, post, processing, errors } = useForm<Record<Field, string>>({
        organization_name: '',
        store_name: '',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/register');
    };

    const field = (id: Field, label: string, type = 'text', hint?: string) => (
        <div>
            <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                {label}
            </label>
            <input id={id} type={type} value={data[id]} onChange={(e) => setData(id, e.target.value)} className={inputClass} />
            {hint && !errors[id] && <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{hint}</p>}
            {errors[id] && <p className="mt-1.5 text-sm text-error-500">{errors[id]}</p>}
        </div>
    );

    return (
        <>
            <Head title="Créer un compte entreprise" />

            <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-10 dark:bg-gray-900">
                <div className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 shadow-theme-md dark:border-gray-800 dark:bg-gray-900">
                    <div className="mb-8 text-center">
                        <h1 className="text-xl font-bold text-gray-900 dark:text-white">Créer un compte entreprise</h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Votre entreprise, votre premier établissement et votre accès administrateur.
                        </p>
                    </div>

                    <form onSubmit={submit} className="space-y-5">
                        <fieldset className="space-y-4">
                            <legend className="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">Entreprise</legend>
                            {field('organization_name', "Nom de l'entreprise")}
                            {field('store_name', 'Premier établissement', 'text', 'Vous pourrez en ajouter d’autres plus tard.')}
                        </fieldset>

                        <fieldset className="space-y-4">
                            <legend className="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">Votre compte</legend>
                            {field('name', 'Nom complet')}
                            {field('email', 'Email', 'email')}
                            {field('password', 'Mot de passe', 'password')}
                            {field('password_confirmation', 'Confirmer le mot de passe', 'password')}
                        </fieldset>

                        <Button className="w-full justify-center" disabled={processing}>
                            {processing ? 'Création...' : 'Créer mon compte'}
                        </Button>

                        <p className="text-center text-sm text-gray-500 dark:text-gray-400">
                            Déjà un compte ?{' '}
                            <Link href="/login" className="font-medium text-brand-500 hover:underline">
                                Se connecter
                            </Link>
                        </p>
                    </form>
                </div>
            </div>
        </>
    );
}

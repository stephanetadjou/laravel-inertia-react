import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import Button from '../../components/ui/button/Button';
import MenentiaLogo from '../../components/common/MenentiaLogo';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/login');
    };

    return (
        <>
            <Head title="Connexion" />

            <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 dark:bg-gray-900">
                <div className="w-full max-w-sm rounded-2xl border border-gray-200 bg-white p-8 shadow-theme-md dark:border-gray-800 dark:bg-gray-900">
                    <div className="mb-8 text-center">
                        <h1 className="flex justify-center">
                            <MenentiaLogo symbolClassName="h-10 w-10" wordmarkClassName="text-[28px]" />
                        </h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Connecter · Fidéliser · Développer</p>
                    </div>

                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Email
                            </label>
                            <input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90"
                                autoFocus
                            />
                            {errors.email && <p className="mt-1.5 text-sm text-error-500">{errors.email}</p>}
                        </div>

                        <div>
                            <label htmlFor="password" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Mot de passe
                            </label>
                            <input
                                id="password"
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90"
                            />
                            {errors.password && <p className="mt-1.5 text-sm text-error-500">{errors.password}</p>}
                        </div>

                        <Button className="w-full justify-center" disabled={processing}>
                            {processing ? 'Connexion...' : 'Se connecter'}
                        </Button>

                        <p className="text-center text-sm text-gray-500 dark:text-gray-400">
                            Nouvelle entreprise ?{' '}
                            <Link href="/register" className="font-medium text-brand-text hover:underline">
                                Créer un compte
                            </Link>
                        </p>
                    </form>
                </div>
            </div>
        </>
    );
}

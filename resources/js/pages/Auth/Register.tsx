import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import Button from '../../components/ui/button/Button';
import MenentiaLogo from '../../components/common/MenentiaLogo';

type Field = 'organization_name' | 'sector' | 'store_name' | 'name' | 'email' | 'password' | 'password_confirmation';

interface Sector {
    key: string;
    label: string;
    description: string;
    modules: string[];
    module_labels: string[];
}

interface RegisterProps {
    sectors: Sector[];
    [key: string]: unknown;
}

interface RegisterForm {
    organization_name: string;
    sector: string;
    store_name: string;
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
}

const inputClass =
    'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

export default function Register() {
    const { sectors } = usePage<RegisterProps>().props;
    const { data, setData, post, processing, errors } = useForm<RegisterForm>({
        organization_name: '',
        sector: '',
        store_name: '',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    // Un seul choix : le secteur prépare modules, étapes de suivi et réponses automatiques.
    const sector = sectors.find((s) => s.key === data.sector);

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
                <div className="w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-8 shadow-theme-md dark:border-gray-800 dark:bg-gray-900">
                    <div className="mb-8 text-center">
                        <div className="mb-5 flex justify-center">
                            <MenentiaLogo symbolClassName="h-10 w-10" wordmarkClassName="text-[28px]" />
                        </div>
                        <h1 className="text-xl font-bold text-ink-strong dark:text-white">Créer un compte entreprise</h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Connaissez vos clients. Parlez-leur au bon moment. Faites grandir votre activité.
                        </p>
                    </div>

                    <form onSubmit={submit} className="space-y-5">
                        <fieldset className="space-y-4">
                            <legend className="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">Entreprise</legend>
                            {field('organization_name', "Nom de l'entreprise")}
                            <div role="radiogroup" aria-labelledby="sector-label">
                                <p id="sector-label" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Secteur d’activité
                                </p>
                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    {sectors.map((option) => {
                                        const checked = data.sector === option.key;
                                        return (
                                            <label
                                                key={option.key}
                                                className={`flex cursor-pointer flex-col gap-1 rounded-xl border p-3 text-sm transition-colors ${
                                                    checked ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500 dark:bg-white/10' : 'border-gray-200 hover:border-gray-300 dark:border-gray-700'
                                                }`}
                                            >
                                                <input type="radio" name="sector" value={option.key} checked={checked} onChange={() => setData('sector', option.key)} className="sr-only" />
                                                <span className="font-semibold text-gray-800 dark:text-white/90">{option.label}</span>
                                                <span className="text-xs leading-snug text-gray-500 dark:text-gray-400">{option.description}</span>
                                            </label>
                                        );
                                    })}
                                </div>
                                {sector && sector.module_labels.length > 0 && (
                                    <p className="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                                        Prêt à l’emploi : {sector.module_labels.join(', ')}. Tout reste modifiable dans Paramètres › Modules.
                                    </p>
                                )}
                                {errors.sector && <p className="mt-1.5 text-sm text-error-500">Choisissez votre secteur d’activité.</p>}
                            </div>
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
                            <Link href="/login" className="font-medium text-brand-text hover:underline">
                                Se connecter
                            </Link>
                        </p>
                    </form>
                </div>
            </div>
        </>
    );
}

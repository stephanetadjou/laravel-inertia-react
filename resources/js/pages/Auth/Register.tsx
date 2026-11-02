import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import Button from '../../components/ui/button/Button';
import MenentiaLogo from '../../components/common/MenentiaLogo';
import { ModuleSettingsFields, ModuleSettingsValues } from '../Settings/ModuleSettingsFields';

type Field = 'organization_name' | 'sector' | 'store_name' | 'name' | 'email' | 'password' | 'password_confirmation';

interface Sector {
    key: string;
    label: string;
    description: string;
    modules: string[];
}

interface RegisterProps {
    sectors: Sector[];
    questions: { module: string; question: string }[];
    moduleDefaults: ModuleSettingsValues;
    beneficiaryLabels: { value: string; label: string }[];
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
    answers: Record<string, boolean>;
    settings: ModuleSettingsValues;
}

const inputClass =
    'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

export default function Register() {
    const { sectors, questions, moduleDefaults, beneficiaryLabels } = usePage<RegisterProps>().props;
    const { data, setData, post, processing, errors } = useForm<RegisterForm>({
        organization_name: '',
        sector: '',
        store_name: '',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        answers: {},
        settings: moduleDefaults,
    });

    // Le secteur pré-coche les réponses ; chacune reste modifiable.
    const chooseSector = (sector: Sector) =>
        setData((d) => ({ ...d, sector: sector.key, answers: Object.fromEntries(questions.map((q) => [q.module, sector.modules.includes(q.module)])) }));
    const sector = sectors.find((s) => s.key === data.sector);
    const enabledModules = sector ? [...sector.modules.filter((m) => !(m in data.answers)), ...Object.keys(data.answers).filter((m) => data.answers[m])] : [];

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
                                <div className="space-y-2">
                                    {sectors.map((sector) => {
                                        const checked = data.sector === sector.key;
                                        return (
                                            <label
                                                key={sector.key}
                                                className={`flex cursor-pointer gap-3 rounded-lg border px-3 py-2.5 text-sm transition-colors ${
                                                    checked ? 'border-brand-500 bg-brand-50 dark:bg-white/10' : 'border-gray-200 hover:border-gray-300 dark:border-gray-700'
                                                }`}
                                            >
                                                <input
                                                    type="radio"
                                                    name="sector"
                                                    value={sector.key}
                                                    checked={checked}
                                                    onChange={() => chooseSector(sector)}
                                                    className="mt-1 accent-brand-500"
                                                />
                                                <span>
                                                    <span className="block font-medium text-gray-800 dark:text-white/90">{sector.label}</span>
                                                    <span className="block text-xs text-gray-500 dark:text-gray-400">{sector.description}</span>
                                                </span>
                                            </label>
                                        );
                                    })}
                                </div>
                                {errors.sector && <p className="mt-1.5 text-sm text-error-500">Choisissez votre secteur d’activité.</p>}
                            </div>
                            {field('store_name', 'Premier établissement', 'text', 'Vous pourrez en ajouter d’autres plus tard.')}
                        </fieldset>

                        {sector && (
                            <fieldset className="space-y-3">
                                <legend className="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">Comment travaillez-vous ?</legend>
                                {questions.map((q) => (
                                    <div key={q.module} className="flex items-center justify-between gap-3 text-sm">
                                        <span className="text-gray-700 dark:text-gray-300">{q.question}</span>
                                        <span className="flex shrink-0 overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700" role="radiogroup" aria-label={q.question}>
                                            {[true, false].map((value) => (
                                                <button
                                                    key={String(value)}
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={data.answers[q.module] === value}
                                                    onClick={() => setData('answers', { ...data.answers, [q.module]: value })}
                                                    className={`px-3 py-1 text-xs font-medium ${data.answers[q.module] === value ? 'bg-brand-500 text-white' : 'text-gray-600 dark:text-gray-300'}`}
                                                >
                                                    {value ? 'Oui' : 'Non'}
                                                </button>
                                            ))}
                                        </span>
                                    </div>
                                ))}
                                <details className="rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                    <summary className="cursor-pointer font-medium text-gray-700 dark:text-gray-300">Réglages de base (modifiables plus tard)</summary>
                                    <div className="mt-3">
                                        <ModuleSettingsFields
                                            enabled={enabledModules}
                                            values={data.settings}
                                            onChange={(v) => setData('settings', v)}
                                            beneficiaryLabels={beneficiaryLabels}
                                            errors={errors as Record<string, string>}
                                            className="space-y-5"
                                        />
                                    </div>
                                </details>
                            </fieldset>
                        )}

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

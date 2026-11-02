import { Link, router } from '@inertiajs/react';

/*
 * « Pour bien démarrer » (accueil) : les premières étapes selon les modules
 * de l'entreprise, cochées toutes seules quand c'est fait ; chaque étape
 * mène à l'écran qui la fait. Fermable à tout moment.
 */

export interface OnboardingData {
    steps: { key: string; label: string; help: string; url: string; done: boolean }[];
    done: number;
    total: number;
}

export default function OnboardingChecklist({ data }: { data: OnboardingData }) {
    const next = data.steps.find((s) => !s.done);

    return (
        <section className="mdash-panel" style={{ marginTop: 12 }} aria-labelledby="onboarding-title">
            <div className="mdash-ph">
                <div>
                    <h2 id="onboarding-title">Pour bien démarrer</h2>
                    <span className="mdash-sub">
                        {data.done} étape{data.done > 1 ? 's' : ''} sur {data.total} · tout se règle ici, sans intervention technique
                    </span>
                </div>
                <button type="button" className="mdash-btn ghost sm" onClick={() => router.post('/onboarding/dismiss', {}, { preserveScroll: true })}>
                    Masquer
                </button>
            </div>
            <div className="mb-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-valuenow={data.done} aria-valuemin={0} aria-valuemax={data.total}>
                <div className="h-full rounded-full bg-brand-500" style={{ width: `${Math.round((data.done / data.total) * 100)}%` }} />
            </div>
            <ol className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {data.steps.map((s) => (
                    <li key={s.key}>
                        <Link
                            href={s.url}
                            className={`flex h-full gap-2 rounded-lg border px-3 py-2 text-sm ${s.done ? 'border-gray-100 text-gray-400 dark:border-gray-800' : s.key === next?.key ? 'border-brand-300 bg-brand-50 dark:bg-white/10' : 'border-gray-200 hover:border-brand-300 dark:border-gray-700'}`}
                        >
                            <span aria-hidden="true" className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs ${s.done ? 'bg-success-500 text-white' : 'border border-gray-300'}`}>
                                {s.done ? '✓' : ''}
                            </span>
                            <span>
                                <b className={s.done ? 'line-through' : ''}>{s.label}</b>
                                {!s.done && <span className="block text-xs text-gray-500 dark:text-gray-400">{s.help}</span>}
                            </span>
                        </Link>
                    </li>
                ))}
            </ol>
        </section>
    );
}

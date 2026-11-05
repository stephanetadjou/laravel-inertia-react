import { Link, router } from "@inertiajs/react";

/*
 * « Pour bien démarrer » (accueil) : les premières étapes selon les modules
 * de l'entreprise, cochées toutes seules quand c'est fait ; chaque étape
 * mène à l'écran qui la fait. Fermable à tout moment.
 */

export interface OnboardingData {
    steps: {
        key: string;
        label: string;
        help: string;
        url: string;
        done: boolean;
    }[];
    done: number;
    total: number;
}

export default function OnboardingChecklist({
    data,
}: {
    data: OnboardingData;
}) {
    const next = data.steps.find((s) => !s.done);

    return (
        <section
            className="mdash-panel nx-onboarding"
            aria-labelledby="onboarding-title"
        >
            <div className="mdash-ph">
                <div>
                    <h2 id="onboarding-title">Vos premiers pas</h2>
                    <span className="mdash-sub">
                        {data.done} étape{data.done > 1 ? "s" : ""} sur{" "}
                        {data.total} · tout se règle ici, sans intervention
                        technique
                    </span>
                </div>
                <button
                    type="button"
                    className="mdash-btn ghost sm"
                    onClick={() =>
                        router.post(
                            "/onboarding/dismiss",
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Masquer
                </button>
            </div>
            <div
                className="mb-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10"
                role="progressbar"
                aria-valuenow={data.done}
                aria-valuemin={0}
                aria-valuemax={data.total}
            >
                <div
                    className="h-full rounded-full bg-brand-500"
                    style={{
                        width: `${Math.round((data.done / data.total) * 100)}%`,
                    }}
                />
            </div>
            <ol className="nx-steps">
                {data.steps
                    .map((s, i) => ({ s, i }))
                    .sort((a, b) => Number(a.s.done) - Number(b.s.done))
                    .map(({ s, i }) => (
                        <li key={s.key}>
                            <Link
                                href={s.url}
                                className={`nx-step ${s.done ? "done" : s.key === next?.key ? "next" : ""}`}
                            >
                                <span
                                    className="nx-step-num"
                                    aria-hidden="true"
                                >
                                    {i + 1}
                                </span>
                                <span
                                    className="nx-step-icon"
                                    aria-hidden="true"
                                >
                                    {s.done ? "✓" : i + 1}
                                </span>
                                <b>{s.label}</b>
                                <span className="nx-step-help">{s.help}</span>
                                <span className="nx-step-go">
                                    {s.done ? "Fait" : "C’est parti →"}
                                </span>
                            </Link>
                        </li>
                    ))}
            </ol>
        </section>
    );
}

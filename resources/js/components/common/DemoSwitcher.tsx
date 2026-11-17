import { router, usePage } from '@inertiajs/react';

/*
 * Phase de test : liste déroulante de la barre du haut pour passer d'une
 * entreprise de démo à l'autre (une par secteur) sans se reconnecter.
 * N'apparaît que si DEMO_SWITCHER=true et pour un compte de démo.
 */

interface DemoOption {
    slug: string;
    sector: string;
    label: string;
    name: string;
}

export default function DemoSwitcher() {
    const demo = usePage<{ demoSwitcher?: { current: string | null; options: DemoOption[] } | null }>().props.demoSwitcher;

    if (!demo || demo.options.length === 0) {
        return null;
    }

    return (
        <label
            className="hidden h-9 items-center gap-2 rounded-full border border-cyan-200 bg-cyan-50/70 pr-1 pl-1.5 text-[13px] text-[#0B1F3A] hover:border-cyan-400 sm:flex dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-100"
            title="Changer d’entreprise de démonstration"
        >
            <span className="nx-hex flex h-6 w-[22px] items-center justify-center bg-gradient-to-br from-cyan-400 to-blue-600 text-[10px] font-bold text-white" aria-hidden="true">
                D
            </span>
            <span className="text-[10.5px] font-bold tracking-wider text-cyan-700 uppercase dark:text-cyan-300">Démo</span>
            <select
                aria-label="Entreprise de démonstration"
                value={demo.current ?? ''}
                onChange={(e) => router.post('/demo/switch', { slug: e.target.value })}
                className="max-w-[210px] cursor-pointer truncate rounded-full border-0 bg-white py-1 pr-7 pl-3 text-[13px] font-medium shadow-sm focus:ring-2 focus:ring-cyan-300 dark:bg-gray-900"
            >
                {!demo.options.some((o) => o.slug === demo.current) && <option value="">Choisir un secteur…</option>}
                {demo.options.map((o) => (
                    <option key={o.slug} value={o.slug}>
                        {o.label} · {o.name}
                    </option>
                ))}
            </select>
        </label>
    );
}

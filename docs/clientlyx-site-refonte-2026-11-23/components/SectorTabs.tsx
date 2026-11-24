'use client';

import { useState } from 'react';
import type { Family } from '@/lib/site';
import { Icon } from './Icon';

// Les 5 secteurs du lancement en onglets : phrase-choc, problème, 3 gains, lien.
export function SectorTabs({ families, pitch, trialHref }: { families: Family[]; pitch: Record<string, string>; trialHref: string }) {
  const [active, setActive] = useState(families[0].slug);
  const f = families.find((x) => x.slug === active) ?? families[0];

  return (
    <div className="flex flex-col gap-6">
      <div role="tablist" aria-label="Secteurs" className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:justify-center sm:px-0">
        {families.map((x) => (
          <button
            key={x.slug}
            role="tab"
            type="button"
            aria-selected={x.slug === f.slug}
            onClick={() => setActive(x.slug)}
            className={`inline-flex shrink-0 items-center gap-2 rounded-full px-4 py-2.5 text-[15px] font-semibold transition ${x.slug === f.slug ? 'bg-navy text-white' : 'bg-white text-navy ring-1 ring-line hover:ring-navy/40'}`}
          >
            <Icon name={x.icon} className="h-[18px] w-[18px]" /> {x.short}
          </button>
        ))}
      </div>

      <div role="tabpanel" className="grid gap-8 rounded-3xl border border-line bg-white p-6 sm:p-10 lg:grid-cols-[1.1fr_1fr] lg:items-center">
        <div className="flex flex-col gap-4">
          <p className="text-[13px] font-bold uppercase tracking-[0.14em] text-green-text">{f.name}</p>
          <h3 className="font-display text-[1.75rem] font-bold leading-tight text-navy sm:text-[2rem]">{pitch[f.slug] ?? f.message}</h3>
          <p className="rounded-xl bg-warn-soft px-4 py-3 text-[15px] font-semibold leading-snug text-[#7C2D12]">{f.problem.headline}</p>
          <p className="text-[14px] text-muted">{f.trades.slice(0, 5).join(' · ')}…</p>
        </div>
        <div className="flex flex-col gap-4">
          <ul className="flex flex-col gap-3">
            {f.gains.map((g) => (
              <li key={g} className="flex items-start gap-3 rounded-2xl bg-soft p-4 text-[15.5px] leading-snug text-ink">
                <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-green text-white"><Icon name="check" className="h-4 w-4" strokeWidth={3} /></span>
                {g}
              </li>
            ))}
          </ul>
          <div className="flex flex-col gap-3 sm:flex-row">
            <a href={trialHref} className="inline-flex h-12 items-center justify-center gap-2 rounded-lg bg-green px-6 font-semibold text-white hover:brightness-110">Essayer gratuitement</a>
            <a href={`/activites/${f.slug}`} className="inline-flex h-12 items-center justify-center gap-2 rounded-lg border border-line px-6 font-semibold text-navy hover:border-navy/40">Voir la solution {f.short} <Icon name="arrow" className="h-4 w-4" /></a>
          </div>
        </div>
      </div>
    </div>
  );
}

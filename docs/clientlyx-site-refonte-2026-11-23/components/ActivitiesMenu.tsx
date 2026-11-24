'use client';

import { useState } from 'react';
import type { Family } from '@/lib/site';
import { Icon } from './Icon';

// Menu « Activités » : à gauche les secteurs, à droite les métiers du secteur
// survolé (ou touché, ou atteint au clavier), en liste compacte sur 2 colonnes.
export function ActivitiesMenu({ families }: { families: Family[] }) {
  const [active, setActive] = useState(families[0]?.slug);
  const current = families.find((f) => f.slug === active) ?? families[0];

  return (
    <div className="grid w-[760px] max-w-[90vw] grid-cols-[280px_1fr] gap-4">
      <div className="flex flex-col gap-1">
        {families.map((f) => (
          <a
            key={f.slug}
            href={`/activites/${f.slug}`}
            onMouseEnter={() => setActive(f.slug)}
            onFocus={() => setActive(f.slug)}
            onTouchStart={() => setActive(f.slug)}
            className={`flex items-center gap-3 rounded-xl p-2.5 hover:bg-soft ${f.slug === current?.slug ? 'bg-soft' : ''}`}
          >
            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-blue-soft text-navy ring-1 ring-line">
              <Icon name={f.icon} className="h-[18px] w-[18px]" />
            </span>
            <span className="text-[15px] font-semibold leading-tight text-navy">{f.name}</span>
          </a>
        ))}
        <a href="/activites" className="flex items-center gap-2 rounded-xl p-2.5 text-[14px] font-semibold text-green-text hover:bg-soft">
          Tous les secteurs <Icon name="arrow" className="h-4 w-4" />
        </a>
      </div>

      {current && (
        <div className="rounded-xl bg-soft p-4">
          <p className="text-xs font-bold uppercase tracking-[0.16em] text-muted">{current.name}</p>
          <ul className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-[14px] text-navy">
            {current.trades.map((t) => (
              <li key={t} className="flex items-start gap-2">
                <span aria-hidden className="mt-[7px] h-1.5 w-1.5 shrink-0 rounded-full bg-green" />
                {t}
              </li>
            ))}
          </ul>
          <a href={`/activites/${current.slug}`} className="mt-4 inline-flex items-center gap-2 text-[14px] font-semibold text-green-text">
            Voir la page {current.short ?? current.name} <Icon name="arrow" className="h-4 w-4" />
          </a>
        </div>
      )}
    </div>
  );
}

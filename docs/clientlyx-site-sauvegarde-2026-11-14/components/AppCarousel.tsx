'use client';

import { useState } from 'react';
import { Icon } from './Icon';

// Carrousel de vraies captures de l'application (dossier public/images/app).
const slides = [
  { src: '/images/app/ma-journee.webp', tab: 'Ma journée', alt: 'Écran Ma journée : clients du jour, relances et applications', caption: 'Chaque matin, les clients du jour et les actions qui vous attendent.' },
  { src: '/images/app/discussions.webp', tab: 'Discussions', alt: 'Écran Discussions : messages WhatsApp des clients', caption: 'Tous les messages WhatsApp de vos clients, au même endroit pour toute l’équipe.' },
  { src: '/images/app/fiches-clients.webp', tab: 'Fiches clients', alt: 'Écran Fiches clients : liste des clients avec étape, source et consentement', caption: 'Une fiche par client, avec son étape, la pub d’où il vient et son consentement WhatsApp.' },
  { src: '/images/app/actions-du-jour.webp', tab: 'Actions du jour', alt: 'Écran Actions du jour : demandes à traiter et clients à relancer', caption: 'Les relances à faire, classées pour vous : demandes, rendez-vous, clients à faire revenir.' },
];

export function AppCarousel() {
  const [i, setI] = useState(0);
  const go = (n: number) => setI((n + slides.length) % slides.length);
  const s = slides[i];
  return (
    <div className="flex flex-col items-center gap-6">
      <div role="tablist" aria-label="Écrans de l’application" className="flex flex-wrap justify-center gap-1.5 rounded-xl bg-white/10 p-1.5">
        {slides.map((x, k) => (
          <button key={x.tab} type="button" role="tab" aria-selected={k === i} onClick={() => setI(k)}
            className={`rounded-lg px-4 py-2 text-[15px] font-semibold transition ${k === i ? 'bg-white text-navy' : 'text-white/85 hover:text-white'}`}>
            {x.tab}
          </button>
        ))}
      </div>
      <div className="flex w-full items-center gap-3 sm:gap-4">
        <button type="button" aria-label="Écran précédent" onClick={() => go(i - 1)} className="hidden h-12 w-12 shrink-0 place-items-center rounded-full border border-white/25 text-white hover:border-white/60 sm:grid">
          <Icon name="chevron" className="h-5 w-5 rotate-90" />
        </button>
        <figure className="min-w-0 flex-1 overflow-hidden rounded-2xl bg-white shadow-[0_16px_40px_rgba(0,0,0,.25)]">
          <div className="flex items-center gap-2 border-b border-line bg-[#F5F2EE] px-4 py-2.5">
            <span className="h-2.5 w-2.5 rounded-full bg-green" /><span className="h-2.5 w-2.5 rounded-full bg-sun" /><span className="h-2.5 w-2.5 rounded-full bg-blue" />
            <span className="ml-3 rounded-md bg-white px-3 py-1 text-[12px] text-muted">app.clientlyx.com</span>
          </div>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={s.src} alt={s.alt} className="block aspect-[16/7.6] w-full object-cover object-left-top" loading="lazy" />
        </figure>
        <button type="button" aria-label="Écran suivant" onClick={() => go(i + 1)} className="hidden h-12 w-12 shrink-0 place-items-center rounded-full border border-white/25 text-white hover:border-white/60 sm:grid">
          <Icon name="chevron" className="h-5 w-5 -rotate-90" />
        </button>
      </div>
      <p className="max-w-2xl text-center text-[15px] text-[#E2D8EC]" aria-live="polite">{s.caption}</p>
      <div className="flex gap-2 sm:hidden">
        {slides.map((x, k) => <button key={x.tab} type="button" aria-label={`Voir ${x.tab}`} onClick={() => setI(k)} className={`h-2.5 w-2.5 rounded-full ${k === i ? 'bg-white' : 'bg-white/30'}`} />)}
      </div>
    </div>
  );
}

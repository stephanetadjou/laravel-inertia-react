import { launchFamilies as families, featureGroups, links, objections, plans, problems } from '@/lib/site';
import { Icon } from './Icon';
import { Button, Container, SectionTitle } from './ui';

export function FamilyStrip() {
  return (
    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
      {families.map((f) => (
        <a key={f.slug} href={`/activites/${f.slug}`} className="group flex min-w-0 flex-col gap-4 rounded-2xl border border-line bg-white p-6 transition hover:-translate-y-0.5 hover:border-blue/40 hover:shadow-card">
          <div className="flex items-center gap-3">
            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-blue-soft text-blue-text">
              <Icon name={f.icon} className="h-5 w-5" />
            </span>
            <div className="min-w-0">
              <span className="block font-display text-[18px] font-bold leading-tight text-navy">{f.name}</span>
              <span className="block truncate text-[13px] text-muted">{f.trades.slice(0, 4).join(', ')}…</span>
            </div>
          </div>
          <p className="rounded-xl bg-warn-soft px-3.5 py-2.5 text-[14px] font-semibold leading-snug text-[#7C2D12]">{f.problem.headline}</p>
          <ul className="flex flex-col gap-2">
            {f.gains.map((g) => (
              <li key={g} className="flex items-start gap-2 text-[14.5px] leading-snug text-ink">
                <Icon name="check" className="mt-0.5 h-4 w-4 shrink-0 text-green-text" strokeWidth={2.6} /> {g}
              </li>
            ))}
          </ul>
          <span className="mt-auto inline-flex items-center gap-1 pt-1 text-[14px] font-semibold text-blue-text">
            Découvrir la solution <Icon name="arrow" className="h-4 w-4 transition group-hover:translate-x-0.5" />
          </span>
        </a>
      ))}
    </div>
  );
}

export function ProblemGrid() {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {problems.map((p) => (
        <div key={p.title} className="flex flex-col gap-3 rounded-2xl bg-soft p-6">
          <span className="grid h-11 w-11 place-items-center rounded-xl bg-warn-soft text-warn">
            <Icon name={p.icon} className="h-5 w-5" />
          </span>
          <h3 className="font-display text-[19px] font-bold leading-tight text-navy">{p.title}</h3>
          <p className="text-[15px] leading-relaxed text-muted">{p.text}</p>
          <p className="mt-auto flex items-start gap-2 border-t border-line pt-3 text-[14px] font-semibold text-green-text">
            <Icon name="check" className="mt-0.5 h-4 w-4 shrink-0" strokeWidth={2.6} /> {p.stat}
          </p>
        </div>
      ))}
    </div>
  );
}

export function PricingCards() {
  return (
    <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-5">
      {plans.map((p) => (
        <div key={p.id} className={`relative flex flex-col gap-5 rounded-3xl p-5 ${p.highlight ? 'bg-navy text-white shadow-float' : 'border border-line bg-white'}`}>
          {p.highlight && <span className="absolute -top-3 right-6 rounded-full bg-green px-3 py-1 text-xs font-bold text-white">Le plus choisi</span>}
          <div>
            <p className={`font-display text-[22px] font-bold ${p.highlight ? 'text-white' : 'text-navy'}`}>{p.name}</p>
            <p className={`text-[14.5px] ${p.highlight ? 'text-[#D3C7DF]' : 'text-muted'}`}>{p.who}</p>
          </div>
          <div>
            <p className={`font-display text-[30px] font-bold leading-none tabular-nums ${p.highlight ? 'text-white' : 'text-navy'}`}>
              {p.price} {/\d/.test(p.price) && <span className={`mt-1.5 block font-sans text-[13px] font-medium ${p.highlight ? 'text-[#D3C7DF]' : 'text-muted'}`}>FCFA / mois</span>}
            </p>
            {p.price !== '0' && /\d/.test(p.priceCm) && <p className={`mt-2 text-[13px] font-semibold ${p.highlight ? 'text-green' : 'text-blue-text'}`}>Cameroun : {p.priceCm} FCFA / mois</p>}
          </div>
          <ul className="flex flex-col gap-2.5">
            {p.features.map((f) => (
              <li key={f} className={`flex items-start gap-2 text-[14px] ${p.highlight ? 'text-[#EEE9F3]' : 'text-ink'}`}>
                <Icon name="check" className={`mt-0.5 h-4 w-4 shrink-0 ${p.highlight ? 'text-green' : 'text-green-text'}`} strokeWidth={2.6} /> {f}
              </li>
            ))}
          </ul>
          <div className={`rounded-2xl p-3.5 ${p.highlight ? 'bg-white/[0.07] ring-1 ring-white/10' : 'bg-[#FFF8EC] ring-1 ring-[#FFD43B]/50'}`}>
            <p className={`mb-2 flex items-center gap-1.5 text-[13px] font-bold uppercase tracking-[0.08em] ${p.highlight ? 'text-sun' : 'text-green-text'}`}>
              <Icon name="mobile" className="h-4 w-4" /> Carte digitale
            </p>
            <ul className="flex flex-col gap-1.5">
              {p.card.map((f) => (
                <li key={f} className={`text-[13px] leading-snug ${p.highlight ? 'text-[#EEE9F3]' : 'text-ink'}`}>{f}</li>
              ))}
            </ul>
          </div>
          <Button href={p.id === 'entreprise' ? '/contact' : links.signupPlan(p.id)} variant={p.highlight ? 'brand' : 'dark'} className="mt-auto w-full">
            {p.id === 'gratuit' ? 'Démarrer' : p.id === 'entreprise' ? 'Nous contacter' : `Essayer ${p.name}`}
          </Button>
        </div>
      ))}
    </div>
  );
}

export function Faq({ limit }: { limit?: number } = {}) {
  return (
    <div className="mx-auto flex w-full max-w-3xl flex-col divide-y divide-line rounded-2xl border border-line bg-white">
      {objections.slice(0, limit).map((o) => (
        <details key={o.q} className="group px-6 py-5">
          <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-display text-[17px] font-bold text-navy [&::-webkit-details-marker]:hidden">
            « {o.q} »
            <Icon name="chevron" className="h-5 w-5 shrink-0 text-muted transition group-open:rotate-180" />
          </summary>
          <p className="pt-3 text-[15.5px] leading-relaxed text-muted">{o.a}</p>
        </details>
      ))}
    </div>
  );
}

export function FeatureIndex() {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
      {featureGroups.map((g) => (
        <a key={g.id} href={`/fonctionnalites#${g.id}`} className="group flex flex-col gap-2 rounded-2xl border border-line bg-white p-5 hover:border-blue/40 hover:shadow-card">
          <Icon name={g.icon} className="h-6 w-6 text-blue-text" />
          <p className="font-display text-[17px] font-bold text-navy">{g.title}</p>
          <p className="text-[14px] leading-snug text-muted">{g.items.map((i) => i.title).slice(0, 3).join(' · ')}</p>
        </a>
      ))}
      <a href="/fonctionnalites" className="flex flex-col justify-center gap-2 rounded-2xl bg-navy p-5 text-white">
        <p className="font-display text-[17px] font-bold">Toutes les fonctionnalités</p>
        <span className="inline-flex items-center gap-1 text-[14px] font-semibold">Voir le détail <Icon name="arrow" className="h-4 w-4" /></span>
      </a>
    </div>
  );
}

export function CtaBand() {
  return (
    <section className="py-20">
      <Container>
        <div className="relative overflow-hidden rounded-[2rem] bg-navy px-6 py-14 sm:px-14">
          <div aria-hidden className="pointer-events-none absolute -right-24 -top-24 h-80 w-80 rounded-full bg-green/25 blur-3xl" />
          <div aria-hidden className="pointer-events-none absolute -bottom-32 left-1/3 h-80 w-80 rounded-full bg-blue/30 blur-3xl" />
          <div className="relative flex flex-col items-start gap-8 lg:flex-row lg:items-center lg:justify-between">
            <SectionTitle
              light
              eyebrow="Offre fondateurs"
              title="Les 30 premières entreprises ont 3 mois offerts."
              lead="Puis 50 % de remise à vie sur l’offre Équipe. Essai de 14 jours sans carte bancaire, installation faite avec vous sur place à Libreville."
            />
            <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row lg:flex-col">
              <Button href={links.trial} variant="brand" size="lg">Créer mon compte gratuit</Button>
              <Button href={links.demo} variant="light" size="lg">Réserver une démo</Button>
            </div>
          </div>
        </div>
      </Container>
    </section>
  );
}

export function TrustRow({ light = false }: { light?: boolean }) {
  const items = [
    { icon: 'wallet', text: 'Paiement Mobile Money' },
    { icon: 'shield', text: 'Essai 14 jours sans carte bancaire' },
    { icon: 'message', text: 'Support en français sur WhatsApp' },
    { icon: 'upload', text: 'Import de votre fichier Excel' },
  ];
  return (
    <ul className={`flex flex-wrap gap-x-6 gap-y-2 text-[14px] font-medium ${light ? 'justify-center text-[#E2D8EC]' : 'text-muted'}`}>
      {items.map((i) => (
        <li key={i.text} className="flex items-center gap-2">
          <Icon name={i.icon} className={`h-4 w-4 ${light ? 'text-green' : 'text-green-text'}`} /> {i.text}
        </li>
      ))}
    </ul>
  );
}

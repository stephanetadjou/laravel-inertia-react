import type { Metadata } from 'next';
import { Icon } from '@/components/Icon';
import { PageHero } from '@/components/PageHero';
import { CtaBand } from '@/components/sections';
import { Container } from '@/components/ui';
import { launchFamilies as families } from '@/lib/site';
import { otherTrades } from '@/lib/families';

export const metadata: Metadata = {
  title: 'Activités',
  description: 'Beauté, santé, sport, formation, agences, immobilier, automobile, services à domicile, événementiel : ClientlyX gère les établissements de services et leurs réservations.',
  alternates: { canonical: '/activites' },
};

export default function Activites() {
  return (
    <>
      <PageHero eyebrow="Activités" title="Le même outil, réglé sur le rythme de votre métier." lead="Une coiffeuse revoit ses clientes toutes les 5 semaines, un garage tous les 5 000 km, un cabinet comptable une fois par an. ClientlyX sait quand chaque client devrait revenir." />
      <section className="py-20">
        <Container className="grid gap-5 md:grid-cols-2">
          {families.map((f) => (
            <a key={f.slug} href={`/activites/${f.slug}`} className="group flex flex-col gap-5 rounded-3xl border border-line bg-white p-7 transition hover:border-blue/40 hover:shadow-card">
              <div className="flex items-center gap-4">
                <span className="grid h-12 w-12 place-items-center rounded-2xl bg-blue-soft text-navy"><Icon name={f.icon} className="h-6 w-6" /></span>
                <div>
                  <h2 className="font-display text-[22px] font-bold text-navy">{f.name}</h2>
                  <p className="text-[14px] text-muted">{f.trades.join(', ')}</p>
                </div>
              </div>
              <p className="font-display text-[19px] font-bold leading-snug text-navy">« {f.message} »</p>
              <dl className="flex flex-col divide-y divide-line rounded-2xl border border-line text-[14.5px]">
                <div className="flex gap-3 p-4"><dt className="w-24 shrink-0 text-[11.5px] font-bold uppercase tracking-wider text-warn">Problème</dt><dd className="min-w-0 text-navy">{f.problem.headline}</dd></div>
                <div className="flex gap-3 p-4"><dt className="w-24 shrink-0 text-[11.5px] font-bold uppercase tracking-wider text-warn">Difficultés</dt><dd className="min-w-0 text-muted">{f.difficulties.map((d) => d.title).join(' · ')}</dd></div>
                <div className="flex gap-3 p-4"><dt className="w-24 shrink-0 text-[11.5px] font-bold uppercase tracking-wider text-green-text">Solution</dt><dd className="min-w-0 text-muted">{f.solutions.map((x) => x.feature).filter((v, i, a) => a.indexOf(v) === i).join(' · ')}</dd></div>
              </dl>
              <span className="inline-flex items-center gap-1.5 font-semibold text-blue-text">Voir la page {f.short} <Icon name="arrow" className="h-4 w-4 transition group-hover:translate-x-0.5" /></span>
            </a>
          ))}
        </Container>
      </section>
      <section className="bg-soft py-20">
        <Container className="flex flex-col gap-8">
          <div className="max-w-2xl">
            <p className="text-[13px] font-bold uppercase tracking-wider text-blue-text">Toutes les activités</p>
            <h2 className="mt-2 font-display text-[30px] font-bold leading-tight text-navy">9 familles d’établissements de services, un même moteur.</h2>
            <p className="mt-3 text-[16px] text-muted">À l’inscription, vous choisissez votre famille puis votre métier : ClientlyX vous propose la configuration de départ. Réservation, collaborateurs, prestations et WhatsApp prennent le vocabulaire de votre métier.</p>
          </div>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {otherTrades.filter((o) => families.some((x) => x.slug === o.family)).map(({ family, label, trades }) => {
              const f = families.find((x) => x.slug === family)!;
              return (
                <a key={label} href={`/activites/${f.slug}`} className="flex flex-col gap-3 rounded-2xl border border-line bg-white p-5 transition hover:border-blue/40">
                  <span className="flex items-center gap-2 text-[15px] font-bold text-navy"><Icon name={f.icon} className="h-4 w-4 text-blue-text" /> {label}</span>
                  <span className="flex flex-wrap gap-2">
                    {trades.map((t) => <span key={t} className="rounded-full bg-soft px-3 py-1 text-[14px] font-medium text-navy">{t}</span>)}
                  </span>
                </a>
              );
            })}
          </div>
          <p className="text-[14px] text-muted">Vous avez déjà votre logiciel de caisse ou de gestion et voulez seulement comprendre vos clients ? Choisissez « Analyser mes données clients » : vous importez vos ventes et ClientlyX calcule segments RFM, clients à risque et relances WhatsApp.</p>
        </Container>
      </section>
      <CtaBand />
    </>
  );
}

import type { Metadata } from 'next';
import { Icon } from '@/components/Icon';
import { PageHero } from '@/components/PageHero';
import { CtaBand } from '@/components/sections';
import { Container } from '@/components/ui';
import { families } from '@/lib/site';

export const metadata: Metadata = {
  title: 'Activités',
  description: 'Beauté, santé, sport, services techniques et services professionnels : Menentia s’adapte au rythme de vos clients.',
  alternates: { canonical: '/activites' },
};

export default function Activites() {
  return (
    <>
      <PageHero eyebrow="Activités" title="Le même outil, réglé sur le rythme de votre métier." lead="Une coiffeuse revoit ses clientes toutes les 5 semaines, un garage tous les 5 000 km, un cabinet comptable une fois par an. Menentia sait quand chaque client devrait revenir." />
      <section className="py-20">
        <Container className="grid gap-5 md:grid-cols-2">
          {families.map((f) => (
            <a key={f.slug} href={`/activites/${f.slug}`} className="group flex flex-col gap-5 rounded-3xl border border-line bg-white p-7 transition hover:border-blue/40 hover:shadow-card">
              <div className="flex items-center gap-4">
                <span className="grid h-12 w-12 place-items-center rounded-2xl bg-gradient-to-br from-blue to-green text-white"><Icon name={f.icon} className="h-6 w-6" /></span>
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
      <CtaBand />
    </>
  );
}

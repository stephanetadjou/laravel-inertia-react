import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { Icon } from '@/components/Icon';
import { CtaBand } from '@/components/sections';
import { Button, Container } from '@/components/ui';
import { pillarPages } from '@/lib/pillars';
import { links } from '@/lib/site';

// Page d'un pilier ClientlyX (accueil) : accroche, problème, fonctions réelles, secteurs, appel à l'action.

export function generateStaticParams() {
  return pillarPages.map((p) => ({ slug: p.slug }));
}

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const p = pillarPages.find((x) => x.slug === slug);
  if (!p) return {};
  return { title: `${p.tag} : ${p.title}`, description: p.hook, alternates: { canonical: `/piliers/${p.slug}` } };
}

export default async function PillarPage({ params }: Props) {
  const { slug } = await params;
  const p = pillarPages.find((x) => x.slug === slug);
  if (!p) notFound();
  const others = pillarPages.filter((x) => x.slug !== p.slug);

  return (
    <>
      <section className="relative overflow-hidden border-b border-line bg-[#FAFAF9]">
        <div aria-hidden className="pointer-events-none absolute inset-0 bg-[radial-gradient(45%_55%_at_78%_40%,rgba(91,58,134,.08),transparent)]" />
        <Container className="relative flex flex-col gap-6 py-14 lg:py-20">
          <nav aria-label="Fil d’Ariane" className="flex items-center gap-2 text-[14px] text-muted">
            <a href="/fonctionnalites" className="hover:text-navy">Fonctionnalités</a>
            <span aria-hidden>/</span>
            <span className="inline-flex items-center gap-1.5 font-semibold text-navy">
              <Icon name={p.icon} className="h-4 w-4 text-blue-text" /> {p.tag}
            </span>
          </nav>
          <p className="text-[13px] font-bold uppercase tracking-[0.18em] text-green-text">{p.tag}</p>
          <h1 className="max-w-3xl text-balance font-display text-[2.4rem] font-bold leading-[1.05] tracking-[-0.025em] text-navy sm:text-[3.2rem]">{p.title}</h1>
          <p className="max-w-2xl text-[1.1rem] leading-relaxed text-muted">{p.hook}</p>
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Button href="/contact" variant="dark" size="lg">Réserver une démonstration</Button>
            <a href={links.signup} className="inline-flex items-center gap-1.5 whitespace-nowrap px-2 font-semibold text-blue-text">
              ou commencer l’essai gratuit <Icon name="arrow" className="h-4 w-4" />
            </a>
          </div>
        </Container>
      </section>

      <section className="py-16">
        <Container className="grid gap-8 lg:grid-cols-[1fr_1.4fr] lg:items-start">
          <p className="text-[13px] font-bold uppercase tracking-[0.18em] text-warn">Le problème</p>
          <div className="flex flex-col gap-4">
            <h2 className="text-balance font-display text-[1.8rem] font-bold leading-[1.15] text-navy sm:text-[2.2rem]">{p.problem.headline}</h2>
            <p className="max-w-[62ch] text-[1.06rem] leading-relaxed text-muted">{p.problem.text}</p>
          </div>
        </Container>
      </section>

      <section className="border-y border-line bg-soft py-16">
        <Container className="flex flex-col gap-8">
          <div className="flex flex-col gap-2">
            <p className="text-[13px] font-bold uppercase tracking-[0.18em] text-green-text">La solution ClientlyX</p>
            <h2 className="font-display text-[1.8rem] font-bold text-navy sm:text-[2.2rem]">Ce que vous avez dans l’application</h2>
          </div>
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {p.features.map((f) => (
              <li key={f.title} className="flex flex-col gap-2 rounded-2xl border border-line bg-white p-6">
                <span className="grid h-9 w-9 place-items-center rounded-lg bg-green-soft text-green-text">
                  <Icon name="check" className="h-[18px] w-[18px]" />
                </span>
                <h3 className="font-display text-[1.1rem] font-bold text-navy">{f.title}</h3>
                <p className="text-[15px] leading-relaxed text-muted">{f.text}</p>
              </li>
            ))}
          </ul>
        </Container>
      </section>

      <section className="py-16">
        <Container className="flex flex-col gap-6">
          <h2 className="font-display text-[1.6rem] font-bold text-navy">Les secteurs qui en profitent le plus</h2>
          <div className="flex flex-wrap gap-3">
            {p.sectors.map((s) => (
              <a key={s.slug} href={`/activites/${s.slug}`} className="inline-flex items-center gap-2 rounded-full border border-line bg-white px-4 py-2 text-[15px] font-semibold text-navy hover:border-navy/40">
                {s.label} <Icon name="arrow" className="h-4 w-4 text-muted" />
              </a>
            ))}
          </div>
          <div className="mt-6 flex flex-col gap-3">
            <p className="text-[13px] font-bold uppercase tracking-[0.18em] text-muted">Les autres piliers</p>
            <div className="grid gap-3 sm:grid-cols-3">
              {others.map((o) => (
                <a key={o.slug} href={`/piliers/${o.slug}`} className="flex items-center gap-3 rounded-xl border border-line bg-white p-4 hover:border-navy/40">
                  <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-blue-soft text-navy">
                    <Icon name={o.icon} className="h-[18px] w-[18px]" />
                  </span>
                  <span>
                    <span className="block text-[12px] font-bold uppercase tracking-wider text-muted">{o.tag}</span>
                    <span className="block font-semibold text-navy">{o.title}</span>
                  </span>
                </a>
              ))}
            </div>
          </div>
        </Container>
      </section>

      <CtaBand />
    </>
  );
}

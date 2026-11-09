import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { notFound } from 'next/navigation';
import { Icon } from '@/components/Icon';
import { SectorOrb, Timeline } from '@/components/SectorVisuals';
import { BookingMock, CampagneMock, ClientMock, DashboardMock, RelancesMock, SegmentsMock, WhatsAppMock, WithPhone } from '@/components/mockups';
import { CtaBand, PricingCards } from '@/components/sections';
import { Button, Container, SectionTitle, StatusBadge } from '@/components/ui';
import { families, links, type Family } from '@/lib/site';

export function generateStaticParams() {
  return families.map((f) => ({ slug: f.slug }));
}

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const f = families.find((x) => x.slug === slug);
  if (!f) return {};
  return { title: f.name, description: `${f.problem.headline} ${f.message}`, alternates: { canonical: `/activites/${f.slug}` } };
}

// Visuel de la partie « solution », choisi selon ce qui compte le plus pour le métier.
const visuals: Record<string, ReactNode> = {
  beaute: <WithPhone main={<RelancesMock />} phone={<WhatsAppMock />} />,
  sante: <WithPhone main={<RelancesMock />} phone={<BookingMock />} />,
  sport: <WithPhone main={<DashboardMock />} phone={<BookingMock />} />,
  technique: <WithPhone main={<ClientMock />} phone={<WhatsAppMock />} />,
  pro: <WithPhone main={<SegmentsMock />} phone={<WhatsAppMock />} />,
  boutiques: <WithPhone main={<SegmentsMock />} phone={<CampagneMock />} />,
};

const steps = [
  { id: 'probleme', n: '1', label: 'Le problème' },
  { id: 'difficultes', n: '2', label: 'Les difficultés' },
  { id: 'solution', n: '3', label: 'La solution' },
];

export default async function FamilyPage({ params }: Props) {
  const { slug } = await params;
  const f = families.find((x) => x.slug === slug);
  if (!f) notFound();
  const others = families.filter((x) => x.slug !== f.slug);
  const live = f.solutions.filter((s) => s.status === 'dispo').length;

  return (
    <>
      <section className="relative overflow-hidden border-b border-line bg-[#F7FAFD]">
        <div aria-hidden className="pointer-events-none absolute inset-0 bg-[radial-gradient(45%_55%_at_78%_40%,rgba(14,123,214,.08),transparent)]" />
        <Container className="relative flex flex-col gap-10 py-14 lg:py-16">
          <nav aria-label="Fil d’Ariane" className="flex items-center gap-2 text-[14px] text-muted">
            <a href="/activites" className="hover:text-navy">Activités</a>
            <span aria-hidden>/</span>
            <span className="inline-flex items-center gap-1.5 font-semibold text-navy"><Icon name={f.icon} className="h-4 w-4 text-blue-text" /> {f.name}</span>
          </nav>
          <div className="grid items-center gap-12 lg:grid-cols-[1fr_1fr]">
            <div className="flex min-w-0 flex-col gap-6">
              <h1 className="text-balance font-display text-[2.4rem] font-bold leading-[1.05] tracking-[-0.025em] text-navy sm:text-[3.2rem]">{f.message}</h1>
              <span aria-hidden className="h-px w-12 bg-navy/30" />
              <p className="max-w-xl text-[1.08rem] leading-relaxed text-muted">{f.intro}</p>
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <Button href="/contact" variant="dark" size="lg">Réserver une démonstration</Button>
                <a href={links.signup} className="inline-flex items-center gap-1.5 whitespace-nowrap px-2 font-semibold text-blue-text">ou commencer l’essai gratuit <Icon name="arrow" className="h-4 w-4" /></a>
              </div>
              <div className="flex flex-wrap gap-2">
                {f.trades.map((t) => (
                  <span key={t} className="rounded-full border border-line bg-white px-3 py-1 text-[13px] font-medium text-navy">{t}</span>
                ))}
              </div>
            </div>
            <div className="min-w-0"><SectorOrb f={f} photo={sectorPhoto(f.slug)} /></div>
          </div>
          <dl className="grid overflow-hidden rounded-2xl border border-line bg-white sm:grid-cols-3 sm:divide-x sm:divide-line">
            <Fact label="Rythme de retour" value={f.rhythm} />
            <Fact label="Signal de départ" value={f.signal} />
            <Fact label="Module métier" value={f.module} />
          </dl>
          <ol className="grid gap-2 sm:grid-cols-3">
            {steps.map((s) => (
              <li key={s.id}>
                <a href={`#${s.id}`} className="group flex items-center gap-3 rounded-xl border border-line bg-white px-4 py-3 transition hover:border-blue/40 hover:shadow-card">
                  <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-blue-soft font-display text-[15px] font-bold text-blue-text">{s.n}</span>
                  <span className="font-semibold text-navy">{s.label}</span>
                  <Icon name="chevron" className="ml-auto h-4 w-4 text-muted transition group-hover:translate-y-0.5" />
                </a>
              </li>
            ))}
          </ol>
        </Container>
      </section>

      {/* 1. Le problème */}
      <section id="probleme" className="scroll-mt-20 py-20">
        <Container className="flex flex-col gap-10">
          <PartLabel n="1" label="Le problème" tone="warn" />
          <div className="grid gap-10 lg:grid-cols-[1.4fr_1fr] lg:items-start">
            <div className="flex flex-col gap-5">
              <h2 className="text-balance font-display text-[1.9rem] font-bold leading-[1.12] tracking-[-0.01em] text-navy sm:text-[2.5rem]">{f.problem.headline}</h2>
              <p className="max-w-[62ch] text-[1.08rem] leading-relaxed text-muted">{f.problem.text}</p>
            </div>
            <CostCard f={f} />
          </div>
          <div className="flex flex-col gap-5">
            <h3 className="font-display text-[1.35rem] font-bold text-navy">Un contact au bon moment. Pas un client perdu.</h3>
            <Timeline f={f} />
            <p className="text-[12.5px] text-muted">Exemple illustratif, données fictives.</p>
          </div>
        </Container>
      </section>

      {/* 2. Les difficultés */}
      <section id="difficultes" className="scroll-mt-20 border-y border-line bg-soft py-20">
        <Container className="flex flex-col gap-10">
          <PartLabel n="2" label="Les difficultés" tone="warn" />
          <SectionTitle title="Ce qui se passe, concrètement, au quotidien." lead="Ce que nous entendons le plus souvent, en visite, chez les gérants et leurs équipes." />
          <ol className="grid overflow-hidden rounded-2xl border border-line bg-white sm:grid-cols-2 lg:grid-cols-4">
            {f.difficulties.map((d, i) => (
              <li key={d.title} className="flex flex-col gap-3 border-t-2 border-t-warn/70 p-6 sm:[&:nth-child(odd)]:border-r sm:[&:nth-child(odd)]:border-r-line lg:[&:not(:last-child)]:border-r lg:[&:not(:last-child)]:border-r-line">
                <span className="text-[12px] font-bold tabular-nums tracking-[0.14em] text-muted">{String(i + 1).padStart(2, '0')}</span>
                <h3 className="font-display text-[18px] font-bold leading-tight text-warn">{d.title}</h3>
                <p className="text-[14.5px] leading-relaxed text-muted">{d.text}</p>
                <p className="mt-auto border-t border-line pt-3 text-[13.5px] font-semibold text-navy">{d.cost}</p>
              </li>
            ))}
          </ol>
        </Container>
      </section>

      {/* 3. La solution */}
      <section id="solution" className="scroll-mt-20 py-20">
        <Container className="flex flex-col gap-12">
          <PartLabel n="3" label="La solution NENTIX" tone="green" />
          <div className="grid items-center gap-12 lg:grid-cols-[1fr_1.05fr]">
            <div className="flex min-w-0 flex-col gap-8">
              <SectionTitle
                eyebrow="Votre fichier client"
                title={`Vos ${f.file.unit} sont déjà là. Encore faut-il ne pas les perdre de vue.`}
                lead="NENTIX repère chaque matin ceux qui dépassent leur rythme habituel et prépare le message. Vous gardez la main : un clic pour envoyer."
              />
            </div>
            <div className="min-w-0">{visuals[f.slug]}</div>
          </div>
          <SectionTitle
            title="À chaque difficulté, une réponse précise."
            lead={live === f.solutions.length ? 'Toutes ces réponses sont déjà disponibles dans l’application.' : `${live} réponses sur ${f.solutions.length} sont déjà disponibles dans l’application. Les autres arrivent avec le module ${f.short}, et sont signalées « Bientôt ».`}
          />
          <ol className="flex flex-col divide-y divide-line overflow-hidden rounded-3xl border border-line">
            {f.solutions.map((s, i) => (
              <li key={s.title} className="grid gap-4 bg-white p-6 md:grid-cols-[minmax(0,0.8fr)_auto_minmax(0,1.6fr)] md:items-start md:gap-8 md:p-7">
                <div className="flex items-start gap-3">
                  <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-warn-soft font-display text-[13px] font-bold text-warn">{i + 1}</span>
                  <p className="pt-0.5 text-[15px] font-semibold leading-snug text-muted">{f.difficulties[i].title}</p>
                </div>
                <Icon name="arrow" className="hidden h-5 w-5 text-green-text md:mt-1 md:block" />
                <div className="flex min-w-0 flex-col gap-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="font-display text-[19px] font-bold leading-tight text-navy">{s.title}</h3>
                  </div>
                  <p className="text-[15px] leading-relaxed text-muted">{s.text}</p>
                  <div className="mt-1 flex flex-wrap items-center gap-2">
                    <span className="rounded-full bg-blue-soft px-2.5 py-0.5 text-[12.5px] font-semibold text-blue-text">{s.feature}</span>
                    <StatusBadge status={s.status} />
                  </div>
                </div>
              </li>
            ))}
          </ol>
        </Container>
      </section>

      <section className="border-t border-line bg-soft py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center eyebrow="Tarifs" title="Un prix adapté à la taille de votre équipe." />
          {f.pricingNote && <p className="mx-auto max-w-3xl rounded-2xl bg-blue-soft px-5 py-4 text-center text-[15px] leading-relaxed text-navy">{f.pricingNote}</p>}
          <PricingCards />
        </Container>
      </section>

      <section className="pt-16">
        <Container className="flex flex-col gap-4">
          <p className="font-display text-[18px] font-bold text-navy">Autres activités</p>
          <div className="flex flex-wrap gap-2">
            {others.map((o) => (
              <a key={o.slug} href={`/activites/${o.slug}`} className="inline-flex items-center gap-2 rounded-full border border-line px-4 py-2 text-[14.5px] font-semibold text-navy hover:border-blue/40">
                <Icon name={o.icon} className="h-4 w-4 text-blue-text" /> {o.name}
              </a>
            ))}
          </div>
        </Container>
      </section>

      <CtaBand />
    </>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-1 border-b border-line p-5 last:border-b-0 sm:border-b-0">
      <dt className="text-[11.5px] font-bold uppercase tracking-[0.14em] text-muted">{label}</dt>
      <dd className="text-[15px] font-semibold leading-snug text-navy">{value}</dd>
    </div>
  );
}

function PartLabel({ n, label, tone }: { n: string; label: string; tone: 'warn' | 'green' }) {
  const c = tone === 'warn' ? 'bg-warn-soft text-warn' : 'bg-green-soft text-green-text';
  return (
    <div className="flex items-center gap-4">
      <span className={`grid h-11 w-11 place-items-center rounded-full font-display text-[18px] font-bold ${c}`}>{n}</span>
      <p className="font-display text-[15px] font-bold uppercase tracking-[0.16em] text-navy">{label}</p>
      <span aria-hidden className="h-px flex-1 bg-line" />
    </div>
  );
}

function CostCard({ f }: { f: Family }) {
  return (
    <div className="rounded-3xl border border-line bg-white p-6 shadow-card">
      <p className="text-[12px] font-bold uppercase tracking-[0.14em] text-muted">{f.calc.label}</p>
      <dl className="mt-4 flex flex-col divide-y divide-line text-[15px]">
        {f.calc.lines.map(([k, v], i) => (
          <div key={k} className="flex items-baseline justify-between gap-4 py-2.5">
            <dt className="text-muted">{k}</dt>
            <dd className="font-semibold tabular-nums text-navy">{i > 0 ? '× ' : ''}{v}</dd>
          </div>
        ))}
      </dl>
      <p className="mt-3 rounded-xl bg-warn-soft px-4 py-3 font-display text-[18px] font-bold leading-snug text-[#7C2D12]">= {f.calc.total}</p>
      <p className="mt-3 text-[12.5px] text-muted">Estimation indicative, à ajuster avec vos propres chiffres.</p>
    </div>
  );
}

// Photo du professionnel du secteur, si elle a été déposée dans public/images/secteurs/
// (beaute.png, sante.png…). Sinon, l’emblème dessiné reste affiché.
function sectorPhoto(slug: string): string | undefined {
  return ['png', 'webp', 'jpg'].map((ext) => `/images/secteurs/${slug}.${ext}`).find((p) => existsSync(join(process.cwd(), 'public', p)));
}

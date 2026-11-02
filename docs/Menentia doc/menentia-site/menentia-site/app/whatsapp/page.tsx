import type { Metadata } from 'next';
import { Icon } from '@/components/Icon';
import { ChatbotMock, RelancesMock, WhatsAppMock, WithPhone } from '@/components/mockups';
import { CtaBand } from '@/components/sections';
import { Button, Container, SectionTitle, StatusBadge } from '@/components/ui';
import { links } from '@/lib/site';
import { columns, faq, menentia, questions, rules, rows } from '@/lib/whatsapp';

export const metadata: Metadata = {
  title: 'WhatsApp pour les PME : le guide 2026',
  description: 'WhatsApp Business ou API officielle de Meta : ce que vos clients vous écrivent, ce qui est permis, ce que ça coûte, et comment Menentia vous aide à répondre et relancer sans y passer vos journées.',
  alternates: { canonical: '/whatsapp' },
};

export default function WhatsAppGuide() {
  return (
    <>
      <section className="relative overflow-hidden border-b border-line bg-[#F7FAFD]">
        <Container className="grid items-center gap-12 py-14 lg:grid-cols-[1.15fr_1fr] lg:py-20">
          <div className="flex min-w-0 flex-col gap-6">
            <p className="text-[13px] font-bold uppercase tracking-[0.16em] text-green-text">WhatsApp pour les PME</p>
            <h1 className="text-balance font-display text-[2.4rem] font-bold leading-[1.05] tracking-[-0.025em] text-navy sm:text-[3.1rem]">WhatsApp Business pour les entreprises sur rendez-vous&nbsp;: le guide 2026</h1>
            <p className="max-w-xl text-[1.08rem] leading-relaxed text-muted">Vos clients ont WhatsApp dans la poche. Ils vous écrivent le soir, le dimanche, pendant que vous êtes avec quelqu’un. Voici ce qui est permis, ce que ça coûte, et comment répondre et relancer sans y passer vos journées.</p>
            <div className="rounded-2xl border border-line bg-white p-5">
              <p className="text-[12px] font-bold uppercase tracking-[0.14em] text-blue-text">Réponse directe</p>
              <p className="mt-2 text-[15.5px] leading-relaxed text-ink">Il y a deux façons d’utiliser WhatsApp avec vos clients : l’application WhatsApp Business, gratuite, où quelqu’un écrit à la main ; ou l’API officielle de Meta, branchée sur un logiciel, qui envoie rappels et campagnes en nombre. Menentia fonctionne avec les deux : sans l’API, il prépare chaque message et vous l’envoyez en un clic ; avec votre compte API, il envoie et suit les campagnes pour vous.</p>
            </div>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
              <Button href="/contact" variant="dark" size="lg">Voir une démonstration</Button>
              <a href={links.signup} className="inline-flex items-center gap-1.5 whitespace-nowrap px-2 font-semibold text-blue-text">ou commencer l’essai gratuit <Icon name="arrow" className="h-4 w-4" /></a>
            </div>
          </div>
          <div className="min-w-0"><WithPhone main={<RelancesMock />} phone={<WhatsAppMock />} /></div>
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle eyebrow="Ce que vos clients vous écrivent" title="Les mêmes questions, tous les jours." lead="Dans une entreprise sur rendez-vous, les messages se ressemblent d’un client à l’autre. C’est ce qui rend WhatsApp si utile, et si prenant quand on répond à la main." />
          <ul className="grid gap-px overflow-hidden rounded-2xl border border-line bg-line sm:grid-cols-2 lg:grid-cols-3">
            {questions.map((q) => (
              <li key={q.q} className="flex flex-col gap-2 bg-white p-6">
                <p className="font-display text-[18px] font-bold leading-snug text-navy">{q.q}</p>
                <p className="text-[12px] font-bold uppercase tracking-[0.12em] text-green-text">{q.who}</p>
                <p className="text-[14.5px] leading-relaxed text-muted">{q.text}</p>
              </li>
            ))}
          </ul>
        </Container>
      </section>

      <section className="border-y border-line bg-soft py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle eyebrow="Comparer" title={'L’application ou Menentia\u00a0: la vraie différence.'} />
          <div className="overflow-x-auto rounded-2xl border border-line bg-white">
            <table className="w-full min-w-[720px] border-collapse text-left text-[14.5px]">
              <thead>
                <tr className="border-b border-line">
                  <th scope="col" className="p-5" />
                  {columns.map((c, i) => (
                    <th key={c} scope="col" className={`p-5 font-display text-[15px] font-bold ${i === 2 ? 'text-blue-text' : 'text-navy'}`}>{c}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.label} className="border-b border-line last:border-b-0">
                    <th scope="row" className="p-5 font-semibold text-navy">{r.label}</th>
                    {r.cells.map((c, i) => (
                      <td key={i} className={`p-5 ${i === 0 ? 'text-muted' : 'text-ink'}`}>{c}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Container>
      </section>

      <section className="py-20">
        <Container className="grid gap-12 lg:grid-cols-[1fr_1.3fr]">
          <SectionTitle eyebrow="Les règles à connaître" title="Ce que WhatsApp permet, et ce qu’il interdit." lead="Ces règles viennent de Meta et des lois sur les données personnelles. Menentia les applique pour vous." />
          <ol className="grid gap-px overflow-hidden rounded-2xl border border-line bg-line sm:grid-cols-2">
            {rules.map((r, i) => (
              <li key={r.t} className="flex flex-col gap-2 bg-white p-6">
                <span className="text-[12px] font-bold tabular-nums tracking-[0.14em] text-muted">{String(i + 1).padStart(2, '0')}</span>
                <p className="font-display text-[17px] font-bold text-navy">{r.t}</p>
                <p className="text-[14.5px] leading-relaxed text-muted">{r.d}</p>
              </li>
            ))}
          </ol>
        </Container>
      </section>

      <section className="bg-navy py-20 text-white">
        <Container className="grid items-center gap-12 lg:grid-cols-[1.3fr_1fr]">
          <div className="flex flex-col gap-8">
            <SectionTitle light eyebrow="Ce que fait Menentia" title="Répondre, rappeler, relancer, au nom de votre établissement." />
            <ul className="grid gap-4 sm:grid-cols-2">
              {menentia.map((m) => (
                <li key={m.title} className="flex flex-col gap-2 rounded-2xl border border-white/10 bg-white/5 p-5">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-display text-[16.5px] font-bold">{m.title}</p>
                    <StatusBadge status={m.status} />
                  </div>
                  <p className="text-[14px] leading-relaxed text-[#C9D6E3]">{m.text}</p>
                </li>
              ))}
            </ul>
          </div>
          <div className="flex justify-center"><ChatbotMock /></div>
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center eyebrow="Questions fréquentes" title="WhatsApp et Menentia, en clair." />
          <div className="mx-auto flex w-full max-w-3xl flex-col divide-y divide-line rounded-2xl border border-line bg-white">
            {faq.map((o) => (
              <details key={o.q} className="group px-6 py-5">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-display text-[17px] font-bold text-navy [&::-webkit-details-marker]:hidden">
                  {o.q}
                  <Icon name="chevron" className="h-5 w-5 shrink-0 text-muted transition group-open:rotate-180" />
                </summary>
                <p className="pt-3 text-[15.5px] leading-relaxed text-muted">{o.a}</p>
              </details>
            ))}
          </div>
        </Container>
      </section>

      <CtaBand />
    </>
  );
}

import type { Metadata } from 'next';
import { Icon } from '@/components/Icon';
import { QuoteCreator } from '@/components/QuoteCreator';
import { Button, Container, Eyebrow, SectionTitle } from '@/components/ui';
import { links } from '@/lib/site';

// Devis express : créateur de devis côté navigateur (QuoteCreator), l'inscription reçoit ?offre=devis.

export const metadata: Metadata = {
  title: 'Devis express : vos devis pro envoyés sur WhatsApp',
  description: 'Créez un devis pro sur votre téléphone en 30 secondes, envoyez le lien sur WhatsApp, votre client le signe en ligne. Montants en FCFA, gratuit pour commencer.',
  alternates: { canonical: '/devis' },
};

const signupQuote = links.signupPlan('devis');

const problems = [
  { icon: 'message', t: 'Des devis peu crédibles', d: 'Un vocal ou un texte brut sur WhatsApp : le client compare avec un concurrent qui envoie un vrai document.' },
  { icon: 'layers', t: 'Des allers-retours sans fin', d: 'Imprimer, faire signer, scanner, renvoyer : le chantier attend pendant que le papier circule.' },
  { icon: 'shield', t: 'Des oublis et aucune trace', d: 'L’acompte oublié, le prix contesté : sans document signé, rien à montrer en cas de litige.' },
];

const steps = [
  { icon: 'sparkles', t: 'Créez en 30 secondes', d: 'Choisissez le client, ajoutez les lignes depuis votre catalogue : le total en FCFA se calcule tout seul.' },
  { icon: 'send', t: 'Envoyez en 1 clic sur WhatsApp', d: 'Un lien unique et un message prêt à partir, depuis votre téléphone.' },
  { icon: 'check', t: 'Votre client accepte et signe', d: 'En ligne, sans compte à créer. Le devis accepté devient une facture avec acompte.' },
];

const audiences = [
  { icon: 'wrench', t: 'Garagistes' },
  { icon: 'sun', t: 'Techniciens froid et climatisation' },
  { icon: 'activity', t: 'Plombiers et électriciens' },
  { icon: 'coffee', t: 'Organisateurs d’événements et traiteurs' },
  { icon: 'mobile', t: 'Agences et freelances du digital' },
  { icon: 'route', t: 'Location de véhicules' },
];

const faq = [
  { q: 'Mon client doit-il installer une application ?', a: 'Non. Le devis s’ouvre dans le navigateur du téléphone depuis le lien WhatsApp. Votre client lit, accepte et signe sans créer de compte.' },
  { q: 'Puis-je mettre mon logo ?', a: 'Oui, avec l’offre Solo : votre logo et vos couleurs apparaissent sur chaque devis, sans la mention « Créé avec ClientlyX ».' },
  { q: 'Le devis signé est-il valable ?', a: 'La signature, la date et l’heure de l’acceptation sont enregistrées avec le devis : vous gardez une preuve de l’accord du client.' },
];

export default function Devis() {
  return (
    <>
      <section className="relative overflow-hidden border-b border-line bg-[#FAFAF9]">
        <div aria-hidden className="pointer-events-none absolute inset-0 bg-[radial-gradient(45%_55%_at_80%_30%,rgba(232,89,12,.10),transparent)]" />
        <Container className="relative flex flex-col gap-12 py-14 lg:py-20">
          <div className="flex max-w-3xl flex-col gap-5">
            <span className="inline-flex w-fit items-center gap-2 rounded-full bg-sun px-3 py-1 text-[13px] font-bold text-navy">
              <Icon name="sparkles" className="h-4 w-4" /> Devis express, gratuit pour commencer
            </span>
            <h1 className="font-display text-[2.3rem] font-bold leading-[1.06] tracking-[-0.02em] text-navy text-balance sm:text-[3.2rem]">
              Vos devis pro en 30 secondes, envoyés sur WhatsApp
            </h1>
            <p className="max-w-2xl text-[1.15rem] leading-relaxed text-muted">
              Créez un devis sur votre téléphone, envoyez le lien sur WhatsApp, votre client le signe en ligne. Gratuit pour commencer.
            </p>
          </div>
          <div id="createur" className="scroll-mt-24">
            <QuoteCreator />
          </div>
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle eyebrow="Le problème" title="Un devis bâclé, c’est un client perdu." />
          <div className="grid gap-5 md:grid-cols-3">
            {problems.map((p) => (
              <div key={p.t} className="flex flex-col gap-3 rounded-2xl bg-soft p-7">
                <Icon name={p.icon} className="h-6 w-6 text-[#E8590C]" />
                <h3 className="font-display text-xl font-bold text-navy">{p.t}</h3>
                <p className="text-[15px] leading-relaxed text-muted">{p.d}</p>
              </div>
            ))}
          </div>
        </Container>
      </section>

      <section className="bg-navy py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle light center eyebrow="Comment ça marche" title="Trois étapes, depuis votre téléphone." />
          <ol className="grid gap-5 md:grid-cols-3">
            {steps.map((s, i) => (
              <li key={s.t} className="flex flex-col gap-3 rounded-2xl bg-white/[0.06] p-7 ring-1 ring-white/10">
                <div className="flex items-center gap-3">
                  <span className="grid h-10 w-10 place-items-center rounded-full bg-sun font-display text-lg font-bold text-navy">{i + 1}</span>
                  <Icon name={s.icon} className="h-6 w-6 text-sun" />
                </div>
                <h3 className="font-display text-xl font-bold text-white">{s.t}</h3>
                <p className="text-[15px] leading-relaxed text-[#E2D8EC]">{s.d}</p>
              </li>
            ))}
          </ol>
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle eyebrow="Pour qui" title="Pour tous les métiers qui chiffrent avant d’intervenir." />
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {audiences.map((a) => (
              <div key={a.t} className="flex items-center gap-3 rounded-2xl border border-line bg-white p-5">
                <Icon name={a.icon} className="h-6 w-6 shrink-0 text-green-text" />
                <p className="font-display text-[17px] font-bold text-navy">{a.t}</p>
              </div>
            ))}
          </div>
        </Container>
      </section>

      <section className="bg-soft py-20">
        <Container className="flex flex-col gap-6">
          <SectionTitle eyebrow="Tarif" title="Gratuit pour commencer." />
          <div className="flex flex-col gap-4 rounded-2xl border border-line bg-white p-7">
            <p className="text-[1.05rem] leading-relaxed text-ink">
              <strong className="text-navy">Gratuit : 3 devis par mois</strong>, avec la mention « Créé avec ClientlyX ».
            </p>
            <p className="text-[1.05rem] leading-relaxed text-ink">
              Au-delà : passez à l’offre <strong className="text-navy">Solo (9 900 FCFA/mois)</strong> pour des devis illimités, votre logo et les relances automatiques.
            </p>
            <a href="/tarifs" className="font-semibold text-blue-text underline-offset-4 hover:underline">Voir tous les tarifs</a>
          </div>
          <div className="flex items-center gap-3 rounded-2xl bg-sun px-6 py-4 font-semibold text-navy">
            <Icon name="wallet" className="h-6 w-6 shrink-0" />
            Bientôt : paiement de l’acompte par Airtel Money et Moov Money.
          </div>
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center eyebrow="Questions fréquentes" title="Tout savoir sur le devis express." />
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

      <section className="pb-20">
        <Container>
          <div className="relative overflow-hidden rounded-[2rem] bg-green px-6 py-14 sm:px-14">
            <div aria-hidden className="pointer-events-none absolute -right-20 -top-20 h-72 w-72 rounded-full bg-sun/40 blur-3xl" />
            <div className="relative flex flex-col items-start gap-8 lg:flex-row lg:items-center lg:justify-between">
              <div className="flex max-w-2xl flex-col gap-4">
                <Eyebrow tone="light"><span className="text-sun">Gratuit</span></Eyebrow>
                <h2 className="font-display text-[2rem] font-bold leading-[1.08] text-white text-balance sm:text-[2.6rem]">Votre prochain devis, signé avant ce soir.</h2>
                <p className="text-lg leading-relaxed text-white/90">3 devis par mois offerts, sans carte bancaire.</p>
              </div>
              <Button href={signupQuote} variant="light" size="lg">Créer mon premier devis</Button>
            </div>
          </div>
        </Container>
      </section>
    </>
  );
}

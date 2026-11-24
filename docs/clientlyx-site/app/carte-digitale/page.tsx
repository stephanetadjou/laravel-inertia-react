import type { Metadata } from 'next';
import { CardCreator } from '@/components/CardCreator';
import { Icon } from '@/components/Icon';
import { Button, Container, Eyebrow, SectionTitle } from '@/components/ui';
import { links } from '@/lib/site';

// Carte digitale gratuite : produit d'appel vers ClientlyX. Le créateur tourne
// dans le navigateur, l'inscription reçoit ?offre=carte et les champs saisis.

export const metadata: Metadata = {
  title: 'Carte de visite digitale gratuite',
  description: 'Créez gratuitement votre carte de visite digitale en 5 minutes : WhatsApp, appel, contact, prise de rendez-vous et QR code. Chaque contact devient une fiche client dans ClientlyX.',
  alternates: { canonical: '/carte-digitale' },
};

const signupCard = links.cardSignup;

const steps = [
  { icon: 'sparkles', t: 'Créez votre carte', d: 'Nom, poste, numéro WhatsApp, photo et couleur : votre carte est en ligne en 5 minutes, depuis votre téléphone.' },
  { icon: 'send', t: 'Partagez le QR ou le lien', d: 'Imprimez le QR code, envoyez le lien sur WhatsApp ou mettez-le dans votre statut. Aucune application à installer pour vos clients.' },
  { icon: 'inbox', t: 'Recevez vos clients', d: 'Un clic sur WhatsApp, un appel, une demande de rendez-vous : chaque contact arrive dans ClientlyX avec sa fiche.' },
];

const benefits = [
  { icon: 'users', t: 'Une fiche client automatique', d: 'Chaque personne qui vous écrit ou réserve depuis la carte obtient une fiche client, avec la source : quelle carte, quel QR, quel support.' },
  { icon: 'message', t: 'La relance WhatsApp prête', d: 'Le contact qui n’a pas donné suite apparaît dans vos actions du jour, avec un message prérempli à envoyer en un clic.' },
  { icon: 'chart', t: 'Les statistiques par collaborateur', d: 'Vues, clics WhatsApp, appels et rendez-vous pour chaque carte : vous savez qui ramène des clients dans votre équipe.' },
];

const uses = [
  { icon: 'layers', t: 'Cartes imprimées', d: 'Le QR au dos de vos cartes de visite papier.' },
  { icon: 'store', t: 'Comptoir et vitrine', d: 'Un présentoir à la caisse ou sur la porte.' },
  { icon: 'route', t: 'Véhicules', d: 'Sur la voiture de service ou le camion de livraison.' },
  { icon: 'book', t: 'Flyers et affiches', d: 'Chaque support a son QR : vous voyez lequel marche.' },
  { icon: 'mobile', t: 'Statut WhatsApp', d: 'Le lien de la carte dans votre statut et votre profil.' },
];

type Cell = boolean | string;
const plans = ['Gratuit', 'Solo', 'Équipe', 'Premium'];
const table: [string, Cell, Cell, Cell, Cell][] = [
  ['Cartes digitales', '3', '10', '30', 'Illimitées'],
  ['QR code, lien et enregistrement du contact', true, true, true, true],
  ['Boutons WhatsApp, Appeler et Prendre RDV', true, true, true, true],
  ['Fiche client créée à chaque contact', true, true, true, true],
  ['Réponses automatiques aux messages WhatsApp', false, true, true, true],
  ['Statistiques', '7 jours', '30 jours', '90 jours, par collaborateur', '12 mois'],
  ['Impression carte de visite 85 × 55 mm', false, false, true, true],
  ['Mention « Créé avec ClientlyX »', 'Oui', 'Oui', 'Retirée', 'Retirée'],
];

const faq = [
  { q: 'La carte est-elle vraiment gratuite ?', a: 'Oui. L’offre Gratuit comprend 3 cartes, sans limite de durée et sans carte bancaire. Vous passez à une offre payante seulement si vous voulez plus de cartes, retirer la mention ou suivre les statistiques.' },
  { q: 'Mes clients doivent-ils installer une application ?', a: 'Non. La carte s’ouvre dans le navigateur du téléphone, depuis le QR code ou le lien. Le client peut vous écrire sur WhatsApp, vous appeler ou enregistrer votre contact en un geste.' },
  { q: 'Que se passe-t-il quand quelqu’un me contacte depuis la carte ?', a: 'Une fiche client est créée dans ClientlyX avec la carte d’origine. Vous retrouvez la personne dans vos clients et, si elle n’a pas donné suite, dans vos relances du jour.' },
  { q: 'Puis-je modifier ma carte après l’avoir imprimée ?', a: 'Oui. Le QR code pointe vers votre carte en ligne : changez de numéro, de poste ou de photo, le QR déjà imprimé reste valable.' },
  { q: 'Puis-je créer les cartes de toute mon équipe ?', a: 'Oui. Chaque collaborateur a sa carte, aux couleurs de l’entreprise. Avec l’offre Équipe, vous comparez les contacts ramenés par chacun.' },
];

export default function CarteDigitale() {
  return (
    <>
      <section className="relative overflow-hidden border-b border-line bg-[#FAFAF9]">
        <div aria-hidden className="pointer-events-none absolute inset-0 bg-[radial-gradient(45%_55%_at_80%_30%,rgba(232,89,12,.10),transparent)]" />
        <Container className="relative flex flex-col gap-12 py-14 lg:py-20">
          <div className="flex max-w-3xl flex-col gap-5">
            <span className="inline-flex w-fit items-center gap-2 rounded-full bg-sun px-3 py-1 text-[13px] font-bold text-navy">
              <Icon name="sparkles" className="h-4 w-4" /> Gratuit, sans carte bancaire
            </span>
            <h1 className="font-display text-[2.3rem] font-bold leading-[1.06] tracking-[-0.02em] text-navy text-balance sm:text-[3.2rem]">
              Votre carte de visite digitale gratuite, prête en 5 minutes
            </h1>
            <p className="max-w-2xl text-[1.15rem] leading-relaxed text-muted">
              WhatsApp, appel, enregistrement du contact et prise de rendez-vous dans un seul lien, avec son QR code. Et avec ClientlyX, chaque personne qui vous contacte devient un client suivi.
            </p>
          </div>
          <div id="createur" className="scroll-mt-24">
            <CardCreator />
          </div>
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center eyebrow="Comment ça marche" title="Trois étapes, aucune compétence technique." />
          <ol className="grid gap-5 md:grid-cols-3">
            {steps.map((s, i) => (
              <li key={s.t} className="flex flex-col gap-3 rounded-2xl border border-line bg-white p-7">
                <div className="flex items-center gap-3">
                  <span className="grid h-10 w-10 place-items-center rounded-full bg-green font-display text-lg font-bold text-white">{i + 1}</span>
                  <Icon name={s.icon} className="h-6 w-6 text-green-text" />
                </div>
                <h3 className="font-display text-xl font-bold text-navy">{s.t}</h3>
                <p className="text-[15px] leading-relaxed text-muted">{s.d}</p>
              </li>
            ))}
          </ol>
        </Container>
      </section>

      <section className="bg-navy py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle light eyebrow="Notre différence" title="Plus qu’une carte : chaque contact devient un client." lead="Une carte classique s’arrête au premier message. Avec ClientlyX, le contact entre dans votre base client et vous savez quoi faire ensuite." />
          <div className="grid gap-5 md:grid-cols-3">
            {benefits.map((b) => (
              <div key={b.t} className="flex flex-col gap-3 rounded-2xl bg-white/[0.06] p-7 ring-1 ring-white/10">
                <span className="grid h-12 w-12 place-items-center rounded-xl bg-sun text-navy"><Icon name={b.icon} className="h-6 w-6" /></span>
                <h3 className="font-display text-xl font-bold text-white">{b.t}</h3>
                <p className="text-[15px] leading-relaxed text-[#E2D8EC]">{b.d}</p>
              </div>
            ))}
          </div>
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle eyebrow="Usages" title="Votre QR code partout où passent vos clients." />
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            {uses.map((u) => (
              <div key={u.t} className="flex flex-col gap-2 rounded-2xl bg-soft p-5">
                <Icon name={u.icon} className="h-6 w-6 text-green-text" />
                <p className="font-display text-[17px] font-bold text-navy">{u.t}</p>
                <p className="text-[14.5px] text-muted">{u.d}</p>
              </div>
            ))}
          </div>
        </Container>
      </section>

      <section className="bg-soft py-20">
        <Container className="flex flex-col gap-8">
          <SectionTitle eyebrow="Offres" title="Gratuit pour commencer, plus de cartes quand l’équipe grandit." />
          <div className="overflow-x-auto rounded-2xl border border-line bg-white">
            <table className="w-full min-w-[640px] border-collapse text-[15px]">
              <thead>
                <tr className="border-b border-line">
                  <th className="px-5 py-4 text-left font-semibold text-muted">Carte digitale</th>
                  {plans.map((p) => <th key={p} className="px-5 py-4 font-display font-bold text-navy">{p}</th>)}
                </tr>
              </thead>
              <tbody>
                {table.map(([label, ...cells]) => (
                  <tr key={label} className="border-b border-line last:border-0">
                    <th className="px-5 py-3.5 text-left font-medium text-ink">{label}</th>
                    {cells.map((c, i) => (
                      <td key={i} className="px-5 py-3.5 text-center">
                        {c === true ? <Icon name="check" className="mx-auto h-5 w-5 text-green-text" strokeWidth={2.6} /> : c === false ? <span className="text-line">—</span> : <span className="text-[14px] font-medium text-navy">{c}</span>}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="text-[14px] text-muted">Les cartes sont comprises dans chaque offre, sans supplément. Entreprise : nombre de cartes sur devis. <a href="/tarifs" className="font-semibold text-blue-text underline-offset-4 hover:underline">Voir tous les tarifs</a></p>
        </Container>
      </section>

      <section className="py-20">
        <Container className="flex flex-col gap-10">
          <SectionTitle center eyebrow="Questions fréquentes" title="Tout savoir sur la carte digitale." />
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
                <h2 className="font-display text-[2rem] font-bold leading-[1.08] text-white text-balance sm:text-[2.6rem]">Votre carte en ligne ce soir, vos premiers clients demain.</h2>
                <p className="text-lg leading-relaxed text-white/90">3 cartes offertes, sans carte bancaire. Chaque contact reçu devient une fiche client dans ClientlyX.</p>
              </div>
              <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row lg:flex-col">
                <Button href={signupCard} variant="light" size="lg">Créer ma carte gratuite</Button>
                <a href="#createur" className="inline-flex h-[52px] items-center justify-center rounded-full border border-white/50 px-7 text-base font-semibold text-white hover:bg-white/10">Essayer le créateur</a>
              </div>
            </div>
          </div>
        </Container>
      </section>
    </>
  );
}

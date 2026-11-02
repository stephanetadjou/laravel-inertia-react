import type { Metadata } from 'next';
import { Icon } from '@/components/Icon';
import { PageHero } from '@/components/PageHero';
import { Container } from '@/components/ui';
import { APP_URL, families, WHATSAPP_DISPLAY } from '@/lib/site';

export const metadata: Metadata = {
  title: 'Demander une démo',
  description: 'Une démonstration de 10 minutes sur votre téléphone, sur votre propre activité.',
  alternates: { canonical: '/contact' },
};

// Le formulaire envoie la demande à l'API de l'application (POST /api/public/v1/leads).
const LEADS_ENDPOINT = `${APP_URL}/api/public/v1/leads`;

const field = 'h-12 w-full rounded-xl border border-line bg-white px-4 text-[15px] text-ink outline-none transition focus:border-blue focus:ring-4 focus:ring-blue/10';

export default function Contact() {
  return (
    <>
      <PageHero eyebrow="Démo" title="10 minutes pour voir Menentia sur votre activité." lead="Nous venons vous montrer l’application sur votre téléphone, avec vos prestations. À Libreville, sur place ; ailleurs, en appel vidéo WhatsApp." />
      <section className="py-16">
        <Container className="grid gap-10 lg:grid-cols-[1.3fr_1fr]">
          <form action={LEADS_ENDPOINT} method="post" className="relative flex flex-col gap-5 rounded-3xl border border-line p-6 sm:p-8">
            <div id="merci" className="hidden rounded-2xl bg-green-soft p-4 text-[15px] font-semibold text-green-text target:block">
              Merci, votre demande est bien arrivée. Nous vous écrivons sur WhatsApp dans la journée.
            </div>
            {/* Piège à robots : un visiteur ne voit pas ce champ et le laisse vide. */}
            <div aria-hidden className="absolute -left-[9999px] h-px w-px overflow-hidden">
              <label htmlFor="site_web">Ne pas remplir</label>
              <input id="site_web" name="site_web" tabIndex={-1} autoComplete="off" />
            </div>
            <div className="grid gap-5 sm:grid-cols-2">
              <label className="flex flex-col gap-2 text-[14px] font-semibold text-navy" htmlFor="nom">Votre nom<input id="nom" name="nom" required className={field} autoComplete="name" /></label>
              <label className="flex flex-col gap-2 text-[14px] font-semibold text-navy" htmlFor="entreprise">Entreprise<input id="entreprise" name="entreprise" required className={field} autoComplete="organization" /></label>
              <label className="flex flex-col gap-2 text-[14px] font-semibold text-navy" htmlFor="whatsapp">Numéro WhatsApp<input id="whatsapp" name="whatsapp" type="tel" required placeholder="+241" className={field} autoComplete="tel" /></label>
              <label className="flex flex-col gap-2 text-[14px] font-semibold text-navy" htmlFor="ville">Ville<input id="ville" name="ville" defaultValue="Libreville" className={field} /></label>
            </div>
            <label className="flex flex-col gap-2 text-[14px] font-semibold text-navy" htmlFor="activite">
              Activité
              <select id="activite" name="activite" className={field} defaultValue="">
                <option value="" disabled>Choisir…</option>
                {families.map((f) => <option key={f.slug} value={f.slug}>{f.name}</option>)}
                <option value="autre">Autre</option>
              </select>
            </label>
            <label className="flex flex-col gap-2 text-[14px] font-semibold text-navy" htmlFor="message">
              Ce que vous voulez améliorer <span className="font-normal text-muted">(facultatif)</span>
              <textarea id="message" name="message" rows={4} className="w-full rounded-xl border border-line px-4 py-3 text-[15px] outline-none focus:border-blue focus:ring-4 focus:ring-blue/10" placeholder="Ex. : trop de rendez-vous oubliés, des clientes qui ne reviennent plus…" />
            </label>
            <label className="flex items-start gap-3 text-[14px] text-muted" htmlFor="consentement">
              <input id="consentement" name="consentement" type="checkbox" required className="mt-1 h-4 w-4 accent-[#0E7BD6]" />
              J’accepte d’être contacté par Menentia sur WhatsApp au sujet de ma demande.
            </label>
            <button type="submit" className="h-[52px] rounded-full bg-gradient-to-r from-blue to-green font-semibold text-white">Demander ma démo</button>
          </form>
          <aside className="flex flex-col gap-4">
            <div className="rounded-3xl bg-navy p-7 text-white">
              <p className="font-display text-[20px] font-bold">Préférez WhatsApp ?</p>
              <p className="mt-2 text-[15px] text-[#C9D9E8]">Écrivez-nous directement, réponse en français dans la journée.</p>
              <p className="mt-4 flex items-center gap-2 font-display text-[22px] font-bold"><Icon name="message" className="h-5 w-5 text-green" /> <span className="select-all">{WHATSAPP_DISPLAY}</span></p>
            </div>
            <div className="rounded-3xl border border-line p-7">
              <p className="font-display text-[18px] font-bold text-navy">Comment se passe la démo</p>
              <ol className="mt-3 flex flex-col gap-3 text-[15px] text-muted">
                {['Diagnostic de 10 minutes sur votre activité', 'Démonstration sur votre problème principal', 'Essai de 14 jours, avec import de vos clients', 'Point ensemble après 2 semaines'].map((s, i) => (
                  <li key={s} className="flex gap-3"><span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-blue-soft text-[12px] font-bold text-blue-text">{i + 1}</span>{s}</li>
                ))}
              </ol>
            </div>
          </aside>
        </Container>
      </section>
    </>
  );
}

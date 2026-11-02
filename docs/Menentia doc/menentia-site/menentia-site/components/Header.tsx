import { families, featureMenu, links, MARKETPLACE_URL } from '@/lib/site';
import { Icon } from './Icon';
import { Logo } from './ui';

// En-tête sans JavaScript : le sous-menu s'ouvre au survol ou au focus,
// le menu mobile repose sur <details>.
export function Header() {
  return (
    <header className="sticky top-0 z-40 border-b border-line/70 bg-white/90 backdrop-blur-md">
      <div className="mx-auto flex h-[72px] w-full max-w-page items-center gap-6 px-5 sm:px-8">
        <a href="/" aria-label="Menentia, accueil" className="shrink-0">
          <Logo id="lg-header" />
        </a>

        <nav className="hidden items-center gap-1 lg:flex" aria-label="Navigation principale">
          <div className="group relative">
            <a href="/activites" className="inline-flex items-center gap-1 rounded-full px-4 py-2 text-[15px] font-medium text-navy hover:bg-soft">
              Activités <Icon name="chevron" className="h-4 w-4 transition group-hover:rotate-180" />
            </a>
            <div className="invisible absolute left-0 top-full w-[560px] pt-3 opacity-0 transition group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100">
              <div className="grid grid-cols-2 gap-1 rounded-2xl border border-line bg-white p-3 shadow-float">
                {families.map((f) => (
                  <a key={f.slug} href={`/activites/${f.slug}`} className="flex items-start gap-3 rounded-xl p-3 hover:bg-soft">
                    <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-blue-soft text-blue-text">
                      <Icon name={f.icon} className="h-[18px] w-[18px]" />
                    </span>
                    <span>
                      <span className="block text-[15px] font-semibold text-navy">{f.name}</span>
                      <span className="block text-[13px] leading-snug text-muted">{f.trades.slice(0, 3).join(', ')}</span>
                    </span>
                  </a>
                ))}
                <a href="/activites" className="flex items-center gap-2 rounded-xl p-3 text-[14px] font-semibold text-blue-text hover:bg-soft">
                  Toutes les activités <Icon name="arrow" className="h-4 w-4" />
                </a>
              </div>
            </div>
          </div>
          {/* Sous-menu large : positionné sur l'en-tête (sticky) et non sur le lien. */}
          <div className="group flex h-[72px] items-center">
            <a href="/fonctionnalites" className="inline-flex items-center gap-1 rounded-full px-4 py-2 text-[15px] font-medium text-navy hover:bg-soft">
              Fonctionnalités <Icon name="chevron" className="h-4 w-4 transition group-hover:rotate-180" />
            </a>
            <div className="invisible absolute inset-x-0 top-full opacity-0 transition group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100">
              <div className="mx-auto w-full max-w-page px-5 sm:px-8">
                <div className="grid grid-cols-3 gap-8 rounded-b-2xl border border-t-0 border-line bg-white p-8 shadow-float">
                  {featureMenu.map((col) => (
                    <div key={col.title}>
                      <p className="mb-3 px-3 text-xs font-bold uppercase tracking-[0.16em] text-muted">{col.title}</p>
                      {col.items.map((item) => (
                        <a key={item.title} href={item.href} className="flex items-start gap-3 rounded-xl p-3 hover:bg-soft">
                          <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-blue-soft text-blue-text">
                            <Icon name={item.icon} className="h-[18px] w-[18px]" />
                          </span>
                          <span>
                            <span className="block text-[15px] font-semibold text-navy">{item.title}</span>
                            {item.soon && <span className="my-1 inline-flex rounded-full bg-blue-soft px-2 py-0.5 text-[11px] font-semibold text-blue-text">Bientôt</span>}
                            <span className="block text-[13px] leading-snug text-muted">{item.text}</span>
                          </span>
                        </a>
                      ))}
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
          <a href="/whatsapp" className="rounded-full px-4 py-2 text-[15px] font-medium text-navy hover:bg-soft">WhatsApp</a>
          <a href="/tarifs" className="rounded-full px-4 py-2 text-[15px] font-medium text-navy hover:bg-soft">Tarifs</a>
          <a href="/contact" className="rounded-full px-4 py-2 text-[15px] font-medium text-navy hover:bg-soft">Démo</a>
        </nav>

        <div className="ml-auto hidden items-center gap-2 lg:flex">
          <a href={MARKETPLACE_URL} className="inline-flex h-11 items-center gap-2 rounded-full border border-line px-4 text-[15px] font-semibold text-navy hover:border-navy/40">
            <Icon name="store" className="h-[18px] w-[18px]" /> Marketplace
          </a>
          <a href={links.login} className="px-3 text-[15px] font-semibold text-navy hover:text-blue-text">Se connecter</a>
          <a href={links.signup} className="inline-flex h-11 items-center rounded-full bg-navy px-5 text-[15px] font-semibold text-white hover:bg-navy-deep">Essai gratuit</a>
        </div>

        <details className="group ml-auto lg:hidden">
          <summary className="inline-flex h-11 cursor-pointer list-none items-center gap-2 rounded-full border border-line px-4 text-[15px] font-semibold text-navy [&::-webkit-details-marker]:hidden">
            Menu <Icon name="menu" className="h-5 w-5 group-open:hidden" /><Icon name="close" className="hidden h-5 w-5 group-open:block" />
          </summary>
          <div className="absolute inset-x-0 top-[72px] max-h-[calc(100dvh-72px)] overflow-y-auto border-b border-line bg-white px-5 pb-6 pt-2 shadow-float">
            <p className="px-2 pt-3 text-xs font-bold uppercase tracking-[0.16em] text-muted">Activités</p>
            {families.map((f) => (
              <a key={f.slug} href={`/activites/${f.slug}`} className="flex items-center gap-3 rounded-xl px-2 py-2.5 text-[16px] font-medium text-navy">
                <Icon name={f.icon} className="h-[18px] w-[18px] text-blue-text" /> {f.name}
              </a>
            ))}
            <div className="my-3 h-px bg-line" />
            <p className="px-2 pt-1 text-xs font-bold uppercase tracking-[0.16em] text-muted">Fonctionnalités</p>
            {featureMenu.flatMap((col) => col.items).map((item) => (
              <a key={item.title} href={item.href} className="flex items-center gap-3 rounded-xl px-2 py-2.5 text-[16px] font-medium text-navy">
                <Icon name={item.icon} className="h-[18px] w-[18px] text-blue-text" /> {item.title}
                {item.soon && <span className="rounded-full bg-blue-soft px-2 py-0.5 text-[11px] font-semibold text-blue-text">Bientôt</span>}
              </a>
            ))}
            <div className="my-3 h-px bg-line" />
            <a href="/whatsapp" className="block rounded-xl px-2 py-2.5 text-[16px] font-medium text-navy">WhatsApp</a>
            <a href="/tarifs" className="block rounded-xl px-2 py-2.5 text-[16px] font-medium text-navy">Tarifs</a>
            <a href="/contact" className="block rounded-xl px-2 py-2.5 text-[16px] font-medium text-navy">Demander une démo</a>
            <a href={MARKETPLACE_URL} className="block rounded-xl px-2 py-2.5 text-[16px] font-medium text-navy">Marketplace</a>
            <div className="mt-4 grid grid-cols-2 gap-2">
              <a href={links.login} className="inline-flex h-12 items-center justify-center rounded-full border border-line font-semibold text-navy">Se connecter</a>
              <a href={links.signup} className="inline-flex h-12 items-center justify-center rounded-full bg-navy font-semibold text-white">Essai gratuit</a>
            </div>
          </div>
        </details>
      </div>
    </header>
  );
}

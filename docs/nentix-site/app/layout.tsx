import type { Metadata, Viewport } from 'next';
import localFont from 'next/font/local';
import { Header } from '@/components/Header';
import { Footer } from '@/components/Footer';
import { ASSISTANT_URL, brand } from '@/lib/site';
import './globals.css';

const display = localFont({
  src: [
    { path: './fonts/Inter-600.ttf', weight: '600' },
    { path: './fonts/Inter-700.ttf', weight: '700' },
  ],
  variable: '--font-display',
  display: 'swap',
});

const body = localFont({
  src: [
    { path: './fonts/Inter-400.ttf', weight: '400' },
    { path: './fonts/Inter-500.ttf', weight: '500' },
    { path: './fonts/Inter-600.ttf', weight: '600' },
    { path: './fonts/Inter-700.ttf', weight: '700' },
  ],
  variable: '--font-body',
  display: 'swap',
});

export const metadata: Metadata = {
  metadataBase: new URL('https://menentia.com'),
  title: { default: `NENTIX · ${brand.promise}`, template: '%s · NENTIX' },
  description: brand.description,
  openGraph: { type: 'website', locale: 'fr_FR', siteName: 'NENTIX', description: brand.description },
  alternates: { canonical: '/' },
};

export const viewport: Viewport = { themeColor: '#0B1F3A', width: 'device-width', initialScale: 1 };

const jsonLd = {
  '@context': 'https://schema.org',
  '@type': 'SoftwareApplication',
  name: 'NENTIX',
  applicationCategory: 'BusinessApplication',
  operatingSystem: 'Web, Android, iOS',
  description: brand.description,
  offers: { '@type': 'Offer', price: '9900', priceCurrency: 'XAF' },
  areaServed: ['GA', 'CM'],
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="fr" className={`${display.variable} ${body.variable}`}>
      <body className="font-sans antialiased">
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }} />
        <a href="#contenu" className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-full focus:bg-navy focus:px-4 focus:py-2 focus:text-white">Aller au contenu</a>
        <Header />
        <main id="contenu">{children}</main>
        <Footer />
        {/* Assistant du site : bulle en bas à droite (public/assistant/assistant.js). */}
        <script src="/assistant/assistant.js" charSet="utf-8" data-menentia-assistant="" data-api={ASSISTANT_URL} data-kb="/assistant/kb.json" defer />
      </body>
    </html>
  );
}

import type { MetadataRoute } from 'next';
import { families } from '@/lib/site';

export const dynamic = 'force-static';

export default function sitemap(): MetadataRoute.Sitemap {
  const pages = ['', '/fonctionnalites', '/whatsapp', '/activites', '/tarifs', '/marketplace', '/contact', ...families.map((f) => `/activites/${f.slug}`)];
  return pages.map((p) => ({ url: `https://menentia.com${p}`, changeFrequency: 'monthly', priority: p === '' ? 1 : 0.7 }));
}

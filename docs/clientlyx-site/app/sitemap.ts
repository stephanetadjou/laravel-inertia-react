import type { MetadataRoute } from 'next';
import { families } from '@/lib/site';
import { posts } from '@/lib/blog';
import { pillarPages } from '@/lib/pillars';

export const dynamic = 'force-static';

export default function sitemap(): MetadataRoute.Sitemap {
  const pages = ['', '/fonctionnalites', '/whatsapp', '/activites', '/tarifs', '/carte-digitale', '/marketplace', '/contact', '/blog', ...posts.map((p) => `/blog/${p.slug}`), ...families.map((f) => `/activites/${f.slug}`), ...pillarPages.map((p) => `/piliers/${p.slug}`)];
  return pages.map((p) => ({ url: `https://clientlyx.com${p}`, changeFrequency: 'monthly', priority: p === '' ? 1 : 0.7 }));
}

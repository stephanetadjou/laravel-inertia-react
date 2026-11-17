'use client';

import { usePathname } from 'next/navigation';
import { useEffect } from 'react';

/*
 * Règle de marque : dans le texte visible, « ClientlyX » s'écrit en gras avec
 * le X en orange (classes .cx-brand / .cx-x de globals.css). Appliqué après
 * l'hydratation, à toutes les pages et aux données des secteurs, sans toucher
 * aux balises title, métadonnées, URL, alt ni emails (attributs, pas du texte).
 */
const WORD = 'ClientlyX';
const SKIP = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEXTAREA', 'INPUT', 'TITLE', 'CODE']);

function mark(root: Node) {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
    acceptNode(node) {
      const parent = node.parentElement;
      if (!parent || !node.nodeValue?.includes(WORD)) return NodeFilter.FILTER_REJECT;
      if (SKIP.has(parent.tagName) || parent.closest('.cx-brand, [data-no-brand], svg')) return NodeFilter.FILTER_REJECT;
      return NodeFilter.FILTER_ACCEPT;
    },
  });
  const nodes: Text[] = [];
  while (walker.nextNode()) nodes.push(walker.currentNode as Text);

  for (const node of nodes) {
    const parts = (node.nodeValue ?? '').split(WORD);
    const fragment = document.createDocumentFragment();
    parts.forEach((part, i) => {
      if (part) fragment.appendChild(document.createTextNode(part));
      if (i < parts.length - 1) {
        const b = document.createElement('b');
        b.className = 'cx-brand';
        b.append('Cliently');
        const x = document.createElement('span');
        x.className = 'cx-x';
        x.textContent = 'X';
        b.appendChild(x);
        fragment.appendChild(b);
      }
    });
    node.replaceWith(fragment);
  }
}

export function BrandMark() {
  const pathname = usePathname();

  // À chaque page (navigation comprise), une fois le rendu React posé.
  useEffect(() => {
    const id = window.setTimeout(() => mark(document.body), 0);

    return () => window.clearTimeout(id);
  }, [pathname]);

  return null;
}

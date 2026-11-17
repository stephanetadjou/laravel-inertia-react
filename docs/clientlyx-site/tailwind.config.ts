import type { Config } from 'tailwindcss';

// Palette ClientlyX « Soleil et prune » (14/11/2026) : prune pour le sérieux,
// orange pour les actions, jaune en touche. Les noms de jetons historiques
// (green, blue, navy) sont gardés pour ne pas toucher toutes les pages :
// green = orange d'action, blue = prune clair, navy = prune.
export default {
  content: ['./app/**/*.{ts,tsx}', './components/**/*.{ts,tsx}', './lib/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        green: { DEFAULT: '#E8590C', text: '#B8460A', soft: '#FDEEE4' },
        blue: { DEFAULT: '#5B3A86', text: '#4E2A7A', soft: '#F3EEF8' },
        navy: { DEFAULT: '#2B1A3D', deep: '#1E1229' },
        sun: '#FFD43B',
        ink: '#2B1A3D',
        muted: '#57534E',
        soft: '#FAFAF9',
        line: '#E7E5E4',
        warn: { DEFAULT: '#C2410C', soft: '#FFF1E8' },
      },
      fontFamily: {
        display: ['var(--font-display)', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        sans: ['var(--font-body)', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      maxWidth: { page: '1200px' },
      boxShadow: {
        card: '0 1px 2px rgba(43,26,61,.05), 0 8px 24px rgba(43,26,61,.05)',
        float: '0 24px 60px -12px rgba(43,26,61,.25)',
      },
    },
  },
  plugins: [],
} satisfies Config;

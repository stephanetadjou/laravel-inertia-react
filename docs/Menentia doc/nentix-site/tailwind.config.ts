import type { Config } from 'tailwindcss';

// Couleurs de la marque bleu-vert (cahier de cadrage final, section 8).
export default {
  content: ['./app/**/*.{ts,tsx}', './components/**/*.{ts,tsx}', './lib/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        green: { DEFAULT: '#00C896', text: '#007A5C', soft: '#E3F8F1' },
        blue: { DEFAULT: '#0E7BD6', text: '#0A66B2', soft: '#E4F0FB' },
        navy: { DEFAULT: '#0B2D4A', deep: '#071E33' },
        ink: '#10243A',
        muted: '#4A5B6E',
        soft: '#F4F8FB',
        line: '#DCE6EF',
        warn: { DEFAULT: '#C2410C', soft: '#FFF1E8' },
      },
      fontFamily: {
        display: ['var(--font-display)', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        sans: ['var(--font-body)', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      maxWidth: { page: '1200px' },
      boxShadow: {
        card: '0 1px 2px rgba(11,45,74,.06), 0 8px 24px rgba(11,45,74,.06)',
        float: '0 24px 60px -12px rgba(11,45,74,.28)',
      },
    },
  },
  plugins: [],
} satisfies Config;

import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import type { ReactNode } from 'react'
import { ThemeProvider } from './context/ThemeContext'
import { initI18n } from './lib/i18n'

createInertiaApp({
    title: title => (title ? `${title} · ClientlyX` : 'ClientlyX'),
    resolve: name => {
        const pages = import.meta.glob<{ default: (props: unknown) => ReactNode }>('./pages/**/*.tsx')
        return pages[`./pages/${name}.tsx`]()
    },
    setup({ el, App, props }) {
        if (!el) {
            return
        }
        // Langue de l'interface (fr | en) et traductions partagées par Laravel.
        initI18n(props.initialPage.props as { locale?: 'fr' | 'en'; translations?: Record<string, string> })
        createRoot(el).render(
            <ThemeProvider>
                <App {...props} />
            </ThemeProvider>,
        )
    },
})

import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import type { ReactNode } from 'react'
import { ThemeProvider } from './context/ThemeContext'

createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob<{ default: (props: unknown) => ReactNode }>('./pages/**/*.tsx')
        return pages[`./pages/${name}.tsx`]()
    },
    setup({ el, App, props }) {
        if (!el) {
            return
        }
        createRoot(el).render(
            <ThemeProvider>
                <App {...props} />
            </ThemeProvider>,
        )
    },
})

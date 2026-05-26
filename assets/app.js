import './styles/app.css';

import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { route } from './js/lib/route';
import { initializeTheme } from './js/hooks/use-appearance';
import { TranslationProvider } from './js/lib/i18n';

// Expose route() globally so all pages can call route('name', params)
window.route = route;

// Apply saved theme before first render to avoid flash of wrong theme
initializeTheme();

createInertiaApp({
    title: (title) => `${title} - JamboAPI`,
    resolve: (name) => {
        const pages = require.context('./js/pages', true, /\.tsx$/);
        return pages(`./${name}.tsx`);
    },
    setup({ el, App, props }) {
        // Initialise Ziggy config from the route manifest injected by PHP on the first page load.
        // Subsequent Inertia XHR navigations reuse the already-set window.Ziggy.
        if (props.initialPage.props.ziggy) {
            window.Ziggy = props.initialPage.props.ziggy;
        }
        const initialProps = props.initialPage.props;
        const root = createRoot(el);
        root.render(
            <TranslationProvider
                initialLocale={initialProps.locale ?? 'en'}
                initialTranslations={initialProps.translations ?? {}}
            >
                <App {...props} />
            </TranslationProvider>
        );
    },
});

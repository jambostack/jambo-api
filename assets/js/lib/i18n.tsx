import { createContext, useContext, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';

type TFn = (key: string, params?: Record<string, string>) => string;

const TranslationContext = createContext<TFn>((key) => key);

interface TranslationProviderProps {
    children: React.ReactNode;
    initialLocale: string;
    initialTranslations: Record<string, string>;
}

export function TranslationProvider({ children, initialLocale, initialTranslations }: TranslationProviderProps) {
    const [locale, setLocale] = useState(initialLocale);
    const [translations, setTranslations] = useState(initialTranslations);

    useEffect(() => {
        // Keep locale/translations in sync with Inertia page navigations
        const remove = router.on('navigate', (event) => {
            const props = (event.detail.page as any).props;
            if (props.locale) setLocale(props.locale);
            if (props.translations) setTranslations(props.translations);
        });
        return remove;
    }, []);

    useEffect(() => {
        document.documentElement.setAttribute('lang', locale);
        document.documentElement.setAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr');
    }, [locale]);

    const t: TFn = (key, params) => {
        let str = translations[key] ?? key;
        if (params) {
            Object.entries(params).forEach(([k, v]) => {
                str = str.replace(`{${k}}`, v);
            });
        }
        return str;
    };

    return (
        <TranslationContext.Provider value={t}>
            {children}
        </TranslationContext.Provider>
    );
}

export const useTranslation = () => useContext(TranslationContext);

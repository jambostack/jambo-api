import { useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedData } from '@/types';
import AppLogoIcon from './app-logo-icon';
import { useSidebar } from '@/components/ui/sidebar';

export default function AppLogo() {
    const { appSettings } = usePage<SharedData>().props;
    const { state } = useSidebar();

    const appName = appSettings?.appName ?? 'JamboApi';
    const isCollapsed = state === 'collapsed';

    const [isDark, setIsDark] = useState(
        () => typeof document !== 'undefined' && document.documentElement.classList.contains('dark')
    );

    useEffect(() => {
        const observer = new MutationObserver(() => {
            setIsDark(document.documentElement.classList.contains('dark'));
        });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        return () => observer.disconnect();
    }, []);

    if (isCollapsed) {
        // Collapsed sidebar: show icon
        const iconUrl = isDark ? appSettings?.iconLightUrl : appSettings?.iconDarkUrl;
        if (iconUrl) {
            return <img src={iconUrl} alt={appName} className="size-8 object-contain block mx-auto" />;
        }
        return <AppLogoIcon className="size-8 text-primary block mx-auto" />;
    }

    // Expanded sidebar: show themed logo, fallback to SVG icon
    const logoUrl = isDark ? appSettings?.logoLightUrl : appSettings?.logoDarkUrl;

    if (logoUrl) {
        return (
            <div>
                <img src={logoUrl} alt={appName} className="h-7 object-contain" />
            </div>
        );
    }

    return (
        <>
            <div>
                <AppLogoIcon className="size-7 text-primary block mx-auto" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold">{appName}</span>
            </div>
        </>
    );
}

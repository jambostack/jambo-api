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
        // Collapsed sidebar: show uploaded icon, fallback to static PNG
        const iconUrl = isDark ? appSettings?.iconLightUrl : appSettings?.iconDarkUrl;
        if (iconUrl) {
            return <img src={iconUrl} alt={appName} className="size-8 object-contain block mx-auto" />;
        }
        const defaultIcon = isDark ? '/images/icon-light.png' : '/images/icon-dark.png';
        return <img src={defaultIcon} alt={appName} className="size-8 object-contain block mx-auto" />;
    }

    // Expanded sidebar: show uploaded logo, fallback to static PNG
    const logoUrl = isDark ? appSettings?.logoLightUrl : appSettings?.logoDarkUrl;

    if (logoUrl) {
        return (
            <div>
                <img src={logoUrl} alt={appName} className="h-7 object-contain" />
            </div>
        );
    }

    const defaultLogo = isDark ? '/images/logo-light.png' : '/images/logo-dark.png';
    return (
        <div>
            <img src={defaultLogo} alt={appName} className="h-7 object-contain" />
        </div>
    );
}

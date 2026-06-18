import Heading from '@/components/heading';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';
import { useTranslation } from '@/lib/i18n';
import { User, Lock, Palette, Shield } from 'lucide-react';

export default function SettingsLayout({ children }: PropsWithChildren) {
    const t = useTranslation();

    if (typeof window === 'undefined') return null;

    const currentPath = window.location.pathname;

    const tabs = [
        { href: '/settings/profile',    labelKey: 'settings.nav.profile',    icon: User },
        { href: '/settings/password',   labelKey: 'settings.nav.password',   icon: Lock },
        { href: '/settings/appearance', labelKey: 'settings.nav.appearance', icon: Palette },
        { href: '/settings/security',   labelKey: 'settings.nav.security',   icon: Shield },
    ];

    return (
        <div className="space-y-6">
            <Heading
                title={t('settings.heading')}
                description={t('settings.heading_desc')}
            />

            {/* Tabs horizontaux */}
            <div className="border-b">
                <nav className="flex gap-0 -mb-px overflow-x-auto">
                    {tabs.map(tab => {
                        const isActive = currentPath === tab.href;
                        const Icon = tab.icon;
                        return (
                            <Link
                                key={tab.href}
                                href={tab.href}
                                className={cn(
                                    'flex items-center gap-2 px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 transition-colors',
                                    isActive
                                        ? 'border-primary text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground hover:border-muted-foreground'
                                )}
                            >
                                <Icon className="h-4 w-4" />
                                {t(tab.labelKey)}
                            </Link>
                        );
                    })}
                </nav>
            </div>

            <div className="max-w-xl">
                {children}
            </div>
        </div>
    );
}

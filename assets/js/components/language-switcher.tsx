import { usePage } from '@inertiajs/react';
import { Globe } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import { type SharedData } from '@/types/index.d';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
    DropdownMenuLabel,
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';

const LOCALES = ['en', 'fr', 'es', 'ar'] as const;

export function LanguageSwitcher() {
    const t = useTranslation();
    const { locale } = usePage<SharedData>().props;

    const changeLocale = async (newLocale: string) => {
        if (newLocale === locale) return;
        await fetch(route('settings_locale_update'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ locale: newLocale }),
        });
        window.location.reload();
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="sm" className="gap-2 w-full justify-start px-2">
                    <Globe className="h-4 w-4" />
                    <span>{t(`lang.${locale}`)}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-36">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    {t('nav.language')}
                </DropdownMenuLabel>
                {LOCALES.map((l) => (
                    <DropdownMenuItem
                        key={l}
                        onClick={() => changeLocale(l)}
                        className={l === locale ? 'font-semibold' : ''}
                    >
                        {t(`lang.${l}`)}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

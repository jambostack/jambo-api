import { Head, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';

import type { BreadcrumbItem, SharedData } from '@/types/index.d';
import AppLayout from '@/layouts/app-layout';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';

type FileField = 'logo' | 'logo_dark' | 'logo_light' | 'icon_dark' | 'icon_light' | 'favicon';

export default function AppSettingsPage() {
    const t = useTranslation();
    const { appSettings } = usePage<SharedData>().props;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('app_settings.breadcrumb'), href: '/admin/app-settings' },
    ];

    const [appName, setAppName] = useState(appSettings.appName ?? '');
    const [previews, setPreviews] = useState<Record<FileField, string | null>>({
        logo:       appSettings.logoUrl,
        logo_dark:  appSettings.logoDarkUrl,
        logo_light: appSettings.logoLightUrl,
        icon_dark:  appSettings.iconDarkUrl,
        icon_light: appSettings.iconLightUrl,
        favicon:    appSettings.faviconUrl,
    });
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const fileRefs: Record<FileField, React.RefObject<HTMLInputElement>> = {
        logo:       useRef<HTMLInputElement>(null),
        logo_dark:  useRef<HTMLInputElement>(null),
        logo_light: useRef<HTMLInputElement>(null),
        icon_dark:  useRef<HTMLInputElement>(null),
        icon_light: useRef<HTMLInputElement>(null),
        favicon:    useRef<HTMLInputElement>(null),
    };

    const handleNameSave = async () => {
        if (!appName.trim()) return;
        setSaving(true);
        setError(null);
        try {
            const res = await fetch('/admin/api/app-settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ appName: appName.trim() }),
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw new Error(data?.errors?.appName ?? t('common.error'));
            }
            setSaved(true);
            setTimeout(() => setSaved(false), 2000);
        } catch (e: unknown) {
            setError(e instanceof Error ? e.message : t('common.error'));
        } finally {
            setSaving(false);
        }
    };

    const handleFileUpload = async (field: FileField, file: File) => {
        setSaving(true);
        setError(null);
        const form = new FormData();
        form.append(field, file);
        try {
            const res = await fetch('/admin/api/app-settings', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: form,
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw new Error(data?.errors?.[field] ?? t('common.error'));
            }
            const data = await res.json();
            const urlMap: Record<FileField, string | null> = {
                logo:       data.logoUrl,
                logo_dark:  data.logoDarkUrl,
                logo_light: data.logoLightUrl,
                icon_dark:  data.iconDarkUrl,
                icon_light: data.iconLightUrl,
                favicon:    data.faviconUrl,
            };
            setPreviews(prev => ({ ...prev, [field]: urlMap[field] }));
            if (fileRefs[field].current) {
                fileRefs[field].current.value = '';
            }
            setSaved(true);
            setTimeout(() => setSaved(false), 2000);
        } catch (e: unknown) {
            setError(e instanceof Error ? e.message : t('common.error'));
        } finally {
            setSaving(false);
        }
    };

    const logoSection = (field: FileField, labelKey: string, hintKey: string, acceptIco = false) => (
        <section className="space-y-3">
            <Label>{t(labelKey)}</Label>
            {previews[field] && (
                <img src={previews[field]!} alt={t(labelKey)} className="h-16 object-contain border rounded p-1 bg-white dark:bg-zinc-800" />
            )}
            <div className="flex gap-2">
                <Input
                    type="file"
                    accept={acceptIco ? 'image/*,.ico' : 'image/*'}
                    ref={fileRefs[field]}
                    onChange={e => e.target.files?.[0] && handleFileUpload(field, e.target.files[0])}
                />
            </div>
            <p className="text-sm text-muted-foreground">{t(hintKey)}</p>
        </section>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('app_settings.breadcrumb')} />

            <div className="px-4 py-6 max-w-2xl space-y-10">
                <HeadingSmall
                    title={t('app_settings.heading')}
                    description={t('app_settings.heading_desc')}
                />

                {/* App Name */}
                <section className="space-y-3">
                    <Label htmlFor="appName">{t('app_settings.app_name')}</Label>
                    <div className="flex gap-2">
                        <Input
                            id="appName"
                            value={appName}
                            onChange={e => setAppName(e.target.value)}
                            placeholder={t('app_settings.app_name_placeholder')}
                            maxLength={100}
                        />
                        <Button onClick={handleNameSave} disabled={saving || !appName.trim()}>
                            {saving ? t('common.loading') : saved ? t('common.saved') : t('common.save')}
                        </Button>
                    </div>
                </section>

                {/* Logo dark (for light mode) */}
                {logoSection('logo_dark', 'app_settings.logo_dark', 'app_settings.logo_dark_hint')}

                {/* Logo light (for dark mode) */}
                {logoSection('logo_light', 'app_settings.logo_light', 'app_settings.logo_light_hint')}

                {/* Icon dark (for light mode, collapsed sidebar) */}
                {logoSection('icon_dark', 'app_settings.icon_dark', 'app_settings.icon_dark_hint')}

                {/* Icon light (for dark mode, collapsed sidebar) */}
                {logoSection('icon_light', 'app_settings.icon_light', 'app_settings.icon_light_hint')}

                {/* Favicon */}
                {logoSection('favicon', 'app_settings.favicon', 'app_settings.favicon_hint', true)}

                {error && <p className="text-destructive text-sm">{error}</p>}
            </div>
        </AppLayout>
    );
}

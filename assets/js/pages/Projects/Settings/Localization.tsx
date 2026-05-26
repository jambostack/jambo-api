import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useState, useMemo } from 'react';
import { toast } from 'sonner';

import type { Project, BreadcrumbItem } from '@/types/index.d';

import AppLayout from '@/layouts/app-layout';
import ProjectSettingsLayout from './layout';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import MultiSelect from '@/components/ui/select/Select';

// Locales JSON map
import localesMap from '@/lib/locales.json';
import { Trash2 } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';

type ProjectWithLocales = Project & { locales: string[] };

interface Props {
    project: Project;
}

export default function LocalizationSettings({ project: initialProject }: Props) {
    const t = useTranslation();
    const [project, setProject] = useState<ProjectWithLocales>(initialProject as ProjectWithLocales);
    const [selectedLocale, setSelectedLocale] = useState<any>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('projects.settings.title'), href: route('projects.settings.project', project.id) },
        { title: t('projects.settings.nav_localization'), href: route('projects.settings.localization', project.id) },
    ];

    const localesArray = Array.isArray(project.locales) ? project.locales : (project.locales ? [project.locales as unknown as string] : []);

    const availableLocaleOptions = useMemo(() => {
        return Object.entries(localesMap)
            .filter(([code]) => !localesArray.includes(code))
            .map(([code, name]) => ({ value: code, label: `${code} - ${name}` }));
    }, [project]);

    const addLocale = async () => {
        if (!selectedLocale) return;
        try {
            const res = await axios.post(`/api/projects/${project.uuid}/settings/locales`, {
                locale: selectedLocale.value,
            });
            setProject(res.data as ProjectWithLocales);
            setSelectedLocale(null);
            toast.success(t('projects.settings.localization.locale_added'));
        } catch (e: any) {
            toast.error(e.response?.data?.message || t('projects.settings.localization.failed_add'));
        }
    };

    const setDefault = async (locale: string) => {
        try {
            const res = await axios.put(`/api/projects/${project.uuid}/settings/locale`, { locale });
            setProject(res.data as ProjectWithLocales);
            toast.success(t('projects.settings.localization.default_updated'));
        } catch (e: any) {
            toast.error(t('projects.settings.localization.failed_default'));
        }
    };

    const deleteLocale = async (locale: string) => {
        try {
            const res = await axios.delete(`/api/projects/${project.uuid}/settings/locales/${locale}`);
            setProject(res.data as ProjectWithLocales);
            toast.success(t('projects.settings.localization.locale_deleted'));
        } catch (e: any) {
            toast.error(e.response?.data?.message || t('projects.settings.localization.failed_delete'));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('projects.settings.localization.title')} />

            <ProjectSettingsLayout project={project}>
                <div className="space-y-10 max-w-2xl">
                    {/* Existing locales */}
                    <div>
                        <HeadingSmall title={t('projects.settings.localization.heading')} />
                        <div className="mt-4 overflow-x-auto border rounded-md">
                            <table className="min-w-full text-sm">
                                <tbody>
                                    {localesArray.map((loc) => (
                                        <tr key={loc} className="border-b last:border-b-0">
                                            <td className="px-4 py-2 whitespace-nowrap">{loc}</td>
                                            <td className="px-4 py-2 whitespace-nowrap">{(localesMap as any)[loc] || '-'}</td>
                                            <td className="px-4 py-2 text-center whitespace-nowrap">
                                                {loc === project.default_locale ? (
                                                    <span className="font-medium">{t('projects.settings.localization.default')}</span>
                                                ) : (
                                                    <Button variant="link" className="px-2" onClick={() => setDefault(loc)}>
                                                        {t('projects.settings.localization.set_default')}
                                                    </Button>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-center whitespace-nowrap">
                                                {loc !== project.default_locale && (
                                                    <Button variant="ghost" size="icon" className="text-red-600" onClick={() => deleteLocale(loc)}>
                                                        <Trash2 className="w-4 h-4" />
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {localesArray.length === 0 && (
                                        <tr>
                                            <td className="px-4 py-2 text-muted-foreground" colSpan={4}>{t('projects.settings.localization.no_locales')}</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <Separator />

                    {/* Add locale */}
                    <div>
                        <HeadingSmall title={t('projects.settings.localization.add_heading')} />
                        <div className="mt-4 flex gap-2 w-full">
                            <MultiSelect
                                value={selectedLocale}
                                onChange={setSelectedLocale}
                                options={availableLocaleOptions}
                                placeholder={t('projects.settings.localization.select_ph')}
                                classNamePrefix="react-select"
                                className="w-full"
                            />
                            <Button onClick={addLocale} disabled={!selectedLocale}>{t('projects.settings.localization.add_btn')}</Button>
                        </div>
                    </div>
                </div>
            </ProjectSettingsLayout>
        </AppLayout>
    );
}

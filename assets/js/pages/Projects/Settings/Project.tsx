import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState, useEffect } from 'react';
import axios from 'axios';

import type { Project, BreadcrumbItem, UserCan } from '@/types/index.d';

import AppLayout from '@/layouts/app-layout';
import ProjectSettingsLayout from './layout';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import DeleteProject from '@/components/delete-project';
import { Separator } from '@/components/ui/separator';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Switch } from '@/components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { CheckCircle, XCircle, Loader2 } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';

interface Props {
    project: Project;
}

interface MailerSettings {
    host: string; port: number; username: string;
    encryption: string; from_email: string; from_name: string;
    enabled: boolean;
}

export default function ProjectSettingsPage({ project }: Props) {
    const t = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: project.name,
            href: route('projects.show', project.id),
        },
        {
            title: t('projects.settings.title'),
            href: route('projects.settings.project', project.id),
        },
    ];

    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
        name: project.name || '',
        description: project.description || '',
        default_locale: project.default_locale || '',
        disk: project.disk || 'public',
        jwt_access_ttl: project.jwt_access_ttl ?? '',
        jwt_refresh_ttl: project.jwt_refresh_ttl ?? '',
    });

    const can = usePage().props.userCan as UserCan;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/api/projects/${project.uuid}`, {
            preserveScroll: true,
        });
    };

    // ─── SMTP Mailer ──────────────────────────────────────────────────────
    const [mailer, setMailer] = useState<MailerSettings | null>(null)
    const [mailerLoading, setMailerLoading] = useState(true)
    const [mailerSaving, setMailerSaving] = useState(false)
    const [mailerTesting, setMailerTesting] = useState(false)
    const [mailerPassword, setMailerPassword] = useState('')
    const [mailerToast, setMailerToast] = useState<{ type: 'ok' | 'err'; msg: string } | null>(null)
    const [mailerTestResult, setMailerTestResult] = useState<{ ok: boolean; msg: string } | null>(null)

    useEffect(() => {
        axios.get(`/api/admin/projects/${project.uuid}/mailer`)
            .then(r => { if (r.data.data) setMailer(r.data.data); setMailerLoading(false) })
            .catch(() => setMailerLoading(false))
    }, [project.uuid])

    function showMailerToast(type: 'ok' | 'err', msg: string) {
        setMailerToast({ type, msg }); setTimeout(() => setMailerToast(null), 4000)
    }

    async function saveMailer() {
        if (!mailer) return
        setMailerSaving(true)
        try {
            const body: any = { ...mailer }
            if (mailerPassword) body.password = mailerPassword
            const r = await axios.put(`/api/admin/projects/${project.uuid}/mailer`, body)
            setMailer(r.data.data)
            setMailerPassword('')
            showMailerToast('ok', t('projects.settings.mailer.saved'))
        } catch (err: any) {
            showMailerToast('err', err.response?.data?.error || t('common.error'))
        } finally { setMailerSaving(false) }
    }

    async function testMailer() {
        if (!mailer?.enabled) { showMailerToast('err', t('projects.settings.mailer.not_configured')); return }
        setMailerTesting(true); setMailerTestResult(null)
        try {
            await axios.post(`/api/admin/projects/${project.uuid}/mailer/test`)
            setMailerTestResult({ ok: true, msg: t('projects.settings.mailer.test_ok') })
        } catch (err: any) {
            setMailerTestResult({ ok: false, msg: err.response?.data?.error || t('common.error') })
        } finally { setMailerTesting(false) }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('projects.settings.page_title')} />

            <ProjectSettingsLayout project={project}>
                <div className="space-y-6 max-w-2xl">
                    <HeadingSmall title={t('projects.settings.project')} description={t('projects.settings.project_desc')} />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">{t('projects.settings.project_name')}</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                placeholder={t('projects.settings.project_name_ph')}
                            />
                            <InputError className="mt-2" message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="description">{t('projects.settings.desc_label')}</Label>
                            <Input
                                id="description"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder={t('projects.settings.desc_ph')}
                            />
                            <InputError className="mt-2" message={errors.description} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="disk">{t('projects.settings.storage')}</Label>
                            <RadioGroup
                                id="disk"
                                value={data.disk}
                                onValueChange={(value) => setData('disk', value as 'public' | 's3')}
                                className="flex gap-4"
                            >
                                <div className="flex flex-col p-4 px-6 border border-dashed rounded-md">
                                    <div className="flex items-center space-x-2">
                                        <RadioGroupItem value="public" id="disk-public" />
                                        <Label htmlFor="disk-public" className="font-medium">{t('projects.settings.local_storage')}</Label>
                                    </div>
                                    <p className="text-xs text-muted-foreground pl-6">{t('projects.settings.local_storage_desc')}</p>
                                </div>
                                <div className="flex items-center space-x-1 p-4 px-10 pl-4 border border-dashed rounded-md">
                                    <RadioGroupItem value="s3" id="disk-s3" />
                                    <Label htmlFor="disk-s3">{t('projects.settings.s3')}</Label>
                                </div>
                            </RadioGroup>
                        </div>

                        <Separator />

                        <HeadingSmall title={t('projects.settings.jwt_title')} description={t('projects.settings.jwt_desc')} />

                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="jwt_access_ttl">{t('projects.settings.jwt_access_ttl')}</Label>
                                <Input
                                    id="jwt_access_ttl"
                                    type="number"
                                    min="0"
                                    value={data.jwt_access_ttl}
                                    onChange={(e) => setData('jwt_access_ttl', e.target.value)}
                                    placeholder="900 (default: 15 min)"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {data.jwt_access_ttl && Number(data.jwt_access_ttl) > 0
                                        ? `= ${Math.round(Number(data.jwt_access_ttl) / 60)} min`
                                        : t('projects.settings.jwt_access_ttl_hint')}
                                </p>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="jwt_refresh_ttl">{t('projects.settings.jwt_refresh_ttl')}</Label>
                                <Input
                                    id="jwt_refresh_ttl"
                                    type="number"
                                    min="0"
                                    value={data.jwt_refresh_ttl}
                                    onChange={(e) => setData('jwt_refresh_ttl', e.target.value)}
                                    placeholder={t('projects.settings.jwt_refresh_ttl_hint')}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {data.jwt_refresh_ttl && Number(data.jwt_refresh_ttl) > 0
                                        ? `= ${Math.round(Number(data.jwt_refresh_ttl) / 86400)} days`
                                        : t('projects.settings.jwt_refresh_ttl_hint')}
                                </p>
                            </div>
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>{t('projects.settings.save_btn')}</Button>
                            {recentlySuccessful && (
                                <p className="text-sm text-neutral-600">{t('projects.settings.saved')}</p>
                            )}
                        </div>
                    </form>

                    {/* ═══ SMTP MAILER ═══ */}
                    <Separator />

                    <HeadingSmall title={t('projects.settings.mailer.title')} description={t('projects.settings.mailer.desc')} />

                    {mailerToast && (
                        <Alert variant={mailerToast.type === 'ok' ? 'default' : 'destructive'}>
                            {mailerToast.type === 'ok' ? <CheckCircle className="h-4 w-4" /> : <XCircle className="h-4 w-4" />}
                            <AlertDescription>{mailerToast.msg}</AlertDescription>
                        </Alert>
                    )}
                    {mailerTestResult && (
                        <Alert variant={mailerTestResult.ok ? 'default' : 'destructive'}>
                            {mailerTestResult.ok ? <CheckCircle className="h-4 w-4" /> : <XCircle className="h-4 w-4" />}
                            <AlertDescription>{mailerTestResult.msg}</AlertDescription>
                        </Alert>
                    )}

                    {mailerLoading && <p className="text-sm text-muted-foreground">{t('common.loading')}</p>}

                    {!mailerLoading && (
                        <div className="space-y-6">
                            <div className="flex items-center justify-between rounded-lg border p-4">
                                <div>
                                    <Label htmlFor="mailer-enabled" className="text-base font-medium">{t('projects.settings.mailer.enabled')}</Label>
                                    <p className="text-sm text-muted-foreground">{t('projects.settings.mailer.enable_desc')}</p>
                                </div>
                                <Switch
                                    id="mailer-enabled"
                                    checked={mailer?.enabled ?? false}
                                    onCheckedChange={(checked) => setMailer(s => s ? { ...s, enabled: checked } : null)}
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="mailer-host">{t('projects.settings.mailer.host')}</Label>
                                    <Input id="mailer-host" value={mailer?.host ?? ''} onChange={e => setMailer(s => s ? { ...s, host: e.target.value } : null)} required placeholder="smtp.gmail.com" />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="mailer-port">{t('projects.settings.mailer.port')}</Label>
                                    <Select value={String(mailer?.port ?? 587)} onValueChange={v => setMailer(s => s ? { ...s, port: parseInt(v) } : null)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="587">587 (TLS)</SelectItem>
                                            <SelectItem value="465">465 (SSL)</SelectItem>
                                            <SelectItem value="25">25</SelectItem>
                                            <SelectItem value="2525">2525</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="mailer-username">{t('projects.settings.mailer.username')}</Label>
                                    <Input id="mailer-username" value={mailer?.username ?? ''} onChange={e => setMailer(s => s ? { ...s, username: e.target.value } : null)} placeholder="apikey" />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="mailer-password">{t('projects.settings.mailer.password')}</Label>
                                    <Input id="mailer-password" type="password" value={mailerPassword} onChange={e => setMailerPassword(e.target.value)} placeholder="••••••••" />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label>{t('projects.settings.mailer.encryption')}</Label>
                                <Select value={mailer?.encryption ?? 'tls'} onValueChange={v => setMailer(s => s ? { ...s, encryption: v } : null)}>
                                    <SelectTrigger className="w-[180px]"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="tls">TLS</SelectItem>
                                        <SelectItem value="ssl">SSL</SelectItem>
                                        <SelectItem value="none">{t('common.cancel')}</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="mailer-from-email">{t('projects.settings.mailer.from_email')}</Label>
                                    <Input id="mailer-from-email" type="email" value={mailer?.from_email ?? ''} onChange={e => setMailer(s => s ? { ...s, from_email: e.target.value } : null)} required placeholder="noreply@example.com" />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="mailer-from-name">{t('projects.settings.mailer.from_name')}</Label>
                                    <Input id="mailer-from-name" value={mailer?.from_name ?? ''} onChange={e => setMailer(s => s ? { ...s, from_name: e.target.value } : null)} placeholder="Eureka Energy Consulting" />
                                </div>
                            </div>

                            <div className="flex items-center gap-4">
                                <Button type="button" onClick={saveMailer} disabled={mailerSaving}>
                                    {mailerSaving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                                    {mailerSaving ? t('projects.settings.mailer.saving') : t('common.save')}
                                </Button>
                                <Button type="button" variant="outline" onClick={testMailer} disabled={mailerTesting || !mailer?.enabled}>
                                    {mailerTesting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                                    {mailerTesting ? t('projects.settings.mailer.testing') : t('projects.settings.mailer.test')}
                                </Button>
                            </div>
                        </div>
                    )}

                    {can.delete_project && (
                        <>
                            <Separator />
                            <DeleteProject projectId={project.id} projectUuid={project.uuid} projectName={project.name} />
                        </>
                    )}
                </div>


            </ProjectSettingsLayout>
        </AppLayout>
    );
}

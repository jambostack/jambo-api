import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Plus, Eye, EyeOff } from 'lucide-react';

import type { Project, BreadcrumbItem, UserCan } from '@/types/index.d';

import AppLayout from '@/layouts/app-layout';
import ProjectSettingsLayout from './layout';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/input-error';
import MultiSelect from '@/components/ui/select/Select';
import { Switch } from '@/components/ui/switch';
import HeadingSmall from '@/components/heading-small';
import { AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle, AlertDialogDescription, AlertDialogFooter, AlertDialogCancel } from '@/components/ui/alert-dialog';
import { useTranslation } from '@/lib/i18n';

interface Webhook {
    id: number;
    name: string;
    description?: string | null;
    url: string;
    secret?: string | null;
    events: string[];
    sources: string[];
    status: boolean;
    payload: boolean;
    collections: { id: number; name: string }[];
}

interface Props {
    project: Project;
}

export default function WebhooksSettings({ project }: Props) {
    const t = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('projects.settings.title'), href: route('projects.settings.project', project.id) },
        { title: t('projects.settings.nav_webhooks'), href: route('projects.settings.webhooks', project.id) },
    ];

    const can = usePage().props.userCan as UserCan;

    const [webhooks, setWebhooks] = useState<Webhook[]>([]);
    const [loading, setLoading] = useState(true);

    const [showDialog, setShowDialog] = useState(false);
    const [editing, setEditing] = useState<Webhook | null>(null);
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [url, setUrl] = useState('');
    const [secret, setSecret] = useState('');
    const [secretVisible, setSecretVisible] = useState(false);
    const [collectionIds, setCollectionIds] = useState<number[]>([]);
    const EVENTS = ['content.created','content.updated','content.trashed','content.deleted','content.published','content.unpublished','content.restored'];
    const [eventsField, setEventsField] = useState<string[]>([EVENTS[0]]);
    const [sourcesField, setSourcesField] = useState<string[]>(['cms']);
    const [payload, setPayload] = useState<boolean>(true);
    const [status, setStatus] = useState<boolean>(true);

    const [errors, setErrors] = useState<Record<string,string[]>>({});

    const [webhookToDelete, setWebhookToDelete] = useState<Webhook | null>(null);

    const load = async () => {
        setLoading(true);
        try {
            const res = await axios.get(`/api/projects/${project.uuid}/webhooks`);
            setWebhooks(Array.isArray(res.data) ? res.data : (res.data.data ?? []));
        } catch {
            toast.error(t('projects.settings.webhooks.failed_load'));
        }
        setLoading(false);
    };

    useEffect(() => { load(); }, []);

    const reset = () => {
        setEditing(null);
        setName('');
        setDescription('');
        setUrl('');
        setSecret('');
        setCollectionIds([]);
        setEventsField([EVENTS[0]]);
        setSourcesField(['cms']);
        setPayload(true);
        setStatus(true);
        setErrors({});
    };

    const save = async () => {
        try {
            const data = { name, description, url, secret, collection_ids: collectionIds, events: eventsField, sources: sourcesField, payload, status };
            if (editing) {
                await axios.put(`/api/projects/${project.uuid}/webhooks/${editing.id}`, data);
                toast.success(t('projects.settings.webhooks.updated'));
            } else {
                await axios.post(`/api/projects/${project.uuid}/webhooks`, data);
                toast.success(t('projects.settings.webhooks.created'));
            }
            setShowDialog(false);
            reset();
            load();
            setErrors({});
        } catch (e: any) {
            if(e.response && e.response.status===422){
                setErrors(e.response.data.errors ?? {});
            } else {
                toast.error(t('projects.settings.webhooks.failed_save'));
            }
        }
    };

    const confirmDeleteWebhook = async () => {
        if(!webhookToDelete) return;
        try {
            await axios.delete(`/api/projects/${project.uuid}/webhooks/${webhookToDelete.id}`);
            setWebhooks(webhooks.filter(w=>w.id!==webhookToDelete.id));
            toast.success(t('projects.settings.webhooks.deleted'));
            setWebhookToDelete(null);
            setShowDialog(false);
        } catch { toast.error(t('projects.settings.webhooks.failed')); }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('projects.settings.webhooks.title')} />

            <ProjectSettingsLayout project={project}>
                <div className="space-y-6">
                    <div className="flex justify-between items-center">
                        <HeadingSmall title={t('projects.settings.webhooks.heading')} />
                        {can.access_webhooks_settings && (
                            <Button size="sm" onClick={() => { reset(); setShowDialog(true); }}><Plus className="w-4 h-4 mr-1" /> {t('projects.settings.webhooks.new')}</Button>
                        )}
                    </div>

                    {loading ? (
                        <p className="text-muted-foreground">{t('projects.settings.webhooks.loading')}</p>
                    ) : webhooks.length === 0 ? (
                        <p className="text-muted-foreground">{t('projects.settings.webhooks.no_webhooks')}</p>
                    ) : (
                        <div className="border rounded-md overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead className="bg-muted">
                                    <tr>
                                        <th className="px-4 py-2 text-left">{t('projects.settings.webhooks.col_name')}</th>
                                        <th className="px-4 py-2 text-left">{t('projects.settings.webhooks.col_url')}</th>
                                        <th className="px-4 py-2 text-left">{t('projects.settings.webhooks.col_collections')}</th>
                                        <th className="px-4 py-2 text-left">{t('projects.settings.webhooks.col_events')}</th>
                                        <th className="px-4 py-2 text-left">{t('projects.settings.webhooks.col_sources')}</th>
                                        <th className="px-4 py-2 text-left">{t('projects.settings.webhooks.col_status')}</th>
                                        <th className="px-4 py-2 text-left">{t('projects.settings.webhooks.col_logs')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {webhooks.map(w => (
                                        <tr key={w.id} className="border-t cursor-pointer hover:bg-muted/50" onClick={() => {
                                            setEditing(w);
                                            setName(w.name);
                                            setDescription(w.description ?? '');
                                            setUrl(w.url);
                                            setSecret(w.secret ?? '');
                                            setCollectionIds(w.collections?.map(c=>c.id) ?? []);
                                            setEventsField(w.events);
                                            setSourcesField(w.sources);
                                            setPayload(w.payload);
                                            setStatus(w.status);
                                            setShowDialog(true);
                                        }}>
                                            <td className="px-4 py-2">{w.name}</td>
                                            <td className="px-4 py-2">{w.url}</td>
                                            <td className="px-4 py-2">{w.collections.map(c=>c.name).join(', ')}</td>
                                            <td className="px-4 py-2">{w.events.join(', ')}</td>
                                            <td className="px-4 py-2">{w.sources.join(', ')}</td>
                                            <td className="px-4 py-2">{w.status ? t('projects.settings.webhooks.active') : t('projects.settings.webhooks.inactive')}</td>
                                            <td className="px-4 py-2" onClick={(e)=>e.stopPropagation()}><Button variant="outline" size="icon" onClick={()=>{
                                                router.visit(`/projects/${project.id}/settings/webhook-logs?webhook=${w.id}`);
                                            }}><Eye className="w-4 h-4"/></Button></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                <Dialog open={showDialog} onOpenChange={(o)=>{ if(!o){ setShowDialog(false); reset(); } }}>
                    <DialogContent className="sm:max-w-3xl">
                        <DialogHeader>
                            <div className="flex items-start justify-between">
                                <div>
                                    <DialogTitle>{editing ? t('projects.settings.webhooks.edit_title') : t('projects.settings.webhooks.create_title')}</DialogTitle>
                                    <DialogDescription>{t('projects.settings.webhooks.dialog_desc')}</DialogDescription>
                                </div>
                                {editing && (
                                    <Button variant="destructive" size="sm" className="ml-auto" onClick={()=>setWebhookToDelete(editing!)}>
                                        {t('projects.settings.webhooks.delete')}
                                    </Button>
                                )}
                            </div>
                        </DialogHeader>

                        <div className="space-y-2 max-h-[75vh] overflow-y-auto pr-2 px-1 pb-10">
                            <div>
                                <Label>{t('projects.settings.webhooks.field_name')}</Label>
                                <Input value={name} onChange={e=>setName(e.target.value)} />
                                <InputError message={errors.name?.[0]} />
                            </div>
                            <div>
                                <Label>{t('projects.settings.webhooks.field_desc')}</Label>
                                <Input value={description} onChange={e=>setDescription(e.target.value)} />
                                <InputError message={errors.description?.[0]} />
                            </div>
                            <div>
                                <Label>{t('projects.settings.webhooks.field_url')}</Label>
                                <Input value={url} onChange={e=>setUrl(e.target.value)} placeholder="https://" />
                                <InputError message={errors.url?.[0]} />
                            </div>
                            <div>
                                <Label>{t('projects.settings.webhooks.field_secret')}</Label>
                                <div className="flex gap-2 items-center">
                                    <Input type={secretVisible ? 'text':'password'} value={secret} onChange={e=>setSecret(e.target.value)} />
                                    <Button variant="outline" size="icon" onClick={()=>setSecretVisible(!secretVisible)}>{secretVisible? <Eye className="w-4 h-4"/>:<EyeOff className="w-4 h-4"/>}</Button>
                                    <Button variant="secondary" size="sm" onClick={()=>setSecret(Math.random().toString(36).slice(2,18) + Math.random().toString(36).slice(2,18))}>{t('projects.settings.webhooks.generate')}</Button>
                                </div>
                                <InputError message={errors.secret?.[0]} />
                            </div>
                            <div>
                                <Label>{t('projects.settings.webhooks.field_collections')}</Label>
                                <MultiSelect isMulti options={(project.collections??[]).map(c=>({value:c.id,label:c.name}))} value={(project.collections??[]).filter(c=>collectionIds.includes(c.id)).map(c=>({value:c.id,label:c.name}))} onChange={(vals:any)=>setCollectionIds(vals.map((v:any)=>v.value))} classNamePrefix="rs"/>
                                <InputError message={errors.collection_ids?.[0]} />
                            </div>
                            <div>
                                <Label>{t('projects.settings.webhooks.field_events')}</Label>
                                <MultiSelect isMulti options={EVENTS.map(e=>({value:e,label:e}))} value={eventsField.map(e=>({value:e,label:e}))} onChange={(vals:any)=>setEventsField(vals.map((v:any)=>v.value))} classNamePrefix="rs"/>
                                <InputError message={errors.events?.[0]} />
                            </div>
                            <div>
                                <Label>{t('projects.settings.webhooks.field_sources')}</Label>
                                <MultiSelect isMulti options={["cms","api"].map(s=>({value:s,label:s}))} value={sourcesField.map(s=>({value:s,label:s}))} onChange={(vals:any)=>setSourcesField(vals.map((v:any)=>v.value))} classNamePrefix="rs"/>
                                <InputError message={errors.sources?.[0]} />
                            </div>
                            <div className="flex items-center gap-4">
                                <label className="text-sm font-medium flex items-center gap-2">{t('projects.settings.webhooks.include_payload')} <Switch checked={payload} onCheckedChange={setPayload} /></label>
                                <label className="text-sm font-medium flex items-center gap-2">{t('projects.settings.webhooks.active_label')} <Switch checked={status} onCheckedChange={setStatus} /></label>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button variant="secondary" onClick={()=>setShowDialog(false)}>{t('projects.settings.webhooks.close')}</Button>
                            <Button onClick={save} disabled={!name || !url}>{t('projects.settings.webhooks.save')}</Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Delete confirmation dialog */}
                <AlertDialog open={webhookToDelete!==null} onOpenChange={(o)=>{ if(!o) setWebhookToDelete(null); }}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>{t('projects.settings.webhooks.delete_title')}</AlertDialogTitle>
                            <AlertDialogDescription>
                                {t('projects.settings.webhooks.delete_desc')}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>{t('projects.settings.webhooks.cancel')}</AlertDialogCancel>
                            <Button variant="destructive" onClick={confirmDeleteWebhook}>{t('projects.settings.webhooks.delete')}</Button>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </ProjectSettingsLayout>
        </AppLayout>
    );
}

import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import { toast } from 'sonner';
import { Copy, Plus, Trash2, Pencil } from 'lucide-react';
import { AlertDialog, AlertDialogContent, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogDescription, AlertDialogCancel, AlertDialogAction } from '@/components/ui/alert-dialog';

import type { Project, BreadcrumbItem, UserCan } from '@/types';

import AppLayout from '@/layouts/app-layout';
import ProjectSettingsLayout from './layout';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useTranslation } from '@/lib/i18n';

interface Props {
    project: Project;
    tokens: Array<{ id: number; name: string; abilities: string[]; created_at: string }>;
}

export default function APIAccessSettings({ project, tokens: initialTokens }: Props) {
    const t = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('projects.settings.title'), href: route('projects.settings.project', project.id) },
        { title: t('projects.settings.nav_api_access'), href: route('projects.settings.api-access', project.id) },
    ];

    const can = usePage().props.userCan as UserCan;

    const [tokens, setTokens] = useState(initialTokens);
    const [publicApi, setPublicApi] = useState<boolean>(project.public_api);

    const [showTokenDialog, setShowTokenDialog] = useState(false);
    const [editingToken, setEditingToken] = useState<{ id: number; name: string; abilities: string[] } | null>(null);
    const [newTokenName, setNewTokenName] = useState('');
    const [newTokenAbilities, setNewTokenAbilities] = useState<string[]>(['read']);
    const [createdPlainToken, setCreatedPlainToken] = useState<string | null>(null);

    const [tokenToDelete, setTokenToDelete] = useState<number|null>(null);

    const endpointUrl = `${window.location.origin}/api`;

    const copy = async (value: string) => {
        let success = false;

        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(value);
                success = true;
            } catch {}
        }

        if (!success) {
            const el = document.createElement('textarea');
            el.value = value;
            el.setAttribute('readonly', '');
            el.style.cssText = 'position:absolute;left:-9999px;top:-9999px';
            document.body.appendChild(el);
            el.select();
            el.setSelectionRange(0, el.value.length);
            success = document.execCommand('copy');
            document.body.removeChild(el);
        }

        if (success) {
            toast.success(t('projects.settings.api.copied'));
        } else {
            toast.error(t('projects.settings.api.copy_failed'));
        }
    };

    const togglePublic = async () => {
        try {
            const res = await axios.post(`/api/projects/${project.uuid}/settings/public-api`);
            setPublicApi(res.data.public_api);
            toast.success(t('projects.settings.api.public_updated'));
        } catch {
            toast.error(t('projects.settings.api.failed'));
        }
    };

    const resetDialogState = () => {
        setEditingToken(null);
        setCreatedPlainToken(null);
        setNewTokenName('');
        setNewTokenAbilities(['read']);
    };

    const saveToken = async () => {
        try {
            if (editingToken) {
                await axios.patch(`/api/projects/${project.uuid}/settings/tokens/${editingToken.id}`, {
                    name: newTokenName,
                    abilities: newTokenAbilities,
                });
                setTokens(tokens.map(t => t.id === editingToken.id ? { ...t, name: newTokenName, abilities: newTokenAbilities } : t));
                toast.success(t('projects.settings.api.token_updated'));
                setShowTokenDialog(false);
                resetDialogState();
            } else {
                const res = await axios.post(`/api/projects/${project.uuid}/settings/tokens`, {
                    name: newTokenName,
                    abilities: newTokenAbilities,
                });
                setTokens([...tokens, { id: res.data.id, name: newTokenName, abilities: newTokenAbilities, created_at: new Date().toISOString() }]);
                setCreatedPlainToken(res.data.token);
            }
        } catch {
            toast.error(t('projects.settings.api.failed_save'));
        }
    };

    const confirmDelete = async () => {
        if (tokenToDelete === null) return;
        try {
            await axios.delete(`/api/projects/${project.uuid}/settings/tokens/${tokenToDelete}`);
            setTokens(tokens.filter(t => t.id !== tokenToDelete));
            toast.success(t('projects.settings.api.token_deleted'));
        } catch { toast.error(t('projects.settings.api.failed')); }
        setTokenToDelete(null);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('projects.settings.api.title')} />

            <ProjectSettingsLayout project={project}>
                <div className="space-y-6 max-w-2xl">
                    <div>
                        <HeadingSmall title={t('projects.settings.api.project_id')} />
                        <div className="flex gap-2">
                            <Input readOnly value={project.uuid} />
                            <Button variant="outline" onClick={() => copy(project.uuid)}>
                                <Copy className="w-4 h-4" />
                            </Button>
                        </div>
                    </div>

                    <Separator />

                    <div>
                        <HeadingSmall title={t('projects.settings.api.endpoint')} />
                        <div className="flex gap-2">
                            <Input readOnly value={endpointUrl} />
                            <Button variant="outline" onClick={() => copy(endpointUrl)}>
                                <Copy className="w-4 h-4" />
                            </Button>
                        </div>
                    </div>

                    <Separator />

                    <div className="flex items-center gap-4">
                        <Label htmlFor="public_toggle">
                            {publicApi ? t('projects.settings.api.disable_public') : t('projects.settings.api.enable_public')}
                        </Label>
                        <Switch id="public_toggle" checked={publicApi} onCheckedChange={togglePublic} />
                    </div>

                    <Separator />

                    <div className="flex justify-between items-center">
                        <HeadingSmall title={t('projects.settings.api.tokens')} />
                        {can.access_api_access_settings && (
                            <Button size="sm" onClick={() => { resetDialogState(); setShowTokenDialog(true); }}>
                                <Plus className="w-4 h-4 mr-1" /> {t('projects.settings.api.create_token')}
                            </Button>
                        )}
                    </div>

                    <div className="border rounded-md mt-4 ">
                        <table className="min-w-full text-sm">
                            <thead className="bg-muted">
                                <tr>
                                    <th className="px-4 py-2 text-left">{t('projects.settings.api.col_name')}</th>
                                    <th className="px-4 py-2 text-left">{t('projects.settings.api.col_abilities')}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                {tokens.map((tk) => (
                                    <tr key={tk.id} className="border-t">
                                        <td className="px-4 py-2">{tk.name}</td>
                                        <td className="px-4 py-2">{tk.abilities.join(', ')}</td>
                                        <td className="px-4 py-2 text-right">
                                            {can.access_api_access_settings && (
                                            <>
                                                <Button variant="ghost" size="icon" onClick={() => { setEditingToken(tk); setNewTokenName(tk.name); setNewTokenAbilities(tk.abilities); setShowTokenDialog(true); }}>
                                                    <Pencil className="w-4 h-4" />
                                                </Button>
                                                <Button variant="ghost" size="icon" onClick={() => setTokenToDelete(tk.id)}>
                                                    <Trash2 className="w-4 h-4" />
                                                </Button>
                                            </>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {tokens.length === 0 && (
                                    <tr>
                                        <td className="px-4 py-6 text-muted-foreground" colSpan={3}>
                                            {t('projects.settings.api.no_tokens')}
                                        </td>
                                    </tr>
                                )}
                                </tbody>
                        </table>
                    </div>

                    <Dialog open={showTokenDialog} onOpenChange={(open)=>{if(!open){setShowTokenDialog(false);resetDialogState();}}}>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>{editingToken ? t('projects.settings.api.edit_token') : t('projects.settings.api.create_token_title')}</DialogTitle>
                                <DialogDescription>{t('projects.settings.api.token_desc')}</DialogDescription>
                            </DialogHeader>

                            {createdPlainToken ? (
                                <div className="space-y-3">
                                    <p className="text-sm">{t('projects.settings.api.token_copy_notice')}</p>
                                    <div className="flex gap-2">
                                        <Input readOnly value={createdPlainToken} />
                                        <Button variant="outline" onClick={() => copy(createdPlainToken!)}>
                                            <Copy className="w-4 h-4" />
                                        </Button>
                                    </div>
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    <div className="space-y-2">
                                        <Label>{t('projects.settings.api.token_name')}</Label>
                                        <Input value={newTokenName} onChange={(e) => setNewTokenName(e.target.value)} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>{t('projects.settings.api.token_abilities')}</Label>
                                        <div className="flex items-center gap-4">
                                            {['create', 'read', 'update', 'delete'].map((a) => (
                                                <label key={a} className="flex items-center gap-2 text-sm cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        checked={newTokenAbilities.includes(a)}
                                                        onChange={(e) => {
                                                            if (e.target.checked) setNewTokenAbilities([...newTokenAbilities, a]);
                                                            else setNewTokenAbilities(newTokenAbilities.filter((ab) => ab !== a));
                                                        }}
                                                    />
                                                    {a}
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            )}

                            <DialogFooter>
                                <Button variant="secondary" onClick={() => setShowTokenDialog(false)}>{t('projects.settings.api.close')}</Button>
                                {!createdPlainToken && (
                                    <Button onClick={saveToken} disabled={!newTokenName.trim()}>{editingToken ? t('projects.settings.save_btn') : t('projects.settings.api.create_token')}</Button>
                                )}
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    <AlertDialog open={tokenToDelete!==null} onOpenChange={(open)=>{if(!open) setTokenToDelete(null);}}>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>{t('projects.settings.api.delete_token_title')}</AlertDialogTitle>
                                <AlertDialogDescription>
                                    {t('projects.settings.api.delete_token_desc')}
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>{t('projects.show.cancel')}</AlertDialogCancel>
                                <Button variant="destructive" onClick={confirmDelete}>{t('common.delete')}</Button>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                </div>
            </ProjectSettingsLayout>
        </AppLayout>
    );
}

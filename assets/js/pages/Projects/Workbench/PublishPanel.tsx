import { useEffect, useState, useRef } from 'react';
import { useStore } from '@nanostores/react';
import { filesStore } from '@/stores/workbench';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Globe, Loader2, CheckCircle2, Trash2, Plus, AlertTriangle } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import { toast } from 'sonner';

interface EnvVar { id: number; key_name: string; value: string | null; is_secret: boolean; }
interface Domain { uuid: string; domain: string; is_primary: boolean; }
interface Props { projectUuid: string; workbenchUuid?: string; publishedAt?: string | null; }

export default function PublishPanel({ projectUuid, workbenchUuid, publishedAt }: Props) {
    const t = useTranslation();
    const files = useStore(filesStore);
    const hasFiles = Object.keys(files).length > 0;

    const [envVars, setEnvVars]         = useState<EnvVar[]>([]);
    const [domains, setDomains]         = useState<Domain[]>([]);
    const [newKey, setNewKey]           = useState('');
    const [newValue, setNewValue]       = useState('');
    const [newSecret, setNewSecret]     = useState(false);
    const [newDomain, setNewDomain]     = useState('');
    const [publishing, setPublishing]   = useState(false);
    const [lastPublished, setLastPublished] = useState<string | null>(publishedAt ?? null);
    const mountedRef = useRef(true);
    useEffect(() => { return () => { mountedRef.current = false; }; }, []);

    const api = (path: string, opts?: RequestInit) =>
        fetch(`/api/projects/${projectUuid}/workbench/${workbenchUuid}${path}`, {
            headers: { 'Content-Type': 'application/json' },
            ...opts,
        });

    useEffect(() => {
        if (!workbenchUuid) return;
        const controller = new AbortController();
        const signal = controller.signal;

        const fetchJson = (path: string) =>
            fetch(`/api/projects/${projectUuid}/workbench/${workbenchUuid}${path}`, {
                headers: { 'Content-Type': 'application/json' },
                signal,
            }).then(r => r.json());

        fetchJson('/env')
            .then((d: { data: EnvVar[] }) => { if (!signal.aborted) setEnvVars(d.data ?? []); })
            .catch((err: Error) => { if (err.name !== 'AbortError') console.error('Failed to load env vars:', err); });

        fetchJson('/domains')
            .then((d: { data: Domain[] }) => { if (!signal.aborted) setDomains(d.data ?? []); })
            .catch((err: Error) => { if (err.name !== 'AbortError') console.error('Failed to load domains:', err); });

        return () => controller.abort();
    }, [projectUuid, workbenchUuid]);

    const handleAddEnv = async () => {
        if (!newKey.trim()) return;
        const res = await api('/env', { method: 'POST', body: JSON.stringify({ key_name: newKey.trim(), value: newValue, is_secret: newSecret }) });
        const d = await res.json() as { data?: EnvVar; error?: string };
        if (!res.ok) { toast.error(d.error ?? t('workbench.sites.error')); return; }
        if (!mountedRef.current) return;
        setEnvVars(prev => [...prev, d.data!]);
        setNewKey(''); setNewValue(''); setNewSecret(false);
    };

    const handleDeleteEnv = async (id: number) => {
        try {
            const res = await api(`/env/${id}`, { method: 'DELETE' });
            if (!res.ok) throw new Error('Delete failed');
            if (!mountedRef.current) return;
            setEnvVars(prev => prev.filter(v => v.id !== id));
        } catch {
            toast.error(t('workbench.sites.error'));
        }
    };

    const handleAddDomain = async () => {
        if (!newDomain.trim()) return;
        const res = await api('/domains', { method: 'POST', body: JSON.stringify({ domain: newDomain.trim() }) });
        const d = await res.json() as { data?: Domain; error?: string };
        if (!res.ok) { toast.error(d.error ?? t('workbench.sites.error')); return; }
        if (!mountedRef.current) return;
        setDomains(prev => [...prev, d.data!]);
        setNewDomain('');
    };

    const handleDeleteDomain = async (uuid: string) => {
        try {
            const res = await api(`/domains/${uuid}`, { method: 'DELETE' });
            if (!res.ok) throw new Error('Delete failed');
            if (!mountedRef.current) return;
            setDomains(prev => prev.filter(d => d.uuid !== uuid));
        } catch {
            toast.error(t('workbench.sites.error'));
        }
    };

    const handlePublish = async () => {
        if (!workbenchUuid || !hasFiles) return;
        setPublishing(true);
        toast(t('workbench.sites.building'));

        try {
            // Build the file map from the store
            const storeFiles = filesStore.get();
            const fileMap: Record<string, string> = {};
            for (const [, f] of Object.entries(storeFiles)) {
                fileMap[f.path] = f.content;
            }

            toast(t('workbench.sites.uploading'));

            const res = await api('/publish', {
                method: 'POST',
                body: JSON.stringify({ files: fileMap }),
            });

            const data = await res.json() as { published_at?: string; error?: string };
            if (!res.ok) { toast.error(data.error ?? t('workbench.sites.publish_error')); return; }

            if (!mountedRef.current) return;
            setLastPublished(data.published_at ?? null);
            toast.success(t('workbench.sites.published'));
        } catch {
            toast.error(t('workbench.sites.publish_error'));
        } finally {
            if (mountedRef.current) setPublishing(false);
        }
    };

    if (!workbenchUuid) {
        return (
            <div className="flex items-center gap-2 rounded-lg bg-amber-500/10 border border-amber-500/20 p-3 text-sm text-amber-600 dark:text-amber-400">
                <AlertTriangle className="w-4 h-4 shrink-0" />
                {t('workbench.deploy.no_files')}
            </div>
        );
    }

    return (
        <div className="space-y-5 overflow-y-auto pr-1">
            {/* Bouton Publier */}
            <div className="space-y-2">
                <Button className="w-full" onClick={handlePublish} disabled={publishing || !hasFiles}>
                    {publishing
                        ? <><Loader2 className="w-4 h-4 mr-2 animate-spin" />{t('workbench.sites.uploading')}</>
                        : t('workbench.sites.publish_btn')}
                </Button>
                {lastPublished && (
                    <p className="text-xs text-muted-foreground text-center flex items-center justify-center gap-1">
                        <CheckCircle2 className="w-3 h-3 text-primary" />
                        {t('workbench.sites.last_published')} {new Date(lastPublished).toLocaleString(navigator.language)}
                    </p>
                )}
            </div>

            <Separator />

            {/* Variables d'environnement */}
            <div className="space-y-3">
                <h4 className="text-sm font-medium">{t('workbench.sites.env_section')}</h4>
                <div className="rounded-lg bg-amber-500/10 border border-amber-500/20 p-2 text-xs text-amber-600 dark:text-amber-400 flex gap-2">
                    <AlertTriangle className="w-3.5 h-3.5 shrink-0 mt-0.5" />
                    {t('workbench.sites.env_secret_warn')}
                </div>
                {envVars.map(v => (
                    <div key={v.id} className="flex items-center gap-2 text-xs">
                        <code className="flex-1 truncate bg-muted rounded px-2 py-1 font-mono">{v.key_name}</code>
                        {v.is_secret
                            ? <span className="text-muted-foreground">••••••</span>
                            : <span className="flex-1 truncate text-muted-foreground">{v.value}</span>}
                        <button onClick={() => handleDeleteEnv(v.id)} className="text-destructive hover:opacity-70 p-1">
                            <Trash2 className="w-3.5 h-3.5" />
                        </button>
                    </div>
                ))}
                <div className="space-y-2 pt-1 border-t border-border">
                    <div className="flex gap-2">
                        <Input placeholder={t('workbench.sites.env_key')} value={newKey} onChange={e => setNewKey(e.target.value.toUpperCase())} className="h-8 text-xs font-mono" />
                        <Input placeholder={t('workbench.sites.env_value')} value={newValue} onChange={e => setNewValue(e.target.value)} className="h-8 text-xs" type={newSecret ? 'password' : 'text'} />
                    </div>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                            <Switch checked={newSecret} onCheckedChange={setNewSecret} className="h-4 w-7" />
                            {t('workbench.sites.env_secret')}
                        </div>
                        <Button size="sm" variant="outline" onClick={handleAddEnv} disabled={!newKey.trim()} className="h-7 text-xs gap-1">
                            <Plus className="w-3 h-3" />{t('workbench.sites.env_add')}
                        </Button>
                    </div>
                </div>
            </div>

            <Separator />

            {/* Domaines */}
            <div className="space-y-3">
                <h4 className="text-sm font-medium">{t('workbench.sites.domains_section')}</h4>
                {domains.map(d => (
                    <div key={d.uuid} className="flex items-center gap-2 text-sm">
                        <Globe className="w-3.5 h-3.5 text-primary shrink-0" />
                        <span className="flex-1 font-mono text-xs truncate">{d.domain}</span>
                        {d.is_primary && <Badge variant="secondary" className="text-[10px] h-4 px-1.5">{t('workbench.sites.domain_primary')}</Badge>}
                        <button onClick={() => handleDeleteDomain(d.uuid)} className="text-destructive hover:opacity-70 p-1">
                            <Trash2 className="w-3.5 h-3.5" />
                        </button>
                    </div>
                ))}
                <p className="text-xs text-muted-foreground">{t('workbench.sites.domain_dns_hint')}</p>
                <div className="flex gap-2">
                    <Input placeholder={t('workbench.sites.domain_placeholder')} value={newDomain} onChange={e => setNewDomain(e.target.value)} className="h-8 text-xs" />
                    <Button size="sm" variant="outline" onClick={handleAddDomain} disabled={!newDomain.trim()} className="h-8 gap-1 text-xs">
                        <Plus className="w-3 h-3" />{t('workbench.sites.domain_add')}
                    </Button>
                </div>
            </div>
        </div>
    );
}

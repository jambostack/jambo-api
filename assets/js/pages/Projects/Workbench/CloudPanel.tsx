// assets/js/pages/Projects/Workbench/CloudPanel.tsx
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Cloud, Loader2, ExternalLink, CheckCircle2, AlertCircle, Globe, Copy } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import { toast } from 'sonner';

interface DomainStatus { domain: string; verified: boolean; sslStatus: string; }
interface HostedStatus {
    app_uuid: string;
    status: string;
    url: string;
    subdomain: string;
    domains: DomainStatus[];
}
interface Props { projectUuid: string; workbenchUuid?: string; }

export default function CloudPanel({ projectUuid, workbenchUuid }: Props) {
    const t = useTranslation();
    const [hosted, setHosted] = useState<HostedStatus | null>(null);
    const [deploying, setDeploying] = useState(false);
    const [newDomain, setNewDomain] = useState('');
    const [challenge, setChallenge] = useState<{ name: string; value: string } | null>(null);

    const hasFiles = Boolean(workbenchUuid);

    const loadStatus = () => {
        if (!workbenchUuid) return;
        fetch(`/api/projects/${projectUuid}/workbench/${workbenchUuid}/cloud/status`)
            .then(r => r.json())
            .then((d: { hosted: HostedStatus | null }) => setHosted(d.hosted))
            .catch(() => {});
    };

    useEffect(loadStatus, [projectUuid, workbenchUuid]);

    const handleDeploy = async () => {
        if (!workbenchUuid) return;
        setDeploying(true);
        try {
            const res = await fetch(`/api/projects/${projectUuid}/workbench/${workbenchUuid}/cloud/deploy`, { method: 'POST' });
            const d = await res.json() as { url?: string; error?: string; status?: string };
            if (!res.ok) { toast.error(d.error ?? t('workbench.cloud.error')); return; }
            toast.success(t('workbench.cloud.deployed'));
            loadStatus();
        } catch { toast.error(t('workbench.cloud.error')); }
        finally { setDeploying(false); }
    };

    const handleAddDomain = async () => {
        if (!workbenchUuid || !newDomain.trim()) return;
        const res = await fetch(`/api/projects/${projectUuid}/workbench/${workbenchUuid}/cloud/domains`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ domain: newDomain.trim() }),
        });
        const d = await res.json() as { record_name?: string; record_value?: string; error?: string };
        if (!res.ok) { toast.error(d.error ?? t('workbench.cloud.error')); return; }
        setChallenge({ name: d.record_name!, value: d.record_value! });
        setNewDomain('');
        loadStatus();
    };

    const copy = (text: string) => { navigator.clipboard.writeText(text); toast.success('Copié'); };

    if (!hasFiles) {
        return (
            <div className="flex items-center gap-2 rounded-lg bg-amber-500/10 border border-amber-500/20 p-3 text-sm text-amber-600 dark:text-amber-400">
                <AlertCircle className="w-4 h-4 shrink-0" />
                {t('workbench.deploy.no_files')}
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="rounded-lg border bg-muted/20 p-4 space-y-3">
                <div className="flex items-center gap-2">
                    <Cloud className="w-4 h-4 text-violet-500" />
                    <span className="text-sm font-medium">{t('workbench.cloud.title')}</span>
                    {hosted && (
                        <Badge variant={hosted.status === 'running' ? 'default' : 'secondary'} className="ml-auto text-xs">
                            {hosted.status}
                        </Badge>
                    )}
                </div>
                <p className="text-xs text-muted-foreground">{t('workbench.cloud.subtitle')}</p>

                {hosted?.url && hosted.status === 'running' && (
                    <a href={hosted.url} target="_blank" rel="noopener noreferrer"
                       className="flex items-center gap-1.5 text-xs text-violet-500 underline">
                        <ExternalLink className="w-3 h-3" />{hosted.url}
                    </a>
                )}

                <Button className="w-full" size="sm" onClick={handleDeploy} disabled={deploying}>
                    {deploying
                        ? <><Loader2 className="w-4 h-4 mr-2 animate-spin" />{t('workbench.cloud.deploying')}</>
                        : <><Cloud className="w-4 h-4 mr-2" />{hosted ? t('workbench.cloud.redeploy') : t('workbench.cloud.deploy')}</>}
                </Button>
            </div>

            {hosted && (
                <div className="rounded-lg border p-4 space-y-3">
                    <div className="flex items-center gap-2">
                        <Globe className="w-4 h-4" />
                        <span className="text-sm font-medium">{t('workbench.cloud.custom_domains')}</span>
                    </div>

                    {hosted.domains.map(d => (
                        <div key={d.domain} className="flex items-center justify-between text-xs">
                            <span className="font-mono">{d.domain}</span>
                            <Badge variant={d.verified ? 'default' : 'secondary'} className="gap-1">
                                {d.verified ? <CheckCircle2 className="w-3 h-3" /> : null}
                                {d.verified ? t('workbench.cloud.verified') : t('workbench.cloud.pending')}
                            </Badge>
                        </div>
                    ))}

                    <div className="flex gap-2">
                        <Input placeholder="monsite.com" value={newDomain}
                               onChange={e => setNewDomain(e.target.value)} className="text-sm h-8" />
                        <Button size="sm" onClick={handleAddDomain} disabled={!newDomain.trim()}>
                            {t('workbench.cloud.add_domain')}
                        </Button>
                    </div>

                    {challenge && (
                        <div className="rounded bg-muted/40 p-3 text-xs space-y-2">
                            <p className="text-muted-foreground">{t('workbench.cloud.dns_instructions')}</p>
                            <div className="flex items-center justify-between gap-2">
                                <code className="truncate">TXT {challenge.name}</code>
                                <Button size="icon" variant="ghost" className="h-6 w-6" onClick={() => copy(challenge.name)}>
                                    <Copy className="w-3 h-3" />
                                </Button>
                            </div>
                            <div className="flex items-center justify-between gap-2">
                                <code className="truncate">{challenge.value}</code>
                                <Button size="icon" variant="ghost" className="h-6 w-6" onClick={() => copy(challenge.value)}>
                                    <Copy className="w-3 h-3" />
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            )}

            <Separator />
            <p className="text-xs text-muted-foreground text-center">{t('workbench.cloud.footer')}</p>
        </div>
    );
}

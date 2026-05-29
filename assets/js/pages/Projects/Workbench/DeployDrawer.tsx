// assets/js/pages/Projects/Workbench/DeployDrawer.tsx
import { useState, useEffect } from 'react';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Download, ExternalLink, Loader2, CheckCircle2, AlertCircle, Link2, Link2Off } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import { toast } from 'sonner';
import CloudPanel from './CloudPanel';

interface ProviderStatus {
    label: string;
    connected: boolean;
}

interface Props {
    open: boolean;
    onClose: () => void;
    projectUuid: string;
    workbenchUuid?: string;
}

export default function DeployDrawer({ open, onClose, projectUuid, workbenchUuid }: Props) {
    const t = useTranslation();
    const [providers, setProviders] = useState<Record<string, ProviderStatus>>({});
    const [deploying, setDeploying] = useState<string | null>(null);
    const [deployUrl, setDeployUrl] = useState<string | null>(null);
    const [downloading, setDownloading] = useState(false);

    const hasFiles = Boolean(workbenchUuid);

    useEffect(() => {
        if (!open) return;
        fetch(`/api/projects/${projectUuid}/workbench/deploy-status`)
            .then(r => r.json())
            .then((data: { providers: Record<string, ProviderStatus> }) => setProviders(data.providers))
            .catch(() => {});
    }, [open, projectUuid]);

    const handleExport = async () => {
        if (!workbenchUuid) return;
        setDownloading(true);
        try {
            const res = await fetch(`/api/projects/${projectUuid}/workbench/${workbenchUuid}/export`);
            if (!res.ok) {
                const err = await res.json() as { error?: string };
                toast.error(err.error ?? t('workbench.deploy.error'));
                return;
            }
            const blob = await res.blob();
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            const disposition = res.headers.get('content-disposition') ?? '';
            const match = disposition.match(/filename="?([^"]+)"?/);
            a.download = match ? match[1] : 'jambo-app.zip';
            a.href = url;
            a.click();
            URL.revokeObjectURL(url);
            toast.success('ZIP téléchargé avec succès');
        } catch {
            toast.error(t('workbench.deploy.error'));
        } finally {
            setDownloading(false);
        }
    };

    const handleConnect = (provider: string) => {
        const returnUrl = window.location.pathname;
        window.location.href = `/api/deploy/oauth/connect/${provider}?return=${encodeURIComponent(returnUrl)}`;
    };

    const handleDisconnect = async (provider: string) => {
        await fetch(`/api/deploy/oauth/disconnect/${provider}`, { method: 'POST' });
        setProviders(prev => ({ ...prev, [provider]: { ...prev[provider], connected: false } }));
        toast.success(`${providers[provider]?.label} déconnecté`);
    };

    const handleDeploy = async (provider: string) => {
        if (!workbenchUuid) return;
        setDeploying(provider);
        setDeployUrl(null);
        try {
            const res  = await fetch(`/api/projects/${projectUuid}/workbench/${workbenchUuid}/deploy/${provider}`, { method: 'POST' });
            const data = await res.json() as { deploy_url?: string; error?: string };
            if (!res.ok) {
                toast.error(data.error ?? t('workbench.deploy.error'));
                return;
            }
            setDeployUrl(data.deploy_url ?? null);
            toast.success(t('workbench.deploy.success'));
        } catch {
            toast.error(t('workbench.deploy.error'));
        } finally {
            setDeploying(null);
        }
    };

    const providerIcons: Record<string, string> = {
        vercel: '▲',
        netlify: '🟦',
        railway: '🚂',
    };

    return (
        <Sheet open={open} onOpenChange={val => !val && onClose()}>
            <SheetContent side="right" className="w-[420px] overflow-y-auto">
                <SheetHeader className="pb-4">
                    <SheetTitle>{t('workbench.deploy.title')}</SheetTitle>
                    <SheetDescription>{t('workbench.deploy.subtitle')}</SheetDescription>
                </SheetHeader>

                {!hasFiles && (
                    <div className="flex items-center gap-2 rounded-lg bg-amber-500/10 border border-amber-500/20 p-3 text-sm text-amber-600 dark:text-amber-400 mb-4">
                        <AlertCircle className="w-4 h-4 shrink-0" />
                        {t('workbench.deploy.no_files')}
                    </div>
                )}

                <Tabs defaultValue="export" className="w-full">
                    <TabsList className="w-full mb-4">
                        <TabsTrigger value="export" className="flex-1 text-xs">Export</TabsTrigger>
                        <TabsTrigger value="providers" className="flex-1 text-xs">1-clic</TabsTrigger>
                        <TabsTrigger value="cloud" className="flex-1 text-xs">Jambo Cloud</TabsTrigger>
                    </TabsList>

                    <TabsContent value="export" className="space-y-4">
                        <div className="rounded-lg border bg-muted/20 p-4 space-y-2">
                            <p className="text-sm font-medium">{t('workbench.deploy.export_zip')}</p>
                            <p className="text-xs text-muted-foreground">{t('workbench.deploy.export_desc')}</p>
                            <Button
                                className="w-full mt-2"
                                variant="outline"
                                onClick={handleExport}
                                disabled={!hasFiles || downloading}
                            >
                                {downloading
                                    ? <><Loader2 className="w-4 h-4 mr-2 animate-spin" />{t('workbench.deploy.downloading')}</>
                                    : <><Download className="w-4 h-4 mr-2" />{t('workbench.deploy.export_zip')}</>
                                }
                            </Button>
                        </div>

                        <div className="rounded-lg border bg-muted/10 p-3 text-xs text-muted-foreground space-y-1">
                            <p className="font-medium text-foreground">Contenu de l'archive :</p>
                            <p>📁 Code source de l'app (framework)</p>
                            <p>🐳 Dockerfile multi-stage optimisé</p>
                            <p>📋 docker-compose.yml</p>
                            <p>⚙️ .env.example</p>
                            <p>🚀 .github/workflows/deploy.yml</p>
                        </div>
                    </TabsContent>

                    <TabsContent value="providers" className="space-y-3">
                        {deployUrl && (
                            <div className="flex items-center gap-2 rounded-lg bg-green-500/10 border border-green-500/20 p-3 mb-2">
                                <CheckCircle2 className="w-4 h-4 text-green-500 shrink-0" />
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm text-green-600 dark:text-green-400 font-medium">{t('workbench.deploy.success')}</p>
                                    <a href={deployUrl} target="_blank" rel="noopener noreferrer"
                                       className="text-xs text-green-600 dark:text-green-400 underline truncate block">
                                        {deployUrl}
                                    </a>
                                </div>
                                <Button size="sm" variant="outline" asChild>
                                    <a href={deployUrl} target="_blank" rel="noopener noreferrer">
                                        <ExternalLink className="w-3 h-3" />
                                    </a>
                                </Button>
                            </div>
                        )}

                        {Object.entries(providers).length === 0 && (
                            <div className="text-center py-8 text-muted-foreground text-sm">
                                <Loader2 className="w-4 h-4 animate-spin mx-auto mb-2" />
                                Chargement…
                            </div>
                        )}

                        {Object.entries(providers).map(([id, info]) => (
                            <div key={id} className="rounded-lg border p-4 space-y-3">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <span className="text-lg">{providerIcons[id] ?? '🌐'}</span>
                                        <span className="font-medium text-sm">{info.label}</span>
                                    </div>
                                    <Badge variant={info.connected ? 'default' : 'secondary'} className="text-xs gap-1">
                                        {info.connected
                                            ? <><CheckCircle2 className="w-3 h-3" />{t('workbench.deploy.connected')}</>
                                            : t('workbench.deploy.not_connected')
                                        }
                                    </Badge>
                                </div>

                                {info.connected ? (
                                    <div className="flex gap-2">
                                        <Button
                                            className="flex-1"
                                            size="sm"
                                            onClick={() => handleDeploy(id)}
                                            disabled={!hasFiles || deploying !== null}
                                        >
                                            {deploying === id
                                                ? <><Loader2 className="w-3.5 h-3.5 mr-1.5 animate-spin" />{t('workbench.deploy.deploying')}</>
                                                : <><ExternalLink className="w-3.5 h-3.5 mr-1.5" />{t(`workbench.deploy.${id}`)}</>
                                            }
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => handleDisconnect(id)}
                                            className="text-muted-foreground hover:text-destructive">
                                            <Link2Off className="w-3.5 h-3.5" />
                                        </Button>
                                    </div>
                                ) : (
                                    <Button variant="outline" size="sm" className="w-full gap-2" onClick={() => handleConnect(id)}>
                                        <Link2 className="w-3.5 h-3.5" />
                                        {t(`workbench.deploy.connect_${id}`)}
                                    </Button>
                                )}
                            </div>
                        ))}

                        <Separator />
                        <p className="text-xs text-muted-foreground text-center">
                            Les tokens OAuth sont chiffrés AES-256-GCM côté serveur
                        </p>
                    </TabsContent>

                    <TabsContent value="cloud">
                        <CloudPanel projectUuid={projectUuid} workbenchUuid={workbenchUuid} />
                    </TabsContent>
                </Tabs>
            </SheetContent>
        </Sheet>
    );
}

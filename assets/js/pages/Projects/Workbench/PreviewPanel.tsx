import { useStore } from '@nanostores/react';
import { previewUrlStore, statusStore, frameworkStore } from '@/stores/workbench';
import { isWebContainerSupported, mountAndRun, resetWebContainer } from '@/lib/webcontainer';
import { useEffect, useRef, useState, useMemo } from 'react';
import { Loader2, AlertCircle, Monitor, RefreshCw } from 'lucide-react';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import Console from '@/components/Console';

interface Props {
    starterFiles: Record<string, string>;
    installCommand: string;
    devCommand: string;
}

export default function PreviewPanel({ starterFiles, installCommand, devCommand }: Props) {
    const t = useTranslation();
    const previewUrl = useStore(previewUrlStore);
    const status = useStore(statusStore);
    const framework = useStore(frameworkStore);
    const [terminalOutput, setTerminalOutput] = useState<string[]>([]);
    const mountedRef = useRef(false);
    const prevFrameworkRef = useRef<string | null>(null);
    const [retryCount, setRetryCount] = useState(0);
    const isSupported = isWebContainerSupported();

    // Mappe le statut du Workbench vers le statut de la Console
    const consoleStatus = useMemo(() => {
        switch (status) {
            case 'wc-booting':
            case 'wc-installing':
            case 'generating':
                return 'running';
            case 'error':
                return 'error';
            default:
                return 'idle';
        }
    }, [status]);

    useEffect(() => {
        if (!isSupported || Object.keys(starterFiles).length === 0) return;

        const frameworkChanged = prevFrameworkRef.current !== null && prevFrameworkRef.current !== framework;
        const shouldRemount = !mountedRef.current || frameworkChanged;

        if (!shouldRemount) return;

        if (frameworkChanged && mountedRef.current) {
            resetWebContainer();
            mountedRef.current = false;
            setTerminalOutput([]);
        }

        mountedRef.current = true;
        prevFrameworkRef.current = framework;

        mountAndRun(
            starterFiles,
            installCommand,
            devCommand,
            () => {},
            (line) => setTerminalOutput(prev => {
                const next = prev.length >= 200 ? prev.slice(-199) : prev;
                return [...next, line];
            }),
        ).catch(err => {
            setTerminalOutput(prev => [...prev, `Error: ${(err as Error).message}`]);
            statusStore.set('error');
            mountedRef.current = false;
        });

        return () => {
            resetWebContainer();
            mountedRef.current = false;
        };
    }, [isSupported, starterFiles, framework, retryCount]);

    if (!isSupported) {
        return (
            <div className="flex items-center justify-center h-full p-6">
                <Alert className="max-w-md">
                    <AlertCircle className="h-4 w-4" />
                    <AlertTitle>{t('workbench.no_chromium_title')}</AlertTitle>
                    <AlertDescription>{t('workbench.no_chromium_desc')}</AlertDescription>
                </Alert>
            </div>
        );
    }

    const statusLabel: Record<string, string> = {
        'wc-booting': t('workbench.booting'),
        'wc-installing': t('workbench.installing'),
        'wc-running': t('workbench.starting_server'),
        'error': t('common.error'),
        'generating': t('workbench.generating'),
    };

    return (
        <div className="flex flex-col h-full">
            {previewUrl ? (
                <iframe src={previewUrl} className="flex-1 w-full border-0 aspect-video lg:aspect-auto" title="Workbench preview"
                    sandbox="allow-same-origin allow-scripts allow-forms allow-popups" />
            ) : (
                <div className="flex flex-col items-center justify-center flex-1 gap-3 text-muted-foreground">
                    {status === 'idle' ? (
                        <><Monitor className="w-10 h-10 opacity-20" /><p className="text-sm">{t('workbench.preview_empty')}</p></>
                    ) : status === 'error' ? (
                        <div className="flex flex-col items-center gap-3">
                            <AlertCircle className="w-8 h-8 text-destructive" />
                            <p className="text-sm text-destructive">{t('workbench.preview_error')}</p>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    mountedRef.current = false;
                                    setRetryCount(c => c + 1);
                                    statusStore.set('idle');
                                }}
                                className="gap-1.5"
                            >
                                <RefreshCw className="w-3.5 h-3.5" />
                                {t('workbench.preview_retry')}
                            </Button>
                        </div>
                    ) : (
                        <><Loader2 className="w-6 h-6 animate-spin text-violet-500" /><p className="text-sm">{statusLabel[status] ?? ''}</p></>
                    )}
                </div>
            )}
            {terminalOutput.length > 0 && (
                <Console
                    lines={terminalOutput}
                    status={consoleStatus}
                    height="h-36"
                    maxLines={500}
                    className="shrink-0 border-t-0 rounded-t-none"
                />
            )}
        </div>
    );
}

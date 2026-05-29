import { useStore } from '@nanostores/react';
import { previewUrlStore, statusStore } from '@/stores/workbench';
import { isWebContainerSupported, mountAndRun } from '@/lib/webcontainer';
import { useEffect, useRef, useState } from 'react';
import { Loader2, AlertCircle, Monitor } from 'lucide-react';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';
import { useTranslation } from '@/lib/i18n';

interface Props {
    starterFiles: Record<string, string>;
    installCommand: string;
    devCommand: string;
}

export default function PreviewPanel({ starterFiles, installCommand, devCommand }: Props) {
    const t = useTranslation();
    const previewUrl = useStore(previewUrlStore);
    const status = useStore(statusStore);
    const [terminalOutput, setTerminalOutput] = useState<string[]>([]);
    const terminalRef = useRef<HTMLDivElement>(null);
    const mountedRef = useRef(false);
    const isSupported = isWebContainerSupported();

    useEffect(() => {
        if (!isSupported || mountedRef.current || Object.keys(starterFiles).length === 0) return;
        mountedRef.current = true;

        mountAndRun(
            starterFiles,
            installCommand,
            devCommand,
            () => {},
            (line) => setTerminalOutput(prev => [...prev.slice(-200), line]),
        ).catch(err => {
            setTerminalOutput(prev => [...prev, `Error: ${(err as Error).message}`]);
            statusStore.set('error');
        });
    }, [isSupported, starterFiles]);

    useEffect(() => {
        terminalRef.current?.scrollTo(0, terminalRef.current.scrollHeight);
    }, [terminalOutput]);

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
    };

    return (
        <div className="flex flex-col h-full">
            {previewUrl ? (
                <iframe src={previewUrl} className="flex-1 w-full border-0" title="Workbench preview"
                    sandbox="allow-same-origin allow-scripts allow-forms allow-popups" />
            ) : (
                <div className="flex flex-col items-center justify-center flex-1 gap-3 text-muted-foreground">
                    {status === 'idle' ? (
                        <><Monitor className="w-10 h-10 opacity-20" /><p className="text-sm">La preview apparaîtra ici après la première génération</p></>
                    ) : (
                        <><Loader2 className="w-6 h-6 animate-spin text-violet-500" /><p className="text-sm">{statusLabel[status] ?? ''}</p></>
                    )}
                </div>
            )}
            {terminalOutput.length > 0 && (
                <div ref={terminalRef} className="h-24 overflow-y-auto bg-black/90 p-2 font-mono text-xs text-green-400 border-t border-border">
                    {terminalOutput.map((line, i) => <div key={i}>{line}</div>)}
                </div>
            )}
        </div>
    );
}

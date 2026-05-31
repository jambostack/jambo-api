import { useEffect, useState, useRef } from 'react';
import { useStore } from '@nanostores/react';
import { filesStore, upsertFile } from '@/stores/workbench';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Loader2, History, RotateCcw, Clock, ChevronRight, AlertTriangle } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

interface SnapshotData {
    uuid: string;
    number: number;
    files: Record<string, string>;
    created_at: string;
}

interface Props {
    projectUuid: string;
    workbenchUuid: string | undefined;
    open: boolean;
    onClose: () => void;
}

export default function SnapshotTimeline({ projectUuid, workbenchUuid, open, onClose }: Props) {
    const t = useTranslation();
    const files = useStore(filesStore);
    const [snapshots, setSnapshots] = useState<SnapshotData[]>([]);
    const [loading, setLoading] = useState(false);
    const [restoring, setRestoring] = useState<string | null>(null);
    const [confirmRestore, setConfirmRestore] = useState<SnapshotData | null>(null);
    const mountedRef = useRef(true);

    useEffect(() => {
        return () => { mountedRef.current = false; };
    }, []);

    useEffect(() => {
        if (!open || !workbenchUuid) return;
        setLoading(true);
        const controller = new AbortController();
        fetch(`/api/projects/${projectUuid}/workbench/${workbenchUuid}/snapshots`, {
            headers: { 'Content-Type': 'application/json' },
            signal: controller.signal,
        })
            .then(async res => {
                if (!res.ok) throw new Error('Failed');
                return res.json();
            })
            .then((d: { data?: SnapshotData[] }) => {
                if (!controller.signal.aborted && mountedRef.current) {
                    setSnapshots(d.data ?? []);
                }
            })
            .catch(err => {
                if (err.name !== 'AbortError') toast.error(t('common.error'));
            })
            .finally(() => {
                if (mountedRef.current) setLoading(false);
            });
        return () => controller.abort();
    }, [open, projectUuid, workbenchUuid, t]);

    const handleSaveSnapshot = async () => {
        if (!workbenchUuid) return;
        const storeFiles = filesStore.get();
        const fileMap: Record<string, string> = {};
        for (const [path, f] of Object.entries(storeFiles)) {
            fileMap[path] = f.content;
        }
        if (Object.keys(fileMap).length === 0) {
            toast.error(t('workbench.errors.no_files_export'));
            return;
        }
        try {
            const res = await fetch(`/api/projects/${projectUuid}/workbench/${workbenchUuid}/snapshots`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ files: fileMap }),
            });
            if (!res.ok) throw new Error('Failed');
            const d = await res.json() as { data?: { uuid: string; number: number; created_at: string } };
            if (mountedRef.current) {
                toast.success(t('workbench.snapshots_created'));
                setSnapshots(prev => [{
                    uuid: d.data!.uuid,
                    number: d.data!.number,
                    files: fileMap,
                    created_at: d.data!.created_at,
                }, ...prev]);
            }
        } catch {
            toast.error(t('common.error'));
        }
    };

    const handleRestore = async (snapshot: SnapshotData) => {
        if (!workbenchUuid) return;
        setRestoring(snapshot.uuid);
        try {
            const res = await fetch(
                `/api/projects/${projectUuid}/workbench/${workbenchUuid}/snapshots/${snapshot.uuid}/restore`,
                { method: 'POST', headers: { 'Content-Type': 'application/json' } },
            );
            if (!res.ok) throw new Error('Failed');
            const d = await res.json() as { data?: { files: Record<string, string> } };
            if (d.data?.files) {
                // Vider le store pour supprimer les fichiers absents du snapshot
                filesStore.set({});
                for (const [path, content] of Object.entries(d.data.files)) {
                    upsertFile(path, content as string);
                }
            }
            if (mountedRef.current) {
                toast.success(t('workbench.snapshots_restored'));
                setConfirmRestore(null);
            }
        } catch {
            toast.error(t('common.error'));
        } finally {
            if (mountedRef.current) setRestoring(null);
        }
    };

    return (
        <>
            <Dialog open={open} onOpenChange={val => !val && onClose()}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <History className="w-4 h-4" />
                            {t('workbench.snapshots_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('workbench.snapshots_description')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3 max-h-[50vh] overflow-y-auto">
                        <Button variant="outline" size="sm" className="w-full gap-1.5" onClick={handleSaveSnapshot} disabled={Object.keys(files).length === 0}>
                            <Clock className="w-3.5 h-3.5" />
                            {t('workbench.snapshots_create')}
                        </Button>

                        {loading && (
                            <div className="flex items-center justify-center py-4">
                                <Loader2 className="w-5 h-5 animate-spin text-muted-foreground" />
                            </div>
                        )}

                        {!loading && snapshots.length === 0 && (
                            <p className="text-center text-xs text-muted-foreground py-4">{t('workbench.snapshots_empty')}</p>
                        )}

                        {snapshots.map(sn => (
                            <div key={sn.uuid} className={cn(
                                'flex items-center gap-3 p-3 rounded-lg border border-border bg-muted/20 text-xs transition-colors hover:bg-muted/40',
                            )}>
                                <div className="flex items-center gap-1.5 text-muted-foreground shrink-0">
                                    <span className="font-mono text-xs font-semibold text-foreground">#{sn.number}</span>
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-muted-foreground truncate">
                                        {new Date(sn.created_at).toLocaleString(navigator.language)}
                                    </p>
                                    <p className="text-[10px] text-muted-foreground/60">
                                        {Object.keys(sn.files).length} fichiers
                                    </p>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 text-[10px] gap-1 shrink-0"
                                    onClick={() => setConfirmRestore(sn)}
                                    disabled={restoring === sn.uuid}
                                >
                                    {restoring === sn.uuid
                                        ? <Loader2 className="w-3 h-3 animate-spin" />
                                        : <RotateCcw className="w-3 h-3" />}
                                    {t('workbench.snapshots_restore')}
                                </Button>
                            </div>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>

            <Dialog open={confirmRestore !== null} onOpenChange={val => !val && setConfirmRestore(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-sm">
                            <AlertTriangle className="w-4 h-4 text-amber-500" />
                            {t('workbench.snapshots_restore')} — snapshot #{confirmRestore?.number}
                        </DialogTitle>
                        <DialogDescription>
                            {t('workbench.snapshots_restore_confirm')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setConfirmRestore(null)}>
                            {t('common.cancel')}
                        </Button>
                        <Button size="sm" variant="default" onClick={() => confirmRestore && handleRestore(confirmRestore)}>
                            <RotateCcw className="w-3.5 h-3.5 mr-1" />
                            {t('workbench.snapshots_restore')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

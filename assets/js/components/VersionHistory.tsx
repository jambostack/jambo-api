import { useState, useEffect } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { History, RotateCcw, GitCompare, Loader2, Clock, User } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Version { uuid: string; versionNumber: number; label: string | null; createdAt: string; createdBy: string | null; }
interface Props { projectUuid: string; collectionSlug: string; entryUuid: string; onRestored?: () => void; }

export default function VersionHistory({ projectUuid, collectionSlug, entryUuid, onRestored }: Props) {
  const t = useTranslation();
  const [versions, setVersions] = useState<Version[]>([]);
  const [loading, setLoading] = useState(false);
  const [restoring, setRestoring] = useState<number | null>(null);
  const [diff, setDiff] = useState<any>(null);
  const [showDiff, setShowDiff] = useState(false);
  const [diffVersions, setDiffVersions] = useState({ v1: 1, v2: 0 });

  async function loadVersions() {
    setLoading(true);
    try {
      const { data } = await axios.get(`/api/projects/${projectUuid}/collections/${collectionSlug}/entries/${entryUuid}/versions`);
      setVersions(data);
      if (data.length > 1) setDiffVersions({ v1: data[data.length - 1]?.versionNumber || 1, v2: data[0]?.versionNumber || 1 });
    } catch { toast.error(t('common.error')); }
    finally { setLoading(false); }
  }

  useEffect(() => { loadVersions(); }, [entryUuid]);

  async function handleRestore(versionNumber: number) {
    setRestoring(versionNumber);
    try {
      await axios.post(`/api/projects/${projectUuid}/collections/${collectionSlug}/entries/${entryUuid}/versions/${versionNumber}/restore`);
      toast.success(t('studio.versions.restored'));
      onRestored?.();
    } catch { toast.error(t('common.error')); }
    finally { setRestoring(null); }
  }

  async function handleDiff() {
    try {
      const { data } = await axios.get(`/api/projects/${projectUuid}/collections/${collectionSlug}/entries/${entryUuid}/versions/diff`, { params: diffVersions });
      setDiff(data); setShowDiff(true);
    } catch { toast.error(t('common.error')); }
  }

  const fmtDate = (iso: string) => {
    const d = new Date(iso);
    return d.toLocaleDateString(undefined, { day: '2-digit', month: 'short' }) + ' · ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
  };

  return (
    <>
      <div className="jambo-rise rounded-xl border bg-card shadow-sm">
        {/* En-tête */}
        <div className="flex items-center justify-between px-4 pt-4 pb-3">
          <div className="flex items-center gap-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <History className="h-[18px] w-[18px]" />
            </div>
            <div>
              <h3 className="font-display text-sm font-bold leading-tight tracking-tight">{t('studio.versions.title')}</h3>
              <p className="text-xs text-muted-foreground">
                {versions.length > 0
                  ? t('studio.versions.count', { count: String(versions.length) })
                  : t('studio.versions.subtitle')}
              </p>
            </div>
          </div>
          <Button variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground" onClick={loadVersions} disabled={loading}>
            {loading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RotateCcw className="h-3.5 w-3.5" />}
          </Button>
        </div>

        {/* Timeline */}
        <div className="px-4 pb-3">
          {versions.length === 0 && !loading && (
            <div className="flex flex-col items-center gap-2 py-6 text-center">
              <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                <History className="h-5 w-5 text-muted-foreground" />
              </div>
              <p className="text-xs text-muted-foreground">{t('studio.versions.empty')}</p>
            </div>
          )}

          {versions.length > 0 && (
            <div className="relative max-h-[320px] overflow-y-auto pr-1">
              {/* Ligne verticale du fil */}
              <div className="pointer-events-none absolute bottom-3 left-[15px] top-3 w-px bg-gradient-to-b from-primary/50 via-border to-transparent" aria-hidden="true" />

              <ol className="space-y-1">
                {versions.map((v, i) => {
                  const isCurrent = i === 0;
                  const isRestoring = restoring === v.versionNumber;
                  return (
                    <li
                      key={v.uuid}
                      style={{ animationDelay: `${i * 50}ms` }}
                      className="jambo-rise group relative flex gap-3 rounded-lg py-2 pl-0 pr-1 transition-colors hover:bg-accent/50"
                    >
                      {/* Nœud */}
                      <div className="relative flex w-8 shrink-0 justify-center pt-1.5">
                        <span
                          className={cn(
                            'z-10 h-3 w-3 rounded-full border-2 transition-all',
                            isCurrent
                              ? 'jambo-pulse-ring border-primary bg-primary'
                              : 'border-muted-foreground/40 bg-card group-hover:border-primary/60',
                          )}
                          style={isCurrent ? ({ '--jambo-ring': 'var(--primary)' } as React.CSSProperties) : undefined}
                        />
                      </div>

                      {/* Contenu */}
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <Badge
                            variant={isCurrent ? 'default' : 'secondary'}
                            className={cn('h-5 px-1.5 font-mono text-[11px]', isCurrent && 'bg-primary text-primary-foreground')}
                          >
                            v{v.versionNumber}
                          </Badge>
                          {isCurrent && (
                            <span className="text-[11px] font-medium text-primary">{t('studio.versions.current')}</span>
                          )}
                          {v.label && <span className="truncate text-xs text-muted-foreground">{v.label}</span>}
                        </div>
                        <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-muted-foreground">
                          <span className="inline-flex items-center gap-1"><Clock className="h-3 w-3" />{fmtDate(v.createdAt)}</span>
                          {v.createdBy && <span className="inline-flex items-center gap-1"><User className="h-3 w-3" />{v.createdBy}</span>}
                        </div>
                      </div>

                      {/* Restaurer — visible au survol (sauf version courante) */}
                      {!isCurrent && (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 self-center px-2 text-xs text-muted-foreground opacity-0 transition-opacity hover:text-primary group-hover:opacity-100 focus-visible:opacity-100"
                          disabled={restoring !== null}
                          onClick={() => handleRestore(v.versionNumber)}
                        >
                          {isRestoring ? <Loader2 className="h-3 w-3 animate-spin" /> : <><RotateCcw className="mr-1 h-3 w-3" />{t('studio.versions.restore')}</>}
                        </Button>
                      )}
                    </li>
                  );
                })}
              </ol>
            </div>
          )}
        </div>

        {/* Comparer */}
        {versions.length >= 2 && (
          <div className="border-t px-4 py-3">
            <Button variant="outline" size="sm" className="w-full" onClick={handleDiff}>
              <GitCompare className="mr-1.5 h-3.5 w-3.5" />
              {t('studio.versions.compare', { v1: String(diffVersions.v1), v2: String(diffVersions.v2) })}
            </Button>
          </div>
        )}
      </div>

      {/* Dialog Diff */}
      <Dialog open={showDiff} onOpenChange={setShowDiff}>
        <DialogContent className="max-h-[80vh] max-w-2xl overflow-y-auto">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <span className="flex h-7 w-7 items-center justify-center rounded-md bg-primary/15 text-primary"><GitCompare className="h-4 w-4" /></span>
              {t('studio.versions.diff_title', { v1: String(diff?.version1 ?? '?'), v2: String(diff?.version2 ?? '?') })}
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-2">
            {diff?.changes && Object.entries(diff.changes as Record<string, { from: any; to: any }>).map(([key, change]) => (
              <div key={key} className="rounded-lg border p-3">
                <p className="mb-2 font-mono text-sm font-semibold">{key}</p>
                <div className="grid grid-cols-2 gap-2 text-xs">
                  <div className="rounded bg-red-50 p-2 dark:bg-red-950/30">
                    <span className="font-semibold text-red-600 dark:text-red-400">− v{diff?.version1}</span>
                    <pre className="mt-1 whitespace-pre-wrap break-words">{JSON.stringify(change.from)}</pre>
                  </div>
                  <div className="rounded bg-green-50 p-2 dark:bg-green-950/30">
                    <span className="font-semibold text-green-600 dark:text-green-400">+ v{diff?.version2}</span>
                    <pre className="mt-1 whitespace-pre-wrap break-words">{JSON.stringify(change.to)}</pre>
                  </div>
                </div>
              </div>
            ))}
            {(!diff?.changes || Object.keys(diff.changes).length === 0) && (
              <p className="py-4 text-center text-sm text-muted-foreground">{t('studio.versions.no_diff')}</p>
            )}
          </div>
          <DialogFooter><Button variant="outline" onClick={() => setShowDiff(false)}>{t('studio.versions.close')}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

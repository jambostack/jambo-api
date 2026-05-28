import { useState, useEffect } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { History, RotateCcw, GitCompare, Loader2, Clock, User } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';

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

  return (
    <>
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-sm flex items-center justify-between">
            <span className="flex items-center gap-2"><History className="w-4 h-4" />{t('studio.versions.title')}</span>
            <Button variant="ghost" size="icon" className="h-7 w-7" onClick={loadVersions} disabled={loading}>
              {loading ? <Loader2 className="w-3 h-3 animate-spin" /> : <RotateCcw className="w-3 h-3" />}
            </Button>
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-2 max-h-[300px] overflow-y-auto">
          {versions.length === 0 && !loading && <p className="text-xs text-muted-foreground text-center py-4">{t('studio.versions.empty')}</p>}
          {versions.map(v => (
            <div key={v.uuid} className="flex items-center justify-between p-2 rounded-lg bg-muted/50 hover:bg-muted transition-colors">
              <div><div className="flex items-center gap-2"><Badge variant="secondary" className="text-xs font-mono">v{v.versionNumber}</Badge>{v.label && <span className="text-xs text-muted-foreground">{v.label}</span>}</div>
                <div className="flex items-center gap-3 mt-1 text-xs text-muted-foreground">
                  <span className="flex items-center gap-1"><Clock className="w-3 h-3" />{new Date(v.createdAt).toLocaleDateString()}</span>
                  {v.createdBy && <span className="flex items-center gap-1"><User className="w-3 h-3" />{v.createdBy}</span>}
                </div>
              </div>
              <Button variant="ghost" size="sm" className="h-7 text-xs" disabled={restoring !== null} onClick={() => handleRestore(v.versionNumber)}>
                {restoring === v.versionNumber ? <Loader2 className="w-3 h-3 animate-spin" /> : <RotateCcw className="w-3 h-3 mr-1" />}{t('studio.versions.restore')}
              </Button>
            </div>
          ))}
        </CardContent>
        {versions.length >= 2 && (
          <div className="px-4 pb-3">
            <Button variant="outline" size="sm" className="w-full" onClick={handleDiff}>
              <GitCompare className="w-3 h-3 mr-1" />{t('studio.versions.compare', { v1: String(diffVersions.v1), v2: String(diffVersions.v2) })}
            </Button>
          </div>
        )}
      </Card>

      <Dialog open={showDiff} onOpenChange={setShowDiff}>
        <DialogContent className="max-w-2xl max-h-[80vh] overflow-y-auto">
          <DialogHeader><DialogTitle className="flex items-center gap-2"><GitCompare className="w-4 h-4" />{t('studio.versions.diff_title', { v1: String(diff?.version1 ?? '?'), v2: String(diff?.version2 ?? '?') })}</DialogTitle></DialogHeader>
          <div className="space-y-2">
            {diff?.changes && Object.entries(diff.changes as Record<string, { from: any; to: any }>).map(([key, change]) => (
              <div key={key} className="border rounded-lg p-3">
                <p className="text-sm font-mono font-semibold mb-2">{key}</p>
                <div className="grid grid-cols-2 gap-2 text-xs">
                  <div className="bg-red-50 dark:bg-red-950/30 p-2 rounded"><span className="text-red-600 dark:text-red-400 font-semibold">- v{diff?.version1}</span><pre className="mt-1 whitespace-pre-wrap">{JSON.stringify(change.from)}</pre></div>
                  <div className="bg-green-50 dark:bg-green-950/30 p-2 rounded"><span className="text-green-600 dark:text-green-400 font-semibold">+ v{diff?.version2}</span><pre className="mt-1 whitespace-pre-wrap">{JSON.stringify(change.to)}</pre></div>
                </div>
              </div>
            ))}
            {(!diff?.changes || Object.keys(diff.changes).length === 0) && <p className="text-sm text-muted-foreground text-center py-4">{t('studio.versions.no_diff')}</p>}
          </div>
          <DialogFooter><Button variant="outline" onClick={() => setShowDiff(false)}>{t('studio.versions.close')}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

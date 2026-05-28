import { useState, useEffect } from 'react';
import axios from 'axios';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ScrollText, Clock, AlertCircle, CheckCircle2, XCircle, Eye, Loader2, Wrench } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import type { Project } from '@/types/index.d';

interface AuditEntry {
  uuid: string; toolName: string; status: string; errorMessage: string | null;
  createdBy: string | null; source: string; durationMs: number | null;
  createdAt: string; input: any; output: any;
}

export default function AuditLogsPage({ project }: { project: Project }) {
  const t = useTranslation();
  const [logs, setLogs] = useState<AuditEntry[]>([]);
  const [loading, setLoading] = useState(false);
  const [selectedLog, setSelectedLog] = useState<AuditEntry | null>(null);
  const [totalToday, setTotalToday] = useState(0);

  async function loadLogs() {
    setLoading(true);
    try {
      const { data } = await axios.get(`/api/projects/${project.uuid}/audit-logs?limit=200`);
      setLogs(data.logs || []);
      setTotalToday(data.total_today || 0);
    } catch { /* ignore */ }
    finally { setLoading(false); }
  }

  useEffect(() => { loadLogs(); }, [project.uuid]);

  const StatusIcon = ({ status }: { status: string }) => {
    if (status === 'success') return <CheckCircle2 className="w-4 h-4 text-green-500" />;
    if (status === 'error') return <XCircle className="w-4 h-4 text-red-500" />;
    return <AlertCircle className="w-4 h-4 text-amber-500" />;
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Logs d'audit</h2>
          <p className="text-muted-foreground">Actions IA, MCP et API enregistrées · {totalToday} aujourd'hui</p>
        </div>
        <Button variant="outline" size="sm" onClick={loadLogs} disabled={loading}>
          {loading ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : <ScrollText className="w-4 h-4 mr-2" />}Actualiser
        </Button>
      </div>

      <div className="space-y-2">
        {logs.map(log => (
          <Card key={log.uuid} className="hover:bg-muted/30 transition-colors cursor-pointer" onClick={() => setSelectedLog(log)}>
            <CardContent className="p-3 flex items-center gap-3">
              <StatusIcon status={log.status} />
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <span className="font-mono text-sm font-medium">{log.toolName}</span>
                  <Badge variant="secondary" className="text-xs">{log.source}</Badge>
                  {log.durationMs && <span className="text-xs text-muted-foreground">{log.durationMs}ms</span>}
                </div>
                <div className="flex items-center gap-3 text-xs text-muted-foreground mt-0.5">
                  <span className="flex items-center gap-1"><Clock className="w-3 h-3" />{new Date(log.createdAt).toLocaleString()}</span>
                  {log.createdBy && <span>par {log.createdBy}</span>}
                  {log.errorMessage && <span className="text-red-500 truncate">{log.errorMessage}</span>}
                </div>
              </div>
              <Eye className="w-4 h-4 text-muted-foreground opacity-0 group-hover:opacity-100" />
            </CardContent>
          </Card>
        ))}
        {logs.length === 0 && !loading && (
          <p className="text-sm text-muted-foreground text-center py-8">Aucun log d'audit pour le moment.</p>
        )}
      </div>

      <Dialog open={!!selectedLog} onOpenChange={() => setSelectedLog(null)}>
        <DialogContent className="max-w-2xl max-h-[80vh] overflow-y-auto">
          <DialogHeader><DialogTitle className="flex items-center gap-2"><Wrench className="w-4 h-4" />{selectedLog?.toolName}</DialogTitle></DialogHeader>
          {selectedLog && (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-2 text-sm">
                <div><span className="text-muted-foreground">Source:</span> <Badge variant="secondary">{selectedLog.source}</Badge></div>
                <div><span className="text-muted-foreground">Statut:</span> <Badge variant={selectedLog.status === 'success' ? 'default' : 'destructive'}>{selectedLog.status}</Badge></div>
                <div><span className="text-muted-foreground">Date:</span> {new Date(selectedLog.createdAt).toLocaleString()}</div>
                <div><span className="text-muted-foreground">Durée:</span> {selectedLog.durationMs ? `${selectedLog.durationMs}ms` : '-'}</div>
                {selectedLog.createdBy && <div><span className="text-muted-foreground">Par:</span> {selectedLog.createdBy}</div>}
                {selectedLog.errorMessage && <div className="col-span-2"><span className="text-muted-foreground">Erreur:</span> <span className="text-red-500">{selectedLog.errorMessage}</span></div>}
              </div>
              <div>
                <h4 className="text-sm font-semibold mb-1">Entrée (input)</h4>
                <pre className="text-xs font-mono bg-muted p-3 rounded-md overflow-auto max-h-48">{JSON.stringify(selectedLog.input, null, 2)}</pre>
              </div>
              <div>
                <h4 className="text-sm font-semibold mb-1">Sortie (output)</h4>
                <pre className="text-xs font-mono bg-muted p-3 rounded-md overflow-auto max-h-48">{JSON.stringify(selectedLog.output, null, 2)}</pre>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}

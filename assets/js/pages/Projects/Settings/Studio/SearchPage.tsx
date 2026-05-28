import { useState } from 'react';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Search, Loader2, FileText, Clock, Globe, ExternalLink } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import type { Project, Collection } from '@/types/index.d';

export default function SearchPage({ project, collections }: { project: Project; collections: Collection[] }) {
  const t = useTranslation();
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const [filterCollection, setFilterCollection] = useState('');
  const [filterLocale, setFilterLocale] = useState('');

  async function handleSearch() {
    if (!query.trim()) return;
    setLoading(true);
    try {
      const params: Record<string, string> = { q: query, limit: '50' };
      if (filterCollection) params.collection = filterCollection;
      if (filterLocale) params.locale = filterLocale;
      const { data } = await axios.get(`/api/projects/${project.uuid}/search`, { params });
      setResults(data);
    } catch (e: any) { setResults({ error: e?.response?.data?.error || 'Erreur' }); }
    finally { setLoading(false); }
  }

  const locales = project.locales || ['en'];

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-2xl font-bold tracking-tight">Recherche globale</h2>
        <p className="text-muted-foreground">Recherche full-text dans tout le contenu du projet</p>
      </div>

      <div className="flex gap-3 flex-wrap">
        <div className="flex-1 min-w-[300px]">
          <Input placeholder="Rechercher..." value={query} onChange={e => setQuery(e.target.value)} onKeyDown={e => e.key === 'Enter' && handleSearch()} />
        </div>
        <Select value={filterCollection} onValueChange={setFilterCollection}>
          <SelectTrigger className="w-44"><SelectValue placeholder="Toutes les collections" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="">Toutes les collections</SelectItem>
            {collections.map(c => <SelectItem key={c.slug} value={c.slug}>{c.name}</SelectItem>)}
          </SelectContent>
        </Select>
        <Select value={filterLocale} onValueChange={setFilterLocale}>
          <SelectTrigger className="w-24"><SelectValue placeholder="Locale" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="">Toutes</SelectItem>
            {locales.map((l: string) => <SelectItem key={l} value={l}>{l.toUpperCase()}</SelectItem>)}
          </SelectContent>
        </Select>
        <Button onClick={handleSearch} disabled={loading || !query.trim()}>
          {loading ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : <Search className="w-4 h-4 mr-2" />}Rechercher
        </Button>
      </div>

      {results && !results.error && (
        <div>
          <p className="text-sm text-muted-foreground mb-3">{results.hits?.length ?? results.total ?? 0} résultat(s)</p>
          <div className="space-y-3">
            {(results.hits || []).map((hit: any, i: number) => (
              <Card key={i}>
                <CardContent className="p-4">
                  <div className="flex items-center justify-between mb-2">
                    <Badge variant="outline">{hit._collection || hit.collection}</Badge>
                    <div className="flex items-center gap-3 text-xs text-muted-foreground">
                      <span className="flex items-center gap-1"><Globe className="w-3 h-3" />{hit.locale}</span>
                      <span className="flex items-center gap-1"><Clock className="w-3 h-3" />{hit.updated_at ? new Date(hit.updated_at).toLocaleDateString() : '-'}</span>
                    </div>
                  </div>
                  <div className="space-y-1">
                    {hit._formatted ? Object.entries(hit._formatted).filter(([k]) => !k.startsWith('_')).slice(0, 3).map(([k, v]: any) => (
                      <div key={k}><span className="text-xs font-mono text-muted-foreground">{k}: </span><span className="text-sm" dangerouslySetInnerHTML={{ __html: String(v).substring(0, 200) }} /></div>
                    )) : Object.entries(hit).filter(([k]) => !k.startsWith('_') && k !== 'uuid').slice(0, 3).map(([k, v]) => (
                      <div key={k}><span className="text-xs font-mono text-muted-foreground">{k}: </span><span className="text-sm">{String(v).substring(0, 200)}</span></div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </div>
      )}
      {results?.error && <p className="text-destructive text-sm">{results.error}</p>}
    </div>
  );
}

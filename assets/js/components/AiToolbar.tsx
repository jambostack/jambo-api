import { useState } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Wand2, Languages, Eye, FileText, Globe, Loader2 } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';

interface Props {
  projectUuid: string;
  collectionSlug: string;
  formData: Record<string, any>;
  onContentGenerated: (data: Record<string, any>) => void;
  locales?: string[];
  defaultLocale?: string;
}

export default function AiToolbar({ projectUuid, collectionSlug, formData, onContentGenerated, locales = [], defaultLocale = 'fr' }: Props) {
  const t = useTranslation();
  const [loading, setLoading] = useState<string | null>(null);
  const [showGenerate, setShowGenerate] = useState(false);
  const [showTranslate, setShowTranslate] = useState(false);
  const [brief, setBrief] = useState('');
  const [targetLocale, setTargetLocale] = useState('en');

  async function callAi(endpoint: string, body: Record<string, any>) {
    setLoading(endpoint);
    try {
      const { data } = await axios.post(`/api/projects/${projectUuid}/ai/${endpoint}`, body);
      return data;
    } catch (e: any) {
      toast.error(e?.response?.data?.error || t('common.error'));
      return null;
    } finally {
      setLoading(null);
    }
  }

  async function handleGenerate() {
    const result = await callAi('generate', { brief, collection: collectionSlug, locale: defaultLocale });
    if (result && !result.error) {
      onContentGenerated(result);
      setShowGenerate(false);
      setBrief('');
      toast.success(t('studio.api.schema_applied'));
    }
  }

  async function handleTranslate() {
    const result = await callAi('translate', { content: formData, locale: targetLocale });
    if (result?.translated && !result.translated.error) {
      onContentGenerated(result.translated);
      setShowTranslate(false);
      toast.success(t('studio.api.schema_applied'));
    }
  }

  async function handleSeo() {
    const result = await callAi('seo', { content: formData });
    if (result && !result.error) {
      const seoData: Record<string, any> = {};
      if (result.metaTitle) seoData.meta_title = result.metaTitle;
      if (result.metaDescription) seoData.meta_description = result.metaDescription;
      if (result.slug) seoData.slug = result.slug;
      if (result.keywords) seoData.keywords = result.keywords;
      onContentGenerated(seoData);
      toast.success('SEO généré');
    }
  }

  async function handleSummarize() {
    const textContent = Object.values(formData).filter(v => typeof v === 'string' && v.length > 50).join('\n\n');
    if (!textContent) { toast.error('Aucun contenu texte à résumer'); return; }
    const result = await callAi('summarize', { text: textContent, maxWords: 80 });
    if (result?.summary) {
      onContentGenerated({ summary: result.summary });
      toast.success('Résumé généré');
    }
  }

  const isLoading = loading !== null;

  return (
    <>
      <Card>
        <CardHeader className="pb-2"><CardTitle className="text-sm flex items-center gap-2"><Wand2 className="w-4 h-4 text-purple-500" />AI Assistant</CardTitle></CardHeader>
        <CardContent className="space-y-2">
          <Button variant="outline" size="sm" className="w-full justify-start" disabled={isLoading} onClick={() => setShowGenerate(true)}>
            <Wand2 className="w-3 h-3 mr-2" />{loading === 'generate' ? <Loader2 className="w-3 h-3 animate-spin" /> : null}Générer du contenu
          </Button>
          <Button variant="outline" size="sm" className="w-full justify-start" disabled={isLoading} onClick={() => setShowTranslate(true)}>
            <Languages className="w-3 h-3 mr-2" />{loading === 'translate' ? <Loader2 className="w-3 h-3 animate-spin" /> : null}Traduire
          </Button>
          <Button variant="outline" size="sm" className="w-full justify-start" disabled={isLoading} onClick={handleSeo}>
            <Globe className="w-3 h-3 mr-2" />{loading === 'seo' ? <Loader2 className="w-3 h-3 animate-spin" /> : null}Générer SEO
          </Button>
          <Button variant="outline" size="sm" className="w-full justify-start" disabled={isLoading} onClick={handleSummarize}>
            <FileText className="w-3 h-3 mr-2" />{loading === 'summarize' ? <Loader2 className="w-3 h-3 animate-spin" /> : null}Résumer le contenu
          </Button>
        </CardContent>
      </Card>

      {/* Dialog: Generate */}
      <Dialog open={showGenerate} onOpenChange={setShowGenerate}>
        <DialogContent>
          <DialogHeader><DialogTitle className="flex items-center gap-2"><Wand2 className="w-4 h-4" />Générer du contenu</DialogTitle></DialogHeader>
          <div className="space-y-3">
            <div><Label>Décrivez le contenu à générer</Label><Textarea value={brief} onChange={e => setBrief(e.target.value)} placeholder="Ex: Un article de blog sur les tendances tech 2026, ton professionnel, 500 mots..." rows={5} /></div>
            <p className="text-xs text-muted-foreground">Collection: <Badge variant="secondary">{collectionSlug}</Badge> · Locale: <Badge variant="secondary">{defaultLocale}</Badge></p>
          </div>
          <DialogFooter><Button variant="outline" onClick={() => setShowGenerate(false)}>Annuler</Button><Button onClick={handleGenerate} disabled={!brief || isLoading}>{loading === 'generate' ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : null}Générer</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Dialog: Translate */}
      <Dialog open={showTranslate} onOpenChange={setShowTranslate}>
        <DialogContent>
          <DialogHeader><DialogTitle className="flex items-center gap-2"><Languages className="w-4 h-4" />Traduire le contenu</DialogTitle></DialogHeader>
          <div className="space-y-3">
            <div><Label>Langue cible</Label>
              <Select value={targetLocale} onValueChange={setTargetLocale}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>{(locales.length > 0 ? locales : ['en', 'fr', 'es', 'ar']).filter(l => l !== defaultLocale).map(l => (<SelectItem key={l} value={l}>{l.toUpperCase()}</SelectItem>))}</SelectContent>
              </Select>
            </div>
            <p className="text-xs text-muted-foreground">Le contenu actuel sera traduit dans la langue sélectionnée.</p>
          </div>
          <DialogFooter><Button variant="outline" onClick={() => setShowTranslate(false)}>Annuler</Button><Button onClick={handleTranslate} disabled={isLoading}>{loading === 'translate' ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : null}Traduire</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

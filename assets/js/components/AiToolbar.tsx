import { useState } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Sparkles, Wand2, Languages, FileText, Globe, Loader2, ArrowRight } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Props {
  projectUuid: string;
  collectionSlug: string;
  formData: Record<string, any>;
  onContentGenerated: (data: Record<string, any>) => void;
  locales?: string[];
  defaultLocale?: string;
}

type ActionId = 'generate' | 'translate' | 'seo' | 'summarize';

interface ActionConfig {
  id: ActionId;
  icon: typeof Wand2;
  titleKey: string;
  descKey: string;
  /** classes du tuile d'icône : texte + fond tinté */
  tile: string;
}

const ACTIONS: ActionConfig[] = [
  { id: 'generate',  icon: Wand2,     titleKey: 'studio.ai.generate_btn',  descKey: 'studio.ai.generate_hint_short',  tile: 'text-violet-500 bg-violet-500/10 group-hover:bg-violet-500/20' },
  { id: 'translate', icon: Languages, titleKey: 'studio.ai.translate_btn', descKey: 'studio.ai.translate_hint_short', tile: 'text-sky-500 bg-sky-500/10 group-hover:bg-sky-500/20' },
  { id: 'seo',       icon: Globe,     titleKey: 'studio.ai.seo_btn',       descKey: 'studio.ai.seo_hint_short',       tile: 'text-emerald-500 bg-emerald-500/10 group-hover:bg-emerald-500/20' },
  { id: 'summarize', icon: FileText,  titleKey: 'studio.ai.summarize_btn', descKey: 'studio.ai.summarize_hint_short', tile: 'text-amber-500 bg-amber-500/10 group-hover:bg-amber-500/20' },
];

export default function AiToolbar({ projectUuid, collectionSlug, formData, onContentGenerated, locales = [], defaultLocale = 'fr' }: Props) {
  const t = useTranslation();
  const [loading, setLoading] = useState<ActionId | null>(null);
  const [showGenerate, setShowGenerate] = useState(false);
  const [showTranslate, setShowTranslate] = useState(false);
  const [brief, setBrief] = useState('');
  const [targetLocale, setTargetLocale] = useState('en');

  async function callAi(endpoint: ActionId, body: Record<string, any>) {
    setLoading(endpoint);
    try {
      const { data } = await axios.post(`/api/projects/${projectUuid}/ai/${endpoint}`, body);
      return data;
    } catch (e: any) {
      toast.error(e?.response?.data?.error || t('common.error'));
      return null;
    } finally { setLoading(null); }
  }

  async function handleGenerate() {
    const result = await callAi('generate', { brief, collection: collectionSlug, locale: defaultLocale });
    if (result && !result.error) { onContentGenerated(result); setShowGenerate(false); setBrief(''); toast.success(t('studio.api.schema_applied')); }
  }

  async function handleTranslate() {
    const result = await callAi('translate', { content: formData, locale: targetLocale });
    if (result?.translated && !result.translated.error) { onContentGenerated(result.translated); setShowTranslate(false); toast.success(t('studio.api.schema_applied')); }
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
      toast.success(t('studio.ai.seo_success'));
    }
  }

  async function handleSummarize() {
    const textContent = Object.values(formData).filter(v => typeof v === 'string' && v.length > 50).join('\n\n');
    if (!textContent) { toast.error(t('studio.ai.no_text')); return; }
    const result = await callAi('summarize', { text: textContent, maxWords: 80 });
    if (result?.summary) { onContentGenerated({ summary: result.summary }); toast.success(t('studio.ai.summary_success')); }
  }

  const onAction = (id: ActionId) => {
    if (id === 'generate')  return setShowGenerate(true);
    if (id === 'translate') return setShowTranslate(true);
    if (id === 'seo')       return handleSeo();
    if (id === 'summarize') return handleSummarize();
  };

  const isLoading = loading !== null;

  return (
    <>
      {/* Bord dégradé premium — signale la « couche IA » */}
      <div className="jambo-rise relative rounded-xl bg-gradient-to-br from-violet-500/40 via-fuchsia-500/25 to-transparent p-px shadow-sm">
        <div className="rounded-[calc(0.75rem-1px)] bg-card">
          {/* En-tête */}
          <div className="flex items-center gap-3 px-4 pt-4 pb-3">
            <div className="jambo-aurora flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-violet-500 via-fuchsia-500 to-violet-600 text-white shadow-md shadow-fuchsia-500/30">
              <Sparkles className="h-[18px] w-[18px]" />
            </div>
            <div className="min-w-0">
              <h3 className="font-display text-sm font-bold leading-tight tracking-tight">{t('studio.ai.title')}</h3>
              <p className="truncate text-xs text-muted-foreground">{t('studio.ai.subtitle')}</p>
            </div>
          </div>

          {/* Actions */}
          <div className="space-y-1 px-2 pb-2">
            {ACTIONS.map((action, i) => {
              const Icon = action.icon;
              const busy = loading === action.id;
              return (
                <button
                  key={action.id}
                  type="button"
                  disabled={isLoading}
                  onClick={() => onAction(action.id)}
                  style={{ animationDelay: `${i * 60}ms` }}
                  className={cn(
                    'group jambo-rise flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-left transition-all',
                    'hover:bg-accent/60 disabled:opacity-50 disabled:hover:bg-transparent',
                  )}
                >
                  <span className={cn('flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-all group-hover:scale-105', action.tile)}>
                    {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Icon className="h-4 w-4" />}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-sm font-medium leading-tight">{t(action.titleKey)}</span>
                    <span className="block truncate text-xs text-muted-foreground">{t(action.descKey)}</span>
                  </span>
                  <ArrowRight className="h-4 w-4 shrink-0 -translate-x-1 text-muted-foreground opacity-0 transition-all group-hover:translate-x-0 group-hover:opacity-100" />
                </button>
              );
            })}
          </div>
        </div>
      </div>

      {/* ── Dialog Génération ── */}
      <Dialog open={showGenerate} onOpenChange={setShowGenerate}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <span className="flex h-7 w-7 items-center justify-center rounded-md bg-violet-500/15 text-violet-500"><Wand2 className="h-4 w-4" /></span>
              {t('studio.ai.generate_title')}
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-3">
            <div className="space-y-1.5">
              <Label>{t('studio.ai.generate_desc')}</Label>
              <Textarea value={brief} onChange={e => setBrief(e.target.value)} placeholder={t('studio.ai.generate_ph')} rows={5} />
            </div>
            <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
              {t('studio.ai.collection_label')} <Badge variant="secondary">{collectionSlug}</Badge> ·
              {t('studio.ai.locale_label')} <Badge variant="secondary">{defaultLocale}</Badge>
            </p>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowGenerate(false)}>{t('common.cancel')}</Button>
            <Button onClick={handleGenerate} disabled={!brief || isLoading} className="bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white hover:opacity-90">
              {loading === 'generate' ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Sparkles className="mr-2 h-4 w-4" />}
              {t('studio.ai.generate_action')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Dialog Traduction ── */}
      <Dialog open={showTranslate} onOpenChange={setShowTranslate}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <span className="flex h-7 w-7 items-center justify-center rounded-md bg-sky-500/15 text-sky-500"><Languages className="h-4 w-4" /></span>
              {t('studio.ai.translate_title')}
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-3">
            <div className="space-y-1.5">
              <Label>{t('studio.ai.target_lang')}</Label>
              <Select value={targetLocale} onValueChange={setTargetLocale}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>{(locales.length > 0 ? locales : ['en', 'fr', 'es', 'ar']).filter(l => l !== defaultLocale).map(l => (<SelectItem key={l} value={l}>{l.toUpperCase()}</SelectItem>))}</SelectContent>
              </Select>
            </div>
            <p className="text-xs text-muted-foreground">{t('studio.ai.translate_hint')}</p>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowTranslate(false)}>{t('common.cancel')}</Button>
            <Button onClick={handleTranslate} disabled={isLoading} className="bg-gradient-to-br from-sky-500 to-blue-500 text-white hover:opacity-90">
              {loading === 'translate' ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Languages className="mr-2 h-4 w-4" />}
              {t('studio.ai.translate_action')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

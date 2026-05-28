import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Layout, Image, Type, List, Code2, Download, Copy, Check, Monitor, Columns, FileCode } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import type { Project, Collection } from '@/types/index.d';

type PageSection = { key: string; type: 'hero'|'list'|'detail'|'form'|'grid'|'custom'; title: string; collection?: string; fields?: string[]; customCode?: string; };
type PageTemplate = { key: string; name: string; slug: string; sections: PageSection[]; };

const SECTION_TEMPLATES: Record<string, { labelKey: string; icon: any }> = {
  hero: { labelKey: 'studio.page.hero', icon: Image },
  list: { labelKey: 'studio.page.list', icon: List },
  detail: { labelKey: 'studio.page.detail', icon: FileCode },
  form: { labelKey: 'studio.page.form', icon: Type },
  grid: { labelKey: 'studio.page.grid', icon: Columns },
  custom: { labelKey: 'studio.page.custom', icon: Code2 },
};

function generateSectionCode(section: PageSection, col?: Collection): string {
  const colName = section.collection || 'items';
  switch (section.type) {
    case 'hero':
      return `<section className="relative py-24 bg-gradient-to-br from-primary/10 to-primary/5">
  <div className="container mx-auto px-4 text-center">
    <h1 className="text-5xl font-extrabold tracking-tight mb-4">{data?.title}</h1>
    <p className="text-xl text-muted-foreground max-w-2xl mx-auto">{data?.description}</p>
  </div>
</section>`;
    case 'list':
      return `<div className="container mx-auto px-4 py-12">
  <h2 className="text-3xl font-bold mb-8">{data?.[0]?.collection || '${colName}'}</h2>
  <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
    {items?.map((item: any) => (<Card key={item.uuid}><CardHeader><CardTitle>{item.title || item.name}</CardTitle></CardHeader><CardContent><p className="text-muted-foreground line-clamp-3">{item.description || item.content}</p></CardContent></Card>))}
  </div>
</div>`;
    case 'detail':
      return `<div className="container mx-auto px-4 py-12 max-w-3xl">
  <article className="prose prose-lg dark:prose-invert">
    <h1>{entry?.title}</h1>
    <div className="flex items-center gap-4 text-sm text-muted-foreground mb-8"><span>{entry?.created_at}</span></div>
    <div dangerouslySetInnerHTML={{ __html: entry?.content || '' }} />
  </article>
</div>`;
    case 'form':
      return `<Card><CardHeader><CardTitle>Form</CardTitle></CardHeader><CardContent><form onSubmit={handleSubmit} className="space-y-4">${col?.fields?.map((f: any) => `<div><Label htmlFor="${f.slug}">${f.name}</Label><Input id="${f.slug}" name="${f.slug}" required={${f.isRequired ? 'true' : 'false'}} /></div>`).join('') ?? '{{/* Add fields */}}'}<Button type="submit">Submit</Button></form></CardContent></Card>`;
    case 'grid':
      return `<div className="container mx-auto px-4 py-12"><div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">{items?.map((item: any) => (<Card key={item.uuid} className="group cursor-pointer hover:border-primary/50">{item.image && <img src={item.image} alt={item.title} className="w-full h-48 object-cover rounded-t-lg" />}<CardContent className="p-4"><h3 className="font-semibold group-hover:text-primary">{item.title || item.name}</h3><p className="text-sm text-muted-foreground">{item.description}</p></CardContent></Card>))}</div></div>`;
    case 'custom': return section.customCode || '// Custom code';
    default: return '// Empty section';
  }
}

export default function PageBuilder({ project, collections }: { project: Project; collections: Collection[] }) {
  const t = useTranslation();
  const [pages, setPages] = useState<PageTemplate[]>([]);
  const [selectedPage, setSelectedPage] = useState<string | null>(null);
  const [generatedCode, setGeneratedCode] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const current = pages.find(p => p.key === selectedPage);

  function addPage() { const key = `page_${Date.now()}`; setPages([...pages, { key, name: '', slug: '', sections: [] }]); setSelectedPage(key); }
  function addSection(type: PageSection['type']) { if (!current) return; setPages(pages.map(p => p.key === current.key ? { ...p, sections: [...p.sections, { key: `sec_${Date.now()}`, type, title: t(SECTION_TEMPLATES[type].labelKey), collection: '', fields: [] }] } : p)); }
  function removeSection(pk: string, sk: string) { setPages(pages.map(p => p.key === pk ? { ...p, sections: p.sections.filter(s => s.key !== sk) } : p)); }
  function updatePage(key: string, data: Partial<PageTemplate>) { setPages(pages.map(p => p.key === key ? { ...p, ...data, slug: data.name ? data.name.toLowerCase().replace(/[^a-z0-9]+/g, '_') : p.slug } : p)); }
  function updateSection(pk: string, sk: string, data: Partial<PageSection>) { setPages(pages.map(p => p.key === pk ? { ...p, sections: p.sections.map(s => s.key === sk ? { ...s, ...data } : s) } : p)); }

  function generateFullCode() {
    if (!current) return;
    const sectionsCode = current.sections.map(s => generateSectionCode(s)).join('\n\n');
    const name = current.slug ? current.slug.charAt(0).toUpperCase() + current.slug.slice(1) : 'GeneratedPage';
    const code = `import { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Page: ${current.name || t('studio.page.untitled')}
 * ${t('studio.code.generated_by', { date: new Date().toLocaleDateString('fr-FR') })}
 * ${t('studio.code.projet', { name: project.name })}
 */
export default function ${name}() {
  const [data, setData] = useState<any>(null);
  const [items, setItems] = useState<any[]>([]);
  const [entry, setEntry] = useState<any>(null);
  useEffect(() => { fetch('/api/${project.uuid}/YOUR_COLLECTION').then(r => r.json()).then(setItems).catch(console.error); }, []);
  const handleSubmit = async (e: React.FormEvent) => { e.preventDefault(); };
  return (<main className="min-h-screen">\n${sectionsCode.split('\n').map(l => '    ' + l).join('\n')}\n  </main>);
}`;
    setGeneratedCode(code);
  }

  async function copyCode() { if (!generatedCode) return; await navigator.clipboard.writeText(generatedCode); setCopied(true); setTimeout(() => setCopied(false), 2000); }
  function downloadCode() { if (!generatedCode) return; const b = new Blob([generatedCode], { type: 'text/typescript' }); const u = URL.createObjectURL(b); const a = document.createElement('a'); a.href = u; a.download = `${current?.slug || 'page'}.tsx`; a.click(); URL.revokeObjectURL(u); }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div><h2 className="text-2xl font-bold tracking-tight">{t('studio.page.title')}</h2><p className="text-muted-foreground">{t('studio.page.desc')}</p></div>
        <Button onClick={addPage}><Layout className="w-4 h-4 mr-2" />{t('studio.page.new_page')}</Button>
      </div>
      <div className="grid grid-cols-12 gap-6">
        <div className="col-span-12 lg:col-span-3 space-y-2">
          <h3 className="font-semibold text-sm text-muted-foreground uppercase tracking-wider">{t('studio.page.pages_title')}</h3>
          {pages.map(page => (
            <Card key={page.key} className={`cursor-pointer transition-all ${selectedPage === page.key ? 'border-primary ring-1 ring-primary/20' : ''}`} onClick={() => setSelectedPage(page.key)}>
              <CardContent className="p-3 flex items-center justify-between"><div><p className="font-medium">{page.name || t('studio.page.untitled')}</p><p className="text-xs text-muted-foreground">{t('studio.page.sections_count', { count: String(page.sections.length) })}</p></div></CardContent>
            </Card>
          ))}
          {pages.length === 0 && <p className="text-sm text-muted-foreground text-center py-8">{t('studio.page.no_pages')}</p>}
        </div>
        <div className="col-span-12 lg:col-span-5 space-y-4">
          {current ? (<>
            <div className="grid grid-cols-2 gap-3">
              <div><Label>{t('studio.page.name_label')}</Label><Input value={current.name} onChange={e => updatePage(current.key, { name: e.target.value })} placeholder={t('studio.page.name_ph')} /></div>
              <div><Label>{t('studio.page.slug_label')}</Label><Input value={current.slug} readOnly className="font-mono text-sm" /></div>
            </div>
            <Separator />
            <div><h3 className="font-semibold text-sm uppercase tracking-wider text-muted-foreground mb-2">{t('studio.page.sections_title')}</h3>
              <div className="flex flex-wrap gap-2 mb-3">
                {Object.entries(SECTION_TEMPLATES).map(([type, info]) => (<Button key={type} variant="outline" size="sm" onClick={() => addSection(type as PageSection['type'])}><info.icon className="w-3 h-3 mr-1" />{t(info.labelKey)}</Button>))}
              </div>
            </div>
            <div className="space-y-3 max-h-[400px] overflow-y-auto pr-1">
              {current.sections.map((section, idx) => {
                const Icon = SECTION_TEMPLATES[section.type]?.icon || Code2;
                return (
                  <Card key={section.key}><CardContent className="p-3">
                    <div className="flex items-center justify-between mb-2">
                      <div className="flex items-center gap-2"><Badge variant="outline"><Icon className="w-3 h-3 mr-1" />{t(SECTION_TEMPLATES[section.type]?.labelKey)}</Badge><span className="text-xs text-muted-foreground">#{idx + 1}</span></div>
                      <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => removeSection(current.key, section.key)}><Trash2 className="w-3 h-3 text-destructive" /></Button>
                    </div>
                    {(section.type === 'list' || section.type === 'detail' || section.type === 'grid') && (
                      <div className="mt-2"><Label className="text-xs">{t('studio.page.collection_link')}</Label>
                        <Select value={section.collection} onValueChange={v => updateSection(current.key, section.key, { collection: v })}>
                          <SelectTrigger className="h-8 mt-1"><SelectValue placeholder={t('studio.page.choose_collection')} /></SelectTrigger>
                          <SelectContent>{collections.map(c => (<SelectItem key={c.slug} value={c.slug}>{c.name}</SelectItem>))}</SelectContent>
                        </Select>
                      </div>
                    )}
                    {section.type === 'custom' && (
                      <textarea className="w-full h-24 mt-2 text-xs font-mono bg-muted p-2 rounded border" placeholder={t('studio.page.custom_placeholder')} value={section.customCode || ''} onChange={e => updateSection(current.key, section.key, { customCode: e.target.value })} />
                    )}
                  </CardContent></Card>
                );
              })}
            </div>
            {current.sections.length > 0 && <Button className="w-full" size="lg" onClick={generateFullCode}><Code2 className="w-4 h-4 mr-2" />{t('studio.page.generate_btn')}</Button>}
          </>) : (<div className="flex flex-col items-center justify-center h-64 text-muted-foreground"><Monitor className="w-12 h-12 mb-3 opacity-30" /><p>{t('studio.page.select_page')}</p></div>)}
        </div>
        <div className="col-span-12 lg:col-span-4">
          <Card className="sticky top-4">
            <CardHeader className="pb-2"><CardTitle className="text-sm flex items-center justify-between"><span className="flex items-center gap-2"><Code2 className="w-4 h-4" />{t('studio.page.code_title')}</span>
              {generatedCode && (<div className="flex gap-1"><Button variant="ghost" size="icon" className="h-7 w-7" onClick={copyCode}>{copied ? <Check className="w-3 h-3 text-green-500" /> : <Copy className="w-3 h-3" />}</Button><Button variant="ghost" size="icon" className="h-7 w-7" onClick={downloadCode}><Download className="w-3 h-3" /></Button></div>)}
            </CardTitle></CardHeader>
            <CardContent>{generatedCode ? <pre className="text-xs font-mono bg-muted p-3 rounded-md overflow-auto max-h-[500px]"><code>{generatedCode}</code></pre> : <p className="text-sm text-muted-foreground text-center py-8">{t('studio.page.code_hint')}</p>}</CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
import { Trash2 } from 'lucide-react';

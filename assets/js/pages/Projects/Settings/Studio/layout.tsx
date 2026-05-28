import Heading from '@/components/heading';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { Project, Collection } from '@/types/index.d';
import { Wand2, Layout, Download } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import SchemaBuilder from './SchemaBuilder';
import PageBuilder from './PageBuilder';
import CodeExporter from './CodeExporter';

interface StudioLayoutProps { project: Project; collections: Collection[]; }

export default function StudioLayout({ project, collections }: StudioLayoutProps) {
  const t = useTranslation();
  if (typeof window === 'undefined') return null;

  return (
    <div>
      <Heading title={t('studio.title')} description={t('studio.desc')} />
      <Tabs defaultValue="schema" className="w-full">
        <TabsList className="mb-6">
          <TabsTrigger value="schema" className="flex items-center gap-2"><Wand2 className="w-4 h-4" />{t('studio.tab_schema')}</TabsTrigger>
          <TabsTrigger value="pages" className="flex items-center gap-2"><Layout className="w-4 h-4" />{t('studio.tab_pages')}</TabsTrigger>
          <TabsTrigger value="export" className="flex items-center gap-2"><Download className="w-4 h-4" />{t('studio.tab_export')}</TabsTrigger>
        </TabsList>
        <TabsContent value="schema"><SchemaBuilder project={project} /></TabsContent>
        <TabsContent value="pages"><PageBuilder project={project} collections={collections} /></TabsContent>
        <TabsContent value="export"><CodeExporter project={project} collections={collections} /></TabsContent>
      </Tabs>
    </div>
  );
}

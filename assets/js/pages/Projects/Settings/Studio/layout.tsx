import Heading from '@/components/heading';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { Project, Collection } from '@/types/index.d';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Wand2, Layout, Download, Search, ScrollText, Braces } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import SchemaBuilder from './SchemaBuilder';
import PageBuilder from './PageBuilder';
import CodeExporter from './CodeExporter';
import SearchPage from './SearchPage';
import AuditLogsPage from './AuditLogsPage';

interface StudioLayoutProps { project: Project; collections: Collection[]; }

export default function StudioLayout({ project, collections }: StudioLayoutProps) {
  const t = useTranslation();
  if (typeof window === 'undefined') return null;

  return (
    <div>
      <Heading title={t('studio.title')} description={t('studio.desc')} />
      <Tabs defaultValue="schema" className="w-full">
        <TabsList className="mb-6 flex flex-wrap">
          <TabsTrigger value="schema" className="flex items-center gap-2"><Wand2 className="w-4 h-4" />{t('studio.tab_schema')}</TabsTrigger>
          <TabsTrigger value="pages" className="flex items-center gap-2"><Layout className="w-4 h-4" />{t('studio.tab_pages')}</TabsTrigger>
          <TabsTrigger value="export" className="flex items-center gap-2"><Download className="w-4 h-4" />{t('studio.tab_export')}</TabsTrigger>
          <TabsTrigger value="search" className="flex items-center gap-2"><Search className="w-4 h-4" />Recherche</TabsTrigger>
          <TabsTrigger value="audit" className="flex items-center gap-2"><ScrollText className="w-4 h-4" />Audit</TabsTrigger>
          <TabsTrigger value="graphql" className="flex items-center gap-2"><Braces className="w-4 h-4" />GraphQL</TabsTrigger>
        </TabsList>
        <TabsContent value="schema"><SchemaBuilder project={project} /></TabsContent>
        <TabsContent value="pages"><PageBuilder project={project} collections={collections} /></TabsContent>
        <TabsContent value="export"><CodeExporter project={project} collections={collections} /></TabsContent>
        <TabsContent value="search"><SearchPage project={project} collections={collections} /></TabsContent>
        <TabsContent value="audit"><AuditLogsPage project={project} /></TabsContent>
        <TabsContent value="graphql">
          <GraphQLExplorer projectUuid={project.uuid} />
        </TabsContent>
      </Tabs>
    </div>
  );
}

function GraphQLExplorer({ projectUuid }: { projectUuid: string }) {
  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-2xl font-bold tracking-tight">Explorateur GraphQL</h2>
        <p className="text-muted-foreground">Requêtes GraphQL interactives sur le schéma de votre projet</p>
      </div>
      <Card>
        <CardHeader><CardTitle className="text-sm">Endpoint GraphQL</CardTitle></CardHeader>
        <CardContent>
          <div className="flex items-center gap-3 mb-4">
            <code className="text-sm font-mono bg-muted px-3 py-1.5 rounded-md">POST /api/projects/{projectUuid}/graphql</code>
            <Button variant="outline" size="sm" onClick={() => navigator.clipboard.writeText(`/api/projects/${projectUuid}/graphql`)}>Copier</Button>
          </div>
          <div className="bg-muted rounded-lg p-6">
            <p className="text-sm font-semibold mb-3">Exemple de requête</p>
            <pre className="text-xs font-mono bg-background p-3 rounded-md overflow-auto">{`# Lister les collections
query {
  _ping
}

# Obtenir une entrée
# query {
#   articles(uuid: "xxx-xxx-xxx") {
#     uuid
#     title
#     status
#   }
# }

# Créer une entrée
# mutation {
#   createArticles(input: {
#     title: "Nouvel article"
#     content: "Contenu..."
#     status: "draft"
#   }) {
#     uuid
#     title
#   }
# }`}</pre>
          </div>
          <p className="text-xs text-muted-foreground mt-4">
            Utilisez n'importe quel client GraphQL (Altair, Insomnia, Postman) pour explorer le schéma complet.
            Le schema est généré automatiquement depuis vos collections.
          </p>
        </CardContent>
      </Card>
    </div>
  );
}

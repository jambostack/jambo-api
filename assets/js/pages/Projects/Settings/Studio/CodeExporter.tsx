import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Download, Code2, Package, FileCode, Rocket, Copy, Check, Globe, Database, Braces, FileJson, FileType } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import type { Project, Collection } from '@/types/index.d';

export default function CodeExporter({ project, collections }: { project: Project; collections: Collection[] }) {
  const t = useTranslation();
  const [selectedCollections, setSelectedCollections] = useState<string[]>([]);
  const [sdkLang, setSdkLang] = useState<'typescript' | 'python' | 'php'>('typescript');
  const [generatedSdk, setGeneratedSdk] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  function toggleCollection(slug: string) { setSelectedCollections(prev => prev.includes(slug) ? prev.filter(s => s !== slug) : [...prev, slug]); }
  function selectAll() { if (selectedCollections.length === collections.length) { setSelectedCollections([]); } else { setSelectedCollections(collections.map(c => c.slug)); } }

  function generateSdk() {
    const cols = collections.filter(c => selectedCollections.includes(c.slug));
    const baseUrl = `https://your-domain.com/api/${project.uuid}`;

    switch (sdkLang) {
      case 'typescript':
        setGeneratedSdk(generateTsSdk(cols, baseUrl, project));
        break;
      case 'python':
        setGeneratedSdk(generatePythonSdk(cols, baseUrl, project));
        break;
      case 'php':
        setGeneratedSdk(generatePhpSdk(cols, baseUrl, project));
        break;
    }
  }

  function generateTsSdk(cols: Collection[], baseUrl: string, project: Project): string {
    const types = cols.map(c => {
      const fields = (c.fields || []).map((f: any) => `  ${f.slug}${f.isRequired ? '' : '?'}: ${tsType(f.type)};`);
      return `export interface ${pascalCase(c.slug)} {\n  uuid: string;\n  locale: string;\n  status: 'draft' | 'published';\n${fields.join('\n')}\n  created_at: string;\n  updated_at: string;\n}`;
    }).join('\n\n');

    const methods = cols.map(c => `
  async get${pascalCase(c.slug)}(uuid: string): Promise<${pascalCase(c.slug)}> {
    const res = await fetch(\`\${this.baseUrl}/${c.slug}/\${uuid}\`);
    return res.json();
  }

  async list${pascalCase(c.slug)}s(options?: { locale?: string; limit?: number; offset?: number }): Promise<${pascalCase(c.slug)}[]> {
    const params = new URLSearchParams(options as any).toString();
    const res = await fetch(\`\${this.baseUrl}/${c.slug}?\${params}\`);
    return res.json();
  }`).join('\n');

    return `/**
 * JamboApi SDK — Client TypeScript auto-généré
 * Projet: ${project.name}
 * Généré le ${new Date().toLocaleDateString('fr-FR')}
 */

${types}

class JamboApiClient {
  private baseUrl: string;
  private apiKey: string;

  constructor(baseUrl: string, apiKey: string) {
    this.baseUrl = baseUrl;
    this.apiKey = apiKey;
  }

  private async request<T>(path: string, options?: RequestInit): Promise<T> {
    const res = await fetch(\`\${this.baseUrl}\${path}\`, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        'Authorization': \`Bearer \${this.apiKey}\`,
        ...options?.headers,
      },
    });
    if (!res.ok) throw new Error(\`JamboApi Error: \${res.status}\`);
    return res.json();
  }
${methods}
}

// Usage:
// const api = new JamboApiClient('${baseUrl}', 'YOUR_API_TOKEN');
// const articles = await api.listArticles({ locale: 'fr', limit: 10 });
export { JamboApiClient, type ${cols.map(c => pascalCase(c.slug)).join(', type ')} };`;
  }

  function generatePythonSdk(cols: Collection[], baseUrl: string, project: Project): string {
    const dataclasses = cols.map(c => {
      const fields = (c.fields || []).map((f: any) => `    ${f.slug}: ${pythonType(f.type)}${f.isRequired ? '' : ' | None = None'}`);
      return `@dataclass\nclass ${pascalCase(c.slug)}:\n    uuid: str\n    locale: str = "fr"\n    status: str = "draft"\n${fields.join('\n')}\n    created_at: str = ""\n    updated_at: str = ""`;
    }).join('\n\n');

    const methods = cols.map(c => `
    def get_${c.slug}(self, uuid: str) -> ${pascalCase(c.slug)}:
        return self._request(f"/${c.slug}/{uuid}")

    def list_${c.slug}s(self, locale: str = None, limit: int = 50, offset: int = 0) -> list[${pascalCase(c.slug)}]:
        params = {k: v for k, v in {"locale": locale, "limit": limit, "offset": offset}.items() if v is not None}
        return self._request(f"/${c.slug}", params=params)`).join('\n');

    return `"""JamboApi SDK — Client Python auto-généré
Projet: ${project.name}
Généré le ${new Date().toLocaleDateString('fr-FR')}
"""
from dataclasses import dataclass
from typing import Optional, List
import requests

${dataclasses}

class JamboApiClient:
    def __init__(self, base_url: str, api_key: str):
        self.base_url = base_url
        self.api_key = api_key
        self.session = requests.Session()
        self.session.headers.update({
            "Content-Type": "application/json",
            "Authorization": f"Bearer {api_key}"
        })

    def _request(self, path: str, params: dict = None, method: str = "GET"):
        url = f"{self.base_url}{path}"
        resp = self.session.request(method, url, params=params)
        resp.raise_for_status()
        return resp.json()
${methods}

# Usage:
# api = JamboApiClient("${baseUrl}", "YOUR_API_TOKEN")
# articles = api.list_articles(locale="fr", limit=10)
`;
  }

  function generatePhpSdk(cols: Collection[], baseUrl: string, project: Project): string {
    const methods = cols.map(c => `
    /**
     * @return array<int, array<string, mixed>>
     */
    public function list${pascalCase(c.slug)}s(?string $locale = null, int $limit = 50, int $offset = 0): array
    {
        return \$this->request('GET', '/${c.slug}', array_filter(['locale' => \$locale, 'limit' => \$limit, 'offset' => \$offset]));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get${pascalCase(c.slug)}(string $uuid): ?array
    {
        return \$this->request('GET', '/${c.slug}/' . \$uuid);
    }`).join('\n');

    return `<?php

/**
 * JamboApi SDK — Client PHP auto-généré
 * Projet: ${project.name}
 * Généré le ${new Date().toLocaleDateString('fr-FR')}
 */
class JamboApiClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private ?\\Symfony\\Contracts\\HttpClient\\HttpClientInterface $httpClient = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $params = []): array
    {
        $url = \$this->baseUrl . $path;
        if (!empty($params) && $method === 'GET') {
            $url .= '?' . http_build_query($params);
        }

        $response = file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => $method,
                'header' => "Content-Type: application/json\\r\\nAuthorization: Bearer {\$this->apiKey}\\r\\n",
                'ignore_errors' => true,
            ],
        ]));

        return json_decode($response, true) ?? [];
    }
${methods}
}

// Usage:
// \$api = new JamboApiClient('${baseUrl}', 'YOUR_API_TOKEN');
// \$articles = \$api->listArticles('fr', 10);
`;
  }

  async function copySdk() {
    if (!generatedSdk) return;
    await navigator.clipboard.writeText(generatedSdk);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  function downloadSdk() {
    if (!generatedSdk) return;
    const ext = sdkLang === 'typescript' ? 'ts' : sdkLang === 'python' ? 'py' : 'php';
    const blob = new Blob([generatedSdk], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `jamboapi-sdk.${ext}`;
    a.click();
    URL.revokeObjectURL(url);
  }

  const langLabels = { typescript: { label: 'TypeScript', icon: FileType }, python: { label: 'Python', icon: FileCode }, php: { label: 'PHP', icon: Braces } };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div><h2 className="text-2xl font-bold tracking-tight">{t('studio.export.title')}</h2><p className="text-muted-foreground">{t('studio.export.desc')}</p></div>
      </div>

      <div className="grid grid-cols-12 gap-6">
        {/* Configuration */}
        <div className="col-span-12 lg:col-span-4 space-y-4">
          <Card>
            <CardHeader><CardTitle className="text-sm flex items-center gap-2"><Database className="w-4 h-4" />{t('studio.export.collections_title')}</CardTitle></CardHeader>
            <CardContent>
              <div className="flex items-center justify-between mb-3">
                <Button variant="ghost" size="sm" onClick={selectAll}>{selectedCollections.length === collections.length ? t('studio.export.deselect_all') : t('studio.export.select_all')}</Button>
                <Badge variant="secondary">{selectedCollections.length}/{collections.length}</Badge>
              </div>
              <div className="space-y-2 max-h-[300px] overflow-y-auto">
                {collections.map(c => (
                  <label key={c.slug} className="flex items-center gap-2 cursor-pointer hover:bg-muted/50 p-2 rounded">
                    <input
                      type="checkbox"
                      checked={selectedCollections.includes(c.slug)}
                      onChange={() => toggleCollection(c.slug)}
                      className="rounded"
                    />
                    <div>
                      <p className="text-sm font-medium">{c.name}</p>
                      <p className="text-xs text-muted-foreground">{c.slug} · {(c.fields || []).length} champs</p>
                    </div>
                  </label>
                ))}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-sm flex items-center gap-2"><Globe className="w-4 h-4" /> Langage cible</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex gap-2">
                {Object.entries(langLabels).map(([key, info]) => (
                  <Button
                    key={key}
                    variant={sdkLang === key ? 'default' : 'outline'}
                    size="sm"
                    onClick={() => setSdkLang(key as any)}
                    className="flex-1"
                  >
                    <info.icon className="w-3 h-3 mr-1" /> {info.label}
                  </Button>
                ))}
              </div>
            </CardContent>
          </Card>

          <Button className="w-full" size="lg" onClick={generateSdk} disabled={selectedCollections.length === 0}>
            <Rocket className="w-4 h-4 mr-2" /> Générer le SDK
          </Button>
        </div>

        {/* Code */}
        <div className="col-span-12 lg:col-span-8">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm flex items-center justify-between">
                <span className="flex items-center gap-2"><Code2 className="w-4 h-4" /> SDK {langLabels[sdkLang].label}</span>
                {generatedSdk && (
                  <div className="flex gap-1">
                    <Button variant="ghost" size="icon" className="h-7 w-7" onClick={copySdk}>
                      {copied ? <Check className="w-3 h-3 text-green-500" /> : <Copy className="w-3 h-3" />}
                    </Button>
                    <Button variant="ghost" size="icon" className="h-7 w-7" onClick={downloadSdk}>
                      <Download className="w-3 h-3" />
                    </Button>
                  </div>
                )}
              </CardTitle>
            </CardHeader>
            <CardContent>
              {generatedSdk ? (
                <pre className="text-xs font-mono bg-muted p-4 rounded-md overflow-auto max-h-[600px]">
                  <code>{generatedSdk}</code>
                </pre>
              ) : (
                <div className="flex flex-col items-center justify-center py-16 text-muted-foreground">
                  <Package className="w-12 h-12 mb-3 opacity-30" />
                  <p className="text-sm">Sélectionnez des collections et cliquez sur "Générer le SDK"</p>
                  <p className="text-xs mt-1">Un client typé sera généré automatiquement</p>
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}

// Type mapping helpers
function tsType(type: string): string {
  const map: Record<string, string> = { text: 'string', longtext: 'string', richtext: 'string', slug: 'string',
    email: 'string', password: 'string', number: 'number', decimal: 'number', boolean: 'boolean',
    date: 'string', datetime: 'string', time: 'string', color: 'string', json: 'Record<string, any>',
    enumeration: 'string', media: 'string | string[]', relation: 'string | string[]' };
  return map[type] || 'string';
}

function pythonType(type: string): string {
  const map: Record<string, string> = { text: 'str', longtext: 'str', richtext: 'str', slug: 'str',
    email: 'str', password: 'str', number: 'float', decimal: 'float', boolean: 'bool',
    date: 'str', datetime: 'str', time: 'str', color: 'str', json: 'dict',
    enumeration: 'str', media: 'str | list[str]', relation: 'str | list[str]' };
  return map[type] || 'str';
}

function pascalCase(str: string): string {
  return str.charAt(0).toUpperCase() + str.slice(1).replace(/_./g, s => s[1].toUpperCase());
}

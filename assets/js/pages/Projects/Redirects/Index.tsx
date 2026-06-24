import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';

import { Project, BreadcrumbItem } from '@/types';
import { useTranslation } from '@/lib/i18n';

import AppLayout from '@/layouts/app-layout';
import ProjectSidebar from '@/pages/Projects/ProjectSidebar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Search, Plus, Trash2, ToggleLeft, ExternalLink } from 'lucide-react';

interface RedirectItem {
    id: number;
    uuid: string;
    fromPath: string;
    toPath: string;
    httpCode: number;
    isPattern: boolean;
    isEnabled: boolean;
    hits: number;
    lastHitAt: string | null;
    isAuto: boolean;
    createdBy: string | null;
    createdAt: string;
}

interface RedirectData {
    data: RedirectItem[];
    meta: { total: number; page: number; per_page: number };
}

interface Props {
    project: Project;
}

export default function RedirectIndex({ project }: Props) {
    const t = useTranslation();
    const [data, setData] = useState<RedirectData | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [showCreate, setShowCreate] = useState(false);
    const [form, setForm] = useState({ fromPath: '', toPath: '', httpCode: 301, isPattern: false, isEnabled: true });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: `/projects/${project.uuid}` },
        { title: 'Redirections', href: `/projects/${project.uuid}/redirects` },
    ];

    const fetchRedirects = () => {
        setLoading(true);
        const params: Record<string, string | number> = { page, per_page: 25 };
        if (search) params.search = search;

        axios.get(`/admin-api/${project.uuid}/redirects`, { params })
            .then(r => setData(r.data))
            .catch(() => setData(null))
            .finally(() => setLoading(false));
    };

    useEffect(() => { fetchRedirects(); }, [page]);

    const handleCreate = () => {
        axios.post(`/admin-api/${project.uuid}/redirects`, form)
            .then(() => { setShowCreate(false); setForm({ fromPath: '', toPath: '', httpCode: 301, isPattern: false, isEnabled: true }); fetchRedirects(); })
            .catch(err => alert(err.response?.data?.error || 'Erreur'));
    };

    const handleDelete = (id: number) => {
        if (!confirm('Supprimer cette redirection ?')) return;
        axios.delete(`/admin-api/${project.uuid}/redirects/${id}`)
            .then(() => fetchRedirects());
    };

    const handleToggle = (id: number) => {
        axios.post(`/admin-api/${project.uuid}/redirects/${id}/toggle`)
            .then(() => fetchRedirects());
    };

    const handleSearch = (e: React.FormEvent) => { e.preventDefault(); setPage(1); fetchRedirects(); };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Redirections — ${project.name}`} />
            <div className="flex h-full">
                <ProjectSidebar project={project} />
                <div className="flex-1 overflow-auto p-6 space-y-6">
                    {/* Barre d'actions */}
                    <div className="flex items-center justify-between">
                        <h2 className="text-lg font-semibold">Redirections ({data?.meta?.total ?? 0})</h2>
                        <Button onClick={() => setShowCreate(true)}>
                            <Plus className="h-4 w-4 mr-2" />Nouvelle redirection
                        </Button>
                    </div>

                    {/* Recherche */}
                    <form onSubmit={handleSearch} className="flex items-center gap-2">
                        <Input
                            placeholder="Rechercher par chemin…"
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            className="max-w-sm"
                        />
                        <Button type="submit" variant="outline" size="icon">
                            <Search className="h-4 w-4" />
                        </Button>
                    </form>

                    {/* Table */}
                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>De</TableHead>
                                    <TableHead>Vers</TableHead>
                                    <TableHead className="w-[80px]">Code</TableHead>
                                    <TableHead className="w-[60px]">Hits</TableHead>
                                    <TableHead className="w-[80px]">Actif</TableHead>
                                    <TableHead className="w-[100px]">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {loading && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">Chargement…</TableCell>
                                    </TableRow>
                                )}
                                {!loading && data?.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">Aucune redirection trouvée.</TableCell>
                                    </TableRow>
                                )}
                                {!loading && data?.data.map(r => (
                                    <TableRow key={r.id}>
                                        <TableCell className="font-mono text-xs max-w-[250px] truncate">
                                            {r.isPattern && <Badge variant="outline" className="mr-1 text-[10px]">regex</Badge>}
                                            {r.isAuto && <Badge variant="secondary" className="mr-1 text-[10px]">auto</Badge>}
                                            {r.fromPath}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs text-blue-600 max-w-[250px] truncate">{r.toPath}</TableCell>
                                        <TableCell><Badge variant="outline">{r.httpCode}</Badge></TableCell>
                                        <TableCell className="text-muted-foreground">{r.hits}</TableCell>
                                        <TableCell>
                                            <Switch checked={r.isEnabled} onCheckedChange={() => handleToggle(r.id)} />
                                        </TableCell>
                                        <TableCell>
                                            <Button variant="ghost" size="icon" onClick={() => handleDelete(r.id)} title="Supprimer">
                                                <Trash2 className="h-4 w-4 text-red-500" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Pagination simple */}
                    {data && data.meta.total > data.meta.per_page && (
                        <div className="flex items-center justify-between">
                            <Button variant="outline" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>
                                Précédent
                            </Button>
                            <span className="text-sm text-muted-foreground">Page {page}</span>
                            <Button variant="outline" disabled={page * data.meta.per_page >= data.meta.total} onClick={() => setPage(p => p + 1)}>
                                Suivant
                            </Button>
                        </div>
                    )}

                    {/* Modal création */}
                    <Dialog open={showCreate} onOpenChange={setShowCreate}>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Nouvelle redirection</DialogTitle>
                            </DialogHeader>
                            <div className="space-y-4 py-4">
                                <div className="space-y-2">
                                    <Label htmlFor="fromPath">Chemin source</Label>
                                    <Input id="fromPath" value={form.fromPath} onChange={e => setForm({ ...form, fromPath: e.target.value })} placeholder="/ancien-chemin" />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="toPath">Chemin cible</Label>
                                    <Input id="toPath" value={form.toPath} onChange={e => setForm({ ...form, toPath: e.target.value })} placeholder="/nouveau-chemin" />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="httpCode">Code HTTP</Label>
                                    <Input id="httpCode" type="number" value={form.httpCode} onChange={e => setForm({ ...form, httpCode: parseInt(e.target.value) || 301 })} />
                                </div>
                                <div className="flex items-center gap-4">
                                    <div className="flex items-center gap-2">
                                        <Switch id="isPattern" checked={form.isPattern} onCheckedChange={v => setForm({ ...form, isPattern: v })} />
                                        <Label htmlFor="isPattern">Pattern regex</Label>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Switch id="isEnabled" checked={form.isEnabled} onCheckedChange={v => setForm({ ...form, isEnabled: v })} />
                                        <Label htmlFor="isEnabled">Activée</Label>
                                    </div>
                                </div>
                            </div>
                            <DialogFooter>
                                <Button variant="outline" onClick={() => setShowCreate(false)}>Annuler</Button>
                                <Button onClick={handleCreate}>Créer</Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
        </AppLayout>
    );
}

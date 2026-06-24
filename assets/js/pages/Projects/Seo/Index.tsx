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
import { Search, TrendingUp, AlertTriangle, CheckCircle2, Edit3 } from 'lucide-react';

interface SeoEntry {
    uuid: string;
    collection: string;
    metaTitle: string | null;
    metaDescription: string | null;
    slug: string;
    score: number;
    seoScore: number | null;
}

interface SeoData {
    data: SeoEntry[];
    meta: { total: number; avg_score: number | null };
}

interface Props {
    project: Project;
}

function ScoreBadge({ score }: { score: number }) {
    if (score >= 80) {
        return <Badge variant="default" className="bg-green-100 text-green-800">{score}/100</Badge>;
    }
    if (score >= 50) {
        return <Badge variant="secondary" className="bg-yellow-100 text-yellow-800">{score}/100</Badge>;
    }
    return <Badge variant="destructive" className="bg-red-100 text-red-800">{score}/100</Badge>;
}

export default function SeoIndex({ project }: Props) {
    const t = useTranslation();
    const [data, setData] = useState<SeoData | null>(null);
    const [loading, setLoading] = useState(true);
    const [collectionFilter, setCollectionFilter] = useState('');
    const [scoreFilter, setScoreFilter] = useState('');
    const [search, setSearch] = useState('');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: `/projects/${project.uuid}` },
        { title: 'SEO', href: `/projects/${project.uuid}/seo` },
    ];

    const fetchScores = () => {
        setLoading(true);
        const params: Record<string, string> = {};
        if (collectionFilter) params.collection = collectionFilter;
        if (scoreFilter) params.score_filter = scoreFilter;
        if (search) params.search = search;

        axios.get(`/admin-api/${project.uuid}/seo/scores`, { params })
            .then(r => setData(r.data))
            .catch(() => setData(null))
            .finally(() => setLoading(false));
    };

    useEffect(() => { fetchScores(); }, [collectionFilter, scoreFilter]);

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        fetchScores();
    };

    const avgScore = data?.meta?.avg_score;
    const total = data?.meta?.total ?? 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`SEO — ${project.name}`} />
            <div className="flex h-full">
                <ProjectSidebar project={project} />
                <div className="flex-1 overflow-auto p-6 space-y-6">
                    {/* KPI Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Score moyen</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <TrendingUp className="h-5 w-5 text-blue-500" />
                                    <span className="text-2xl font-bold">
                                        {avgScore !== null && avgScore !== undefined ? avgScore : '—'}/100
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Entrées analysées</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <CheckCircle2 className="h-5 w-5 text-green-500" />
                                    <span className="text-2xl font-bold">{total}</span>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">À améliorer</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <AlertTriangle className="h-5 w-5 text-yellow-500" />
                                    <span className="text-2xl font-bold">
                                        {data ? data.data.filter(e => e.score < 50).length : '—'}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Filters + Search */}
                    <div className="flex items-center gap-3 flex-wrap">
                        <select
                            value={scoreFilter}
                            onChange={e => setScoreFilter(e.target.value)}
                            className="w-[180px] rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">Tous les scores</option>
                            <option value="good">Bons (≥ 80)</option>
                            <option value="ok">Moyens (50–79)</option>
                            <option value="poor">Faibles (&lt; 50)</option>
                        </select>
                        <form onSubmit={handleSearch} className="flex items-center gap-2">
                            <Input
                                placeholder="Rechercher par titre…"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                className="w-[250px]"
                            />
                            <Button type="submit" variant="outline" size="icon">
                                <Search className="h-4 w-4" />
                            </Button>
                        </form>
                    </div>

                    {/* Table */}
                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Titre</TableHead>
                                    <TableHead>Collection</TableHead>
                                    <TableHead>Slug</TableHead>
                                    <TableHead className="w-[100px]">Score</TableHead>
                                    <TableHead className="w-[80px]">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {loading && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-center py-8 text-muted-foreground">
                                            Chargement…
                                        </TableCell>
                                    </TableRow>
                                )}
                                {!loading && data?.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-center py-8 text-muted-foreground">
                                            Aucune entrée trouvée.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {!loading && data?.data.map(entry => (
                                    <TableRow key={entry.uuid}>
                                        <TableCell className="font-medium max-w-[300px] truncate">
                                            {entry.metaTitle || '—'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">{entry.collection}</TableCell>
                                        <TableCell className="text-muted-foreground font-mono text-xs">{entry.slug}</TableCell>
                                        <TableCell><ScoreBadge score={entry.score} /></TableCell>
                                        <TableCell>
                                            <Button variant="ghost" size="icon" title="Modifier le SEO">
                                                <Edit3 className="h-4 w-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

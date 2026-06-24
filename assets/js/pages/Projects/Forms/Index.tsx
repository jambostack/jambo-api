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
import { Textarea } from '@/components/ui/textarea';
import { Plus, Trash2, Eye, Mail, FileText, AlertTriangle } from 'lucide-react';

interface FormItem {
    id: number;
    name: string;
    slug: string;
    fields_count: number;
    has_steps: boolean;
    submissions_count: number;
    unread_count: number;
    createdAt: string;
    updatedAt: string;
}

interface FormData {
    data: FormItem[];
}

interface SubmissionItem {
    id: number;
    data: Record<string, unknown>;
    metadata: Record<string, unknown>;
    isComplete: boolean;
    isSpam: boolean;
    isRead: boolean;
    createdAt: string;
}

interface SubmissionData {
    data: SubmissionItem[];
    meta: { total: number; page: number; per_page: number };
}

interface Props {
    project: Project;
}

export default function FormIndex({ project }: Props) {
    const t = useTranslation();
    const [forms, setForms] = useState<FormData | null>(null);
    const [loading, setLoading] = useState(true);
    const [showCreate, setShowCreate] = useState(false);
    const [showSubmissions, setShowSubmissions] = useState<number | null>(null);
    const [submissions, setSubmissions] = useState<SubmissionData | null>(null);
    const [submissionForm, setSubmissionForm] = useState({ name: '', slug: '', fields: '[]', settings: '{}' });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: `/projects/${project.uuid}` },
        { title: 'Formulaires', href: `/projects/${project.uuid}/forms` },
    ];

    const fetchForms = () => {
        setLoading(true);
        axios.get(`/admin-api/${project.uuid}/forms`)
            .then(r => setForms(r.data))
            .catch(() => setForms(null))
            .finally(() => setLoading(false));
    };

    const fetchSubmissions = (formId: number) => {
        axios.get(`/admin-api/${project.uuid}/forms/${formId}/submissions`)
            .then(r => { setSubmissions(r.data); setShowSubmissions(formId); })
            .catch(() => alert('Erreur de chargement des soumissions'));
    };

    useEffect(() => { fetchForms(); }, []);

    const handleCreate = () => {
        let fields;
        try {
            fields = JSON.parse(submissionForm.fields);
        } catch {
            alert('JSON invalide pour les champs');
            return;
        }
        let settings = {};
        try {
            settings = JSON.parse(submissionForm.settings || '{}');
        } catch {
            settings = {};
        }

        axios.post(`/admin-api/${project.uuid}/forms`, {
            name: submissionForm.name,
            slug: submissionForm.slug,
            fields,
            settings,
        })
            .then(() => {
                setShowCreate(false);
                setSubmissionForm({ name: '', slug: '', fields: '[]', settings: '{}' });
                fetchForms();
            })
            .catch(err => alert(err.response?.data?.error || 'Erreur'));
    };

    const handleDelete = (id: number) => {
        if (!confirm('Supprimer ce formulaire et toutes ses soumissions ?')) return;
        axios.delete(`/admin-api/${project.uuid}/forms/${id}`)
            .then(() => fetchForms());
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Formulaires — ${project.name}`} />
            <div className="flex h-full">
                <ProjectSidebar project={project} />
                <div className="flex-1 overflow-auto p-6 space-y-6">
                    {/* Header */}
                    <div className="flex items-center justify-between">
                        <h2 className="text-lg font-semibold">Formulaires ({forms?.data?.length ?? 0})</h2>
                        <Button onClick={() => setShowCreate(true)}>
                            <Plus className="h-4 w-4 mr-2" />Nouveau formulaire
                        </Button>
                    </div>

                    {/* KPI Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Total formulaires</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <FileText className="h-5 w-5 text-blue-500" />
                                    <span className="text-2xl font-bold">{forms?.data?.length ?? 0}</span>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Total soumissions</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <Mail className="h-5 w-5 text-green-500" />
                                    <span className="text-2xl font-bold">
                                        {forms ? forms.data.reduce((sum, f) => sum + f.submissions_count, 0) : '—'}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Non lues</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <AlertTriangle className="h-5 w-5 text-yellow-500" />
                                    <span className="text-2xl font-bold">
                                        {forms ? forms.data.reduce((sum, f) => sum + f.unread_count, 0) : '—'}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Table */}
                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nom</TableHead>
                                    <TableHead>Slug</TableHead>
                                    <TableHead className="w-[80px]">Champs</TableHead>
                                    <TableHead className="w-[100px]">Soumissions</TableHead>
                                    <TableHead className="w-[80px]">Non lues</TableHead>
                                    <TableHead className="w-[120px]">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {loading && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">Chargement…</TableCell>
                                    </TableRow>
                                )}
                                {!loading && (!forms || forms.data.length === 0) && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">Aucun formulaire trouvé.</TableCell>
                                    </TableRow>
                                )}
                                {!loading && forms?.data.map(f => (
                                    <TableRow key={f.id}>
                                        <TableCell className="font-medium">{f.name}</TableCell>
                                        <TableCell className="text-muted-foreground font-mono text-xs">{f.slug}</TableCell>
                                        <TableCell>{f.fields_count}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline" className="cursor-pointer" onClick={() => fetchSubmissions(f.id)}>
                                                {f.submissions_count} <Eye className="h-3 w-3 ml-1" />
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {f.unread_count > 0
                                                ? <Badge variant="destructive">{f.unread_count}</Badge>
                                                : <span className="text-muted-foreground">0</span>}
                                        </TableCell>
                                        <TableCell>
                                            <Button variant="ghost" size="icon" onClick={() => fetchSubmissions(f.id)} title="Voir les soumissions">
                                                <Eye className="h-4 w-4" />
                                            </Button>
                                            <Button variant="ghost" size="icon" onClick={() => handleDelete(f.id)} title="Supprimer">
                                                <Trash2 className="h-4 w-4 text-red-500" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Modal création */}
                    <Dialog open={showCreate} onOpenChange={setShowCreate}>
                        <DialogContent className="max-w-2xl">
                            <DialogHeader>
                                <DialogTitle>Nouveau formulaire</DialogTitle>
                            </DialogHeader>
                            <div className="space-y-4 py-4">
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="formName">Nom</Label>
                                        <Input id="formName" value={submissionForm.name} onChange={e => setSubmissionForm({ ...submissionForm, name: e.target.value })} placeholder="Contact" />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="formSlug">Slug</Label>
                                        <Input id="formSlug" value={submissionForm.slug} onChange={e => setSubmissionForm({ ...submissionForm, slug: e.target.value })} placeholder="contact" />
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="formFields">Champs (JSON)</Label>
                                    <Textarea
                                        id="formFields"
                                        value={submissionForm.fields}
                                        onChange={e => setSubmissionForm({ ...submissionForm, fields: e.target.value })}
                                        placeholder='[{"name":"email","type":"email","label":"Email","required":true}]'
                                        rows={8}
                                        className="font-mono text-xs"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="formSettings">Paramètres (JSON)</Label>
                                    <Textarea
                                        id="formSettings"
                                        value={submissionForm.settings}
                                        onChange={e => setSubmissionForm({ ...submissionForm, settings: e.target.value })}
                                        placeholder='{}'
                                        rows={4}
                                        className="font-mono text-xs"
                                    />
                                </div>
                            </div>
                            <DialogFooter>
                                <Button variant="outline" onClick={() => setShowCreate(false)}>Annuler</Button>
                                <Button onClick={handleCreate}>Créer</Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    {/* Modal soumissions */}
                    <Dialog open={showSubmissions !== null} onOpenChange={() => setShowSubmissions(null)}>
                        <DialogContent className="max-w-3xl max-h-[80vh] overflow-auto">
                            <DialogHeader>
                                <DialogTitle>Soumissions ({submissions?.meta?.total ?? 0})</DialogTitle>
                            </DialogHeader>
                            <div className="py-4">
                                {!submissions || submissions.data.length === 0 ? (
                                    <p className="text-center text-muted-foreground py-8">Aucune soumission.</p>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>ID</TableHead>
                                                <TableHead>Données</TableHead>
                                                <TableHead className="w-[80px]">Spam</TableHead>
                                                <TableHead className="w-[120px]">Date</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {submissions.data.map(s => (
                                                <TableRow key={s.id} className={s.isSpam ? 'opacity-50' : ''}>
                                                    <TableCell className="font-mono text-xs">#{s.id}</TableCell>
                                                    <TableCell className="max-w-[400px]">
                                                        <pre className="text-xs whitespace-pre-wrap font-mono">
                                                            {JSON.stringify(s.data, null, 1)}
                                                        </pre>
                                                    </TableCell>
                                                    <TableCell>
                                                        {s.isSpam ? <Badge variant="destructive">Spam</Badge> : <Badge variant="outline">OK</Badge>}
                                                    </TableCell>
                                                    <TableCell className="text-xs text-muted-foreground">
                                                        {new Date(s.createdAt).toLocaleDateString('fr-FR')}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                            </div>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
        </AppLayout>
    );
}

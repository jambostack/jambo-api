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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Plus,
    Trash2,
    Eye,
    Mail,
    MailOpen,
    FileText,
    AlertTriangle,
    Download,
    ShieldAlert,
    ShieldCheck,
    ChevronLeft,
    ChevronRight,
} from 'lucide-react';

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
    const [submissionsLoading, setSubmissionsLoading] = useState(false);
    const [submissionsPage, setSubmissionsPage] = useState(1);
    const [submissionsFilter, setSubmissionsFilter] = useState<'all' | 'unread' | 'read' | 'ham' | 'spam'>('all');
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [batchLoading, setBatchLoading] = useState(false);
    const [submissionForm, setSubmissionForm] = useState({ name: '', slug: '', fields: '[]', settings: '{}' });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: `/projects/${project.uuid}` },
        { title: t('forms.title'), href: `/projects/${project.uuid}/forms` },
    ];

    const fetchForms = () => {
        setLoading(true);
        axios.get(`/admin-api/${project.uuid}/forms`)
            .then(r => setForms(r.data))
            .catch(() => setForms(null))
            .finally(() => setLoading(false));
    };

    const fetchSubmissions = (formId: number, page = 1, filter: 'all' | 'unread' | 'read' | 'ham' | 'spam' = submissionsFilter) => {
        setSubmissionsLoading(true);
        let url = `/admin-api/${project.uuid}/forms/${formId}/submissions?page=${page}&per_page=20`;
        if (filter === 'unread') url += '&read=unread';
        if (filter === 'read') url += '&read=read';
        if (filter === 'ham') url += '&spam=ham';
        if (filter === 'spam') url += '&spam=spam';

        axios.get(url)
            .then(r => {
                setSubmissions(r.data);
                setShowSubmissions(formId);
                setSubmissionsPage(page);
                setSelectedIds([]);
            })
            .catch(() => alert(t('common.error')))
            .finally(() => setSubmissionsLoading(false));
    };

    const handleFilterChange = (newFilter: 'all' | 'unread' | 'read' | 'ham' | 'spam') => {
        setSubmissionsFilter(newFilter);
        if (showSubmissions !== null) {
            fetchSubmissions(showSubmissions, 1, newFilter);
        }
    };

    const handleExportCsv = (formId: number) => {
        let url = `/admin-api/${project.uuid}/forms/${formId}/submissions/export-csv`;
        const params: string[] = [];
        if (submissionsFilter === 'unread') params.push('read=unread');
        if (submissionsFilter === 'read') params.push('read=read');
        if (submissionsFilter === 'ham') params.push('spam=ham');
        if (submissionsFilter === 'spam') params.push('spam=spam');
        if (params.length > 0) url += `?${params.join('&')}`;
        window.open(url, '_blank');
    };

    const handleUpdateSubmission = (formId: number, submissionId: number, patch: { isRead?: boolean; isSpam?: boolean }) => {
        axios.patch(`/admin-api/${project.uuid}/forms/${formId}/submissions/${submissionId}`, patch)
            .then(() => {
                fetchSubmissions(formId, submissionsPage, submissionsFilter);
                fetchForms();
            })
            .catch(err => alert(err.response?.data?.error || t('common.error')));
    };

    const handleDeleteSubmission = (formId: number, submissionId: number) => {
        if (!confirm(t('forms.delete_confirm'))) return;
        axios.delete(`/admin-api/${project.uuid}/forms/${formId}/submissions/${submissionId}`)
            .then(() => {
                fetchSubmissions(formId, submissionsPage, submissionsFilter);
                fetchForms();
            })
            .catch(err => alert(err.response?.data?.error || t('common.error')));
    };

    const handleBatchAction = (action: 'mark_read' | 'mark_unread' | 'mark_spam' | 'mark_ham' | 'delete') => {
        if (!showSubmissions || selectedIds.length === 0) return;
        if (action === 'delete' && !confirm(t('forms.batch_delete_confirm'))) return;

        setBatchLoading(true);
        axios.post(`/admin-api/${project.uuid}/forms/${showSubmissions}/submissions/batch`, {
            action,
            ids: selectedIds,
        })
            .then(() => {
                setSelectedIds([]);
                fetchSubmissions(showSubmissions, submissionsPage, submissionsFilter);
                fetchForms();
            })
            .catch(err => alert(err.response?.data?.error || t('common.error')))
            .finally(() => setBatchLoading(false));
    };

    useEffect(() => { fetchForms(); }, []);

    const handleCreate = () => {
        let fields;
        try {
            fields = JSON.parse(submissionForm.fields);
        } catch {
            alert(t('studio.fopts.json_invalid'));
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
            .catch(err => alert(err.response?.data?.error || t('common.error')));
    };

    const handleDelete = (id: number) => {
        if (!confirm(t('forms.delete_confirm'))) return;
        axios.delete(`/admin-api/${project.uuid}/forms/${id}`)
            .then(() => fetchForms());
    };

    const allVisibleSelected = Boolean(
        submissions?.data &&
        submissions.data.length > 0 &&
        submissions.data.every(s => selectedIds.includes(s.id))
    );

    const toggleSelectAll = () => {
        if (!submissions?.data) return;
        if (allVisibleSelected) {
            setSelectedIds([]);
        } else {
            setSelectedIds(submissions.data.map(s => s.id));
        }
    };

    const toggleSelectOne = (id: number) => {
        setSelectedIds(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]);
    };

    const totalSubmissions = submissions?.meta?.total ?? 0;
    const perPage = submissions?.meta?.per_page ?? 20;
    const totalPages = Math.max(1, Math.ceil(totalSubmissions / perPage));

    const formatDate = (isoString: string) => {
        try {
            return new Date(isoString).toLocaleString();
        } catch {
            return isoString;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('forms.title')} — ${project.name}`} />
            <div className="flex h-full">
                <ProjectSidebar project={project} />
                <div className="flex-1 overflow-auto p-6 space-y-6">
                    {/* Header */}
                    <div className="flex items-center justify-between">
                        <h2 className="text-lg font-semibold">{t('forms.title')} ({forms?.data?.length ?? 0})</h2>
                        <Button onClick={() => setShowCreate(true)}>
                            <Plus className="h-4 w-4 mr-2" />{t('forms.new_form')}
                        </Button>
                    </div>

                    {/* KPI Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">{t('forms.total_forms')}</CardTitle>
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
                                <CardTitle className="text-sm font-medium text-muted-foreground">{t('forms.total_submissions')}</CardTitle>
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
                                <CardTitle className="text-sm font-medium text-muted-foreground">{t('forms.unread')}</CardTitle>
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
                                    <TableHead>{t('common.name')}</TableHead>
                                    <TableHead>{t('fields.slug')}</TableHead>
                                    <TableHead className="w-[80px]">{t('forms.fields')}</TableHead>
                                    <TableHead className="w-[100px]">{t('forms.submissions')}</TableHead>
                                    <TableHead className="w-[80px]">{t('forms.unread')}</TableHead>
                                    <TableHead className="w-[120px]">{t('forms.actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {loading && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">{t('common.loading')}</TableCell>
                                    </TableRow>
                                )}
                                {!loading && (!forms || forms.data.length === 0) && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">{t('forms.no_forms')}</TableCell>
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
                                            <Button variant="ghost" size="icon" onClick={() => fetchSubmissions(f.id)} title={t('forms.view_submissions')}>
                                                <Eye className="h-4 w-4" />
                                            </Button>
                                            <Button variant="ghost" size="icon" onClick={() => handleDelete(f.id)} title={t('common.delete')}>
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
                                <DialogTitle>{t('forms.new_form')}</DialogTitle>
                            </DialogHeader>
                            <div className="space-y-4 py-4">
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="formName">{t('common.name')}</Label>
                                        <Input id="formName" value={submissionForm.name} onChange={e => setSubmissionForm({ ...submissionForm, name: e.target.value })} placeholder="Contact" />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="formSlug">{t('fields.slug')}</Label>
                                        <Input id="formSlug" value={submissionForm.slug} onChange={e => setSubmissionForm({ ...submissionForm, slug: e.target.value })} placeholder="contact" />
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="formFields">{t('forms.fields_json')}</Label>
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
                                    <Label htmlFor="formSettings">{t('forms.settings_json')}</Label>
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
                                <Button variant="outline" onClick={() => setShowCreate(false)}>{t('common.cancel')}</Button>
                                <Button onClick={handleCreate}>{t('common.create')}</Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    {/* Modal soumissions avec actions en lote, export CSV et pagination */}
                    <Dialog open={showSubmissions !== null} onOpenChange={() => { setShowSubmissions(null); setSelectedIds([]); }}>
                        <DialogContent className="max-w-4xl max-h-[85vh] flex flex-col">
                            <DialogHeader className="flex flex-row items-center justify-between space-y-0 pb-3 border-b">
                                <DialogTitle className="text-base font-semibold">
                                    {t('forms.submissions')} ({submissions?.meta?.total ?? 0})
                                </DialogTitle>
                                {showSubmissions !== null && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => handleExportCsv(showSubmissions)}
                                        title={t('forms.export_csv')}
                                    >
                                        <Download className="h-4 w-4 mr-2" />
                                        {t('forms.export_csv')}
                                    </Button>
                                )}
                            </DialogHeader>

                            {/* Barre de filtres */}
                            <div className="flex flex-wrap items-center justify-between gap-2 py-3 border-b">
                                <div className="flex items-center gap-1">
                                    {(['all', 'unread', 'read', 'ham', 'spam'] as const).map(filterKey => {
                                        const labelMap = {
                                            all: t('forms.filter_all'),
                                            unread: t('forms.filter_unread'),
                                            read: t('forms.filter_read'),
                                            ham: t('forms.filter_ham'),
                                            spam: t('forms.filter_spam'),
                                        };
                                        return (
                                            <Button
                                                key={filterKey}
                                                variant={submissionsFilter === filterKey ? 'default' : 'ghost'}
                                                size="sm"
                                                className="text-xs h-7 px-2.5"
                                                onClick={() => handleFilterChange(filterKey)}
                                            >
                                                {labelMap[filterKey]}
                                            </Button>
                                        );
                                    })}
                                </div>

                                {/* Actions groupées */}
                                {selectedIds.length > 0 && showSubmissions !== null && (
                                    <div className="flex items-center gap-2 bg-muted/60 p-1 rounded-md">
                                        <span className="text-xs font-medium px-2">
                                            {selectedIds.length} {t('forms.selected_count')}
                                        </span>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="h-7 text-xs"
                                            disabled={batchLoading}
                                            onClick={() => handleBatchAction('mark_read')}
                                            title={t('forms.mark_read')}
                                        >
                                            <MailOpen className="h-3.5 w-3.5 mr-1" />
                                            {t('forms.mark_read')}
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="h-7 text-xs"
                                            disabled={batchLoading}
                                            onClick={() => handleBatchAction('mark_unread')}
                                            title={t('forms.mark_unread')}
                                        >
                                            <Mail className="h-3.5 w-3.5 mr-1" />
                                            {t('forms.mark_unread')}
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="h-7 text-xs"
                                            disabled={batchLoading}
                                            onClick={() => handleBatchAction('mark_ham')}
                                            title={t('forms.mark_ham')}
                                        >
                                            <ShieldCheck className="h-3.5 w-3.5 mr-1 text-green-600" />
                                            {t('forms.mark_ham')}
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="h-7 text-xs"
                                            disabled={batchLoading}
                                            onClick={() => handleBatchAction('mark_spam')}
                                            title={t('forms.mark_spam')}
                                        >
                                            <ShieldAlert className="h-3.5 w-3.5 mr-1 text-amber-600" />
                                            {t('forms.mark_spam')}
                                        </Button>
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            className="h-7 text-xs"
                                            disabled={batchLoading}
                                            onClick={() => handleBatchAction('delete')}
                                            title={t('common.delete')}
                                        >
                                            <Trash2 className="h-3.5 w-3.5 mr-1" />
                                            {t('common.delete')}
                                        </Button>
                                    </div>
                                )}
                            </div>

                            {/* Contenu tableau */}
                            <div className="flex-1 overflow-auto py-2">
                                {submissionsLoading ? (
                                    <p className="text-center text-muted-foreground py-8">{t('common.loading')}</p>
                                ) : !submissions || submissions.data.length === 0 ? (
                                    <p className="text-center text-muted-foreground py-8">{t('forms.no_submissions')}</p>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-[40px]">
                                                    <Checkbox
                                                        checked={allVisibleSelected}
                                                        onCheckedChange={toggleSelectAll}
                                                        aria-label={t('forms.batch_actions')}
                                                    />
                                                </TableHead>
                                                <TableHead className="w-[60px]">ID</TableHead>
                                                <TableHead>{t('forms.data_col')}</TableHead>
                                                <TableHead className="w-[110px]">{t('forms.status')}</TableHead>
                                                <TableHead className="w-[140px]">{t('forms.date_col')}</TableHead>
                                                <TableHead className="w-[110px] text-right">{t('forms.actions')}</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {submissions.data.map(s => {
                                                const isSelected = selectedIds.includes(s.id);
                                                return (
                                                    <TableRow key={s.id} className={s.isSpam ? 'opacity-60 bg-amber-50/20' : ''}>
                                                        <TableCell>
                                                            <Checkbox
                                                                checked={isSelected}
                                                                onCheckedChange={() => toggleSelectOne(s.id)}
                                                                aria-label={`Select submission #${s.id}`}
                                                            />
                                                        </TableCell>
                                                        <TableCell className="font-mono text-xs">#{s.id}</TableCell>
                                                        <TableCell className="max-w-[340px]">
                                                            <pre className="text-xs whitespace-pre-wrap font-mono max-h-24 overflow-auto rounded bg-muted/40 p-1.5">
                                                                {JSON.stringify(s.data, null, 1)}
                                                            </pre>
                                                        </TableCell>
                                                        <TableCell>
                                                            <div className="flex flex-col gap-1 items-start">
                                                                {s.isSpam ? (
                                                                    <Badge variant="destructive">{t('forms.spam')}</Badge>
                                                                ) : (
                                                                    <Badge variant="outline" className="border-green-600/40 text-green-700 dark:text-green-400">
                                                                        {t('forms.valid')}
                                                                    </Badge>
                                                                )}
                                                                {!s.isRead && (
                                                                    <Badge variant="secondary" className="text-[10px] px-1 py-0 font-medium">
                                                                        {t('forms.unread')}
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                        </TableCell>
                                                        <TableCell className="text-xs text-muted-foreground whitespace-nowrap">
                                                            {formatDate(s.createdAt)}
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            <div className="flex items-center justify-end gap-1">
                                                                {showSubmissions !== null && (
                                                                    <>
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="icon"
                                                                            className="h-7 w-7"
                                                                            onClick={() => handleUpdateSubmission(showSubmissions, s.id, { isRead: !s.isRead })}
                                                                            title={s.isRead ? t('forms.mark_unread') : t('forms.mark_read')}
                                                                        >
                                                                            {s.isRead ? <MailOpen className="h-3.5 w-3.5 text-muted-foreground" /> : <Mail className="h-3.5 w-3.5 text-blue-600" />}
                                                                        </Button>
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="icon"
                                                                            className="h-7 w-7"
                                                                            onClick={() => handleUpdateSubmission(showSubmissions, s.id, { isSpam: !s.isSpam })}
                                                                            title={s.isSpam ? t('forms.mark_ham') : t('forms.mark_spam')}
                                                                        >
                                                                            {s.isSpam ? <ShieldCheck className="h-3.5 w-3.5 text-green-600" /> : <ShieldAlert className="h-3.5 w-3.5 text-amber-600" />}
                                                                        </Button>
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="icon"
                                                                            className="h-7 w-7 text-red-500 hover:text-red-700"
                                                                            onClick={() => handleDeleteSubmission(showSubmissions, s.id)}
                                                                            title={t('common.delete')}
                                                                        >
                                                                            <Trash2 className="h-3.5 w-3.5" />
                                                                        </Button>
                                                                    </>
                                                                )}
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                )}
                            </div>

                            {/* Pagination */}
                            {totalPages > 1 && showSubmissions !== null && (
                                <div className="flex items-center justify-between pt-3 border-t">
                                    <div className="text-xs text-muted-foreground">
                                        {t('forms.page_info')
                                            .replace('%page%', String(submissionsPage))
                                            .replace('%pages%', String(totalPages))}
                                    </div>
                                    <div className="flex items-center gap-1">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="h-7 text-xs px-2"
                                            disabled={submissionsPage <= 1 || submissionsLoading}
                                            onClick={() => fetchSubmissions(showSubmissions, submissionsPage - 1)}
                                        >
                                            <ChevronLeft className="h-3.5 w-3.5 mr-1" />
                                            {t('forms.prev_page')}
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="h-7 text-xs px-2"
                                            disabled={submissionsPage >= totalPages || submissionsLoading}
                                            onClick={() => fetchSubmissions(showSubmissions, submissionsPage + 1)}
                                        >
                                            {t('forms.next_page')}
                                            <ChevronRight className="h-3.5 w-3.5 ml-1" />
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
        </AppLayout>
    );
}
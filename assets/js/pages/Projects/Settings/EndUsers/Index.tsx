import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { Search, Plus, MoreHorizontal, Eye, Pencil, Ban, CheckCircle, Trash2, Settings2 } from 'lucide-react';

import type { Project, BreadcrumbItem, UserCan, EndUser } from '@/types';

import AppLayout from '@/layouts/app-layout';
import ProjectsLayout from '@/pages/Projects/layout';
import ProjectSidebar from '@/pages/Projects/ProjectSidebar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Checkbox } from '@/components/ui/checkbox';
import { AlertDialog, AlertDialogContent, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogDescription, AlertDialogCancel, AlertDialogAction } from '@/components/ui/alert-dialog';
import { useTranslation } from '@/lib/i18n';

const API = (projectUuid: string) => `/api/projects/${projectUuid}/end-users`;

interface Props {
    project: Project;
    endUsers: {
        data: EndUser[];
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
    };
    filters: {
        status: string;
        search: string;
        per_page: string;
    };
    userCan: UserCan;
}

export default function EndUsersIndex({ project, endUsers, filters }: Props) {
    const t = useTranslation();
    const [search, setSearch] = useState(filters.search || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || '');
    const [deleteTarget, setDeleteTarget] = useState<EndUser | null>(null);
    const [bulkConfirmOpen, setBulkConfirmOpen] = useState<'ban' | 'delete' | null>(null);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [bulkBusy, setBulkBusy] = useState(false);

    const allSelected = endUsers.data.length > 0 && selected.size === endUsers.data.length;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('end_users.heading'), href: route('projects.settings.end-users', project.id) },
    ];

    function applyFilters() {
        router.get(
            route('projects.settings.end-users', project.id),
            { search, status: statusFilter, per_page: endUsers.per_page },
            { preserveState: true, replace: true }
        );
    }

    function handleSearch(e: React.FormEvent) {
        e.preventDefault();
        applyFilters();
    }

    function handleStatusFilter(status: string) {
        setStatusFilter(status);
        router.get(
            route('projects.settings.end-users', project.id),
            { search, status, per_page: endUsers.per_page },
            { preserveState: true, replace: true }
        );
    }

    const baseApi = API(project.uuid);

    function confirmDelete(endUser: EndUser) {
        setDeleteTarget(endUser);
    }

    async function executeDelete() {
        if (!deleteTarget) return;
        try {
            await axios.delete(`${baseApi}/${deleteTarget.uuid}`);
            toast.success(t('end_users.deleted'));
            router.reload({ only: ['endUsers'] });
        } catch {
            toast.error(t('end_users.delete_error'));
        } finally {
            setDeleteTarget(null);
        }
    }

    async function toggleBan(endUser: EndUser) {
        const newStatus = endUser.status === 'banned' ? 'active' : 'banned';
        try {
            await axios.patch(`${baseApi}/${endUser.uuid}/status`, { status: newStatus });
            toast.success(newStatus === 'banned' ? t('end_users.ban_success') : t('end_users.unban_success'));
            router.reload({ only: ['endUsers'] });
        } catch {
            toast.error(t('end_users.status_error'));
        }
    }

    function toggleAll() {
        if (allSelected) { setSelected(new Set()); }
        else { setSelected(new Set(endUsers.data.map(eu => eu.uuid))); }
    }

    function toggleOne(uuid: string) {
        setSelected(prev => {
            const next = new Set(prev);
            next.has(uuid) ? next.delete(uuid) : next.add(uuid);
            return next;
        });
    }

    async function bulkBan() {
        if (selected.size === 0) return;
        setBulkBusy(true);
        let ok = 0;
        for (const uuid of selected) {
            try {
                await axios.patch(`${baseApi}/${uuid}/status`, { status: 'banned' });
                ok++;
            } catch {}
        }
        setBulkBusy(false); setSelected(new Set());
        toast.success(t('end_users.bulk_ban_done', { count: String(ok) }));
        router.reload({ only: ['endUsers'] });
    }

    async function bulkDelete() {
        if (selected.size === 0) return;
        setBulkBusy(true);
        let ok = 0;
        for (const uuid of selected) {
            try {
                await axios.delete(`${baseApi}/${uuid}`);
                ok++;
            } catch {}
        }
        setBulkBusy(false); setSelected(new Set());
        toast.success(t('end_users.bulk_delete_done', { count: String(ok) }));
        router.reload({ only: ['endUsers'] });
    }

    function getInitials(name: string | null, email: string): string {
        if (name) return name.substring(0, 2).toUpperCase();
        return email.substring(0, 2).toUpperCase();
    }

    function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
        switch (status) {
            case 'active': return 'default';
            case 'banned': return 'destructive';
            case 'pending': return 'secondary';
            default: return 'outline';
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('end_users.heading')} — ${project.name}`} />
            <ProjectsLayout>
                <ProjectSidebar project={project} />
                <div className="flex-1 min-w-0 space-y-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h2 className="text-lg font-semibold">{t('end_users.heading')}</h2>
                            <p className="text-sm text-muted-foreground">{t('end_users.heading_desc')}</p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button asChild size="sm" variant="outline">
                                <Link href={route('projects.settings.end-users.schema', project.id)}>
                                    <Settings2 className="mr-1 h-4 w-4" /> {t('end_users.schema')}
                                </Link>
                            </Button>
                            <Button asChild size="sm">
                                <Link href={route('projects.settings.end-users.create', project.id)}>
                                    <Plus className="mr-1 h-4 w-4" /> {t('end_users.create')}
                                </Link>
                            </Button>
                        </div>
                    </div>

                    {/* Filters */}
                    <div className="flex items-center gap-3">
                        <form onSubmit={handleSearch} className="flex items-center gap-2 flex-1 max-w-sm">
                            <Input
                                placeholder={t('end_users.search')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="h-9"
                            />
                            <Button type="submit" size="sm" variant="outline" className="h-9">
                                <Search className="h-4 w-4" />
                            </Button>
                        </form>
                        <select
                            value={statusFilter}
                            onChange={(e) => handleStatusFilter(e.target.value)}
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">{t('end_users.filter_status')}</option>
                            <option value="active">{t('end_users.status_active')}</option>
                            <option value="banned">{t('end_users.status_banned')}</option>
                            <option value="pending">{t('end_users.status_pending')}</option>
                        </select>
                    </div>

                    {/* Bulk actions */}
                    {selected.size > 0 && (
                        <div className="flex items-center gap-2 rounded-md border border-primary/30 bg-primary/5 px-3 py-2">
                            <span className="text-sm font-medium">{t('end_users.selected_count', { count: String(selected.size) })}</span>
                            <Button size="sm" variant="outline" disabled={bulkBusy} onClick={() => setBulkConfirmOpen('ban')}>
                                <Ban className="mr-1 h-3.5 w-3.5" /> {t('end_users.ban')}
                            </Button>
                            <Button size="sm" variant="outline" disabled={bulkBusy} onClick={() => setBulkConfirmOpen('delete')}>
                                <Trash2 className="mr-1 h-3.5 w-3.5" /> {t('end_users.delete')}
                            </Button>
                        </div>
                    )}

                    {/* Bulk confirmation dialog */}
                    <AlertDialog open={bulkConfirmOpen !== null} onOpenChange={() => setBulkConfirmOpen(null)}>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    {bulkConfirmOpen === 'ban' ? t('end_users.bulk_ban_title', { count: String(selected.size) }) : t('end_users.bulk_delete_title', { count: String(selected.size) })}
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    {bulkConfirmOpen === 'ban'
                                        ? t('end_users.bulk_ban_desc', { count: String(selected.size) })
                                        : t('end_users.bulk_delete_desc', { count: String(selected.size) })}
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel disabled={bulkBusy}>{t('common.cancel')}</AlertDialogCancel>
                                <AlertDialogAction
                                    disabled={bulkBusy}
                                    className={bulkConfirmOpen === 'delete' ? 'bg-destructive text-destructive-foreground' : ''}
                                    onClick={() => {
                                        if (bulkConfirmOpen === 'ban') bulkBan();
                                        else bulkDelete();
                                    }}
                                >
                                    {bulkBusy ? t('common.loading') : (bulkConfirmOpen === 'ban' ? t('end_users.ban') : t('end_users.delete'))}
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>

                    {/* Table */}
                    <div className="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10">
                                        <Checkbox
                                            checked={allSelected}
                                            onCheckedChange={toggleAll}
                                            aria-label="Select all"
                                        />
                                    </TableHead>
                                    <TableHead>{t('end_users.col_user')}</TableHead>
                                    <TableHead>{t('end_users.col_email')}</TableHead>
                                    <TableHead>{t('end_users.col_status')}</TableHead>
                                    <TableHead>{t('end_users.col_created')}</TableHead>
                                    <TableHead className="w-10"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {endUsers.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                                            {t('end_users.no_users')}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    endUsers.data.map((eu) => (
                                        <TableRow key={eu.uuid}>
                                            <TableCell>
                                                <Checkbox
                                                    checked={selected.has(eu.uuid)}
                                                    onCheckedChange={() => toggleOne(eu.uuid)}
                                                    aria-label={`Select ${eu.email}`}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-3">
                                                    <Avatar className="h-8 w-8">
                                                        <AvatarFallback className="text-xs">{getInitials(eu.username, eu.email)}</AvatarFallback>
                                                    </Avatar>
                                                    <span className="font-medium text-sm">{eu.username || '—'}</span>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">{eu.email}</TableCell>
                                            <TableCell>
                                                <Badge variant={statusVariant(eu.status)}>{eu.status}</Badge>
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {new Date(eu.created_at).toLocaleDateString()}
                                            </TableCell>
                                            <TableCell>
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button variant="ghost" size="icon" className="h-8 w-8">
                                                            <MoreHorizontal className="h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuLabel>{t('end_users.actions')}</DropdownMenuLabel>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem asChild>
                                                            <Link href={route('projects.settings.end-users.show', { project: project.id, endUserUuid: eu.uuid })}>
                                                                <Eye className="mr-2 h-4 w-4" /> {t('end_users.view')}
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem asChild>
                                                            <Link href={route('projects.settings.end-users.edit', { project: project.id, endUserUuid: eu.uuid })}>
                                                                <Pencil className="mr-2 h-4 w-4" /> {t('end_users.edit_action')}
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem onClick={() => toggleBan(eu)}>
                                                            {eu.status === 'banned' ? (
                                                                <><CheckCircle className="mr-2 h-4 w-4" /> {t('end_users.unban_action')}</>
                                                            ) : (
                                                                <><Ban className="mr-2 h-4 w-4" /> {t('end_users.ban_action')}</>
                                                            )}
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            className="text-destructive"
                                                            onClick={() => confirmDelete(eu)}
                                                        >
                                                            <Trash2 className="mr-2 h-4 w-4" /> {t('end_users.delete_action')}
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Pagination */}
                    {endUsers.last_page > 1 && (
                        <div className="flex items-center justify-between text-sm text-muted-foreground">
                            <span>{t('end_users.total', { count: String(endUsers.total) })}</span>
                            <div className="flex items-center gap-1">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={endUsers.current_page <= 1}
                                    onClick={() => router.get(route('projects.settings.end-users', project.id), { search, status: statusFilter, page: endUsers.current_page - 1, per_page: endUsers.per_page }, { preserveState: true, replace: true })}
                                >
                                    {t('end_users.prev')}
                                </Button>
                                {Array.from({ length: endUsers.last_page }, (_, i) => i + 1).map((p) => (
                                    <Button
                                        key={p}
                                        variant={p === endUsers.current_page ? 'default' : 'outline'}
                                        size="sm"
                                        onClick={() => router.get(route('projects.settings.end-users', project.id), { search, status: statusFilter, page: p, per_page: endUsers.per_page }, { preserveState: true, replace: true })}
                                    >
                                        {p}
                                    </Button>
                                ))}
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={endUsers.current_page >= endUsers.last_page}
                                    onClick={() => router.get(route('projects.settings.end-users', project.id), { search, status: statusFilter, page: endUsers.current_page + 1, per_page: endUsers.per_page }, { preserveState: true, replace: true })}
                                >
                                    {t('end_users.next')}
                                </Button>
                            </div>
                        </div>
                    )}
                </div>

                {/* Delete confirmation */}
                <AlertDialog open={deleteTarget !== null} onOpenChange={() => setDeleteTarget(null)}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>{t('end_users.delete_title')}</AlertDialogTitle>
                            <AlertDialogDescription>
                                {t('end_users.delete_desc', { email: deleteTarget?.email ?? '' })}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
                            <AlertDialogAction onClick={executeDelete} className="bg-destructive text-destructive-foreground">{t('end_users.delete')}</AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </ProjectsLayout>
        </AppLayout>
    );
}

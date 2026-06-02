import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ArrowLeft, Pencil, Ban, CheckCircle, Trash2 } from 'lucide-react';

import type { Project, BreadcrumbItem, UserCan, EndUser } from '@/types';

import AppLayout from '@/layouts/app-layout';
import ProjectsLayout from '@/pages/Projects/layout';
import ProjectSidebar from '@/pages/Projects/ProjectSidebar';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Separator } from '@/components/ui/separator';
import { AlertDialog, AlertDialogContent, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogDescription, AlertDialogCancel, AlertDialogAction } from '@/components/ui/alert-dialog';
import { useTranslation } from '@/lib/i18n';

interface Props {
    project: Project;
    endUser: EndUser;
    userCan: UserCan;
}

export default function EndUsersShow({ project, endUser }: Props) {
    const t = useTranslation();
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [statusLoading, setStatusLoading] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('end_users.heading'), href: route('projects.settings.end-users', project.id) },
        { title: endUser.email, href: '#' },
    ];

    function getInitials(): string {
        if (endUser.name) return endUser.name.substring(0, 2).toUpperCase();
        return endUser.email.substring(0, 2).toUpperCase();
    }

    function toggleBan() {
        setStatusLoading(true);
        const newStatus = endUser.status === 'banned' ? 'active' : 'banned';
        router.patch(
            route('projects.settings.end-users.status', { project: project.id, endUserUuid: endUser.uuid }),
            { status: newStatus },
            {
                onSuccess: () => toast.success(newStatus === 'banned' ? t('end_users.ban_success') : t('end_users.unban_success')),
                onError: () => toast.error(t('end_users.status_error')),
                onFinish: () => setStatusLoading(false),
            }
        );
    }

    function executeDelete() {
        // Le backend DELETE redirige vers la liste -> Inertia suit automatiquement
        router.delete(
            route('projects.settings.end-users.destroy', { project: project.id, endUserUuid: endUser.uuid }),
            {
                onSuccess: () => toast.success(t('end_users.deleted')),
                onError: () => toast.error(t('end_users.delete_error')),
            }
        );
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
            <Head title={`${endUser.email} — ${t('end_users.heading')}`} />
            <ProjectsLayout>
                <ProjectSidebar project={project} />
                <div className="flex-1 min-w-0 space-y-6">
                    <div className="flex items-center gap-2">
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={route('projects.settings.end-users', project.id)}>
                                <ArrowLeft className="mr-1 h-4 w-4" /> {t('end_users.back')}
                            </Link>
                        </Button>
                    </div>

                    <div className="flex items-start justify-between">
                        <div className="flex items-center gap-4">
                            <Avatar className="h-14 w-14">
                                <AvatarFallback className="text-lg">{getInitials()}</AvatarFallback>
                            </Avatar>
                            <div>
                                <h2 className="text-xl font-semibold">{endUser.name || t('end_users.unnamed')}</h2>
                                <p className="text-sm text-muted-foreground">{endUser.email}</p>
                                <Badge variant={statusVariant(endUser.status)} className="mt-1">{endUser.status}</Badge>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('projects.settings.end-users.edit', { project: project.id, endUserUuid: endUser.uuid })}>
                                    <Pencil className="mr-1 h-4 w-4" /> {t('end_users.edit_action')}
                                </Link>
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={toggleBan}
                                disabled={statusLoading}
                            >
                                {endUser.status === 'banned' ? (
                                    <><CheckCircle className="mr-1 h-4 w-4" /> {t('end_users.unban_action')}</>
                                ) : (
                                    <><Ban className="mr-1 h-4 w-4" /> {t('end_users.ban_action')}</>
                                )}
                            </Button>
                            <Button variant="outline" size="sm" className="text-destructive border-destructive" onClick={() => setDeleteOpen(true)}>
                                <Trash2 className="mr-1 h-4 w-4" /> {t('end_users.delete_action')}
                            </Button>
                        </div>
                    </div>

                    <Separator />

                    <div className="grid grid-cols-2 gap-6 max-w-lg">
                        <div>
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">{t('end_users.uuid')}</p>
                            <p className="text-sm font-mono mt-1">{endUser.uuid}</p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">{t('end_users.col_email')}</p>
                            <p className="text-sm mt-1">{endUser.email}</p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">{t('end_users.col_status')}</p>
                            <p className="text-sm mt-1"><Badge variant={statusVariant(endUser.status)}>{endUser.status}</Badge></p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">{t('end_users.token_version')}</p>
                            <p className="text-sm mt-1">{endUser.token_version}</p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">{t('end_users.created_at')}</p>
                            <p className="text-sm mt-1">{new Date(endUser.created_at).toLocaleString()}</p>
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">{t('end_users.updated_at')}</p>
                            <p className="text-sm mt-1">{new Date(endUser.updated_at).toLocaleString()}</p>
                        </div>
                        {endUser.custom_fields && Object.keys(endUser.custom_fields).length > 0 && (
                            <div className="col-span-2">
                                <p className="text-xs text-muted-foreground uppercase tracking-wider mb-2">{t('end_users.custom_fields')}</p>
                                <pre className="text-sm bg-muted p-3 rounded-md overflow-auto">{JSON.stringify(endUser.custom_fields, null, 2)}</pre>
                            </div>
                        )}
                    </div>
                </div>

                <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>{t('end_users.delete_title')}</AlertDialogTitle>
                            <AlertDialogDescription>
                                {t('end_users.delete_desc', { email: endUser.email })}
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

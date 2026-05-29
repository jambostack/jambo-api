import { useState, useRef, useEffect } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';

import { BreadcrumbItem, Role, UserCan } from '@/types';

import AppLayout from '@/layouts/app-layout';
import UserManagementLayout from './layout';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/input-error';
import { Eye, EyeOff, Plus, Users, Shield, Key, Lock, Projector } from 'lucide-react';
import { DataTable, ColumnFilter, DataTableRef } from '@/components/ui/data-table';
import { useTranslation } from '@/lib/i18n';

interface RolesPageProps {
    permissionGroups: {
        groups: PermissionGroup[];
        projects: ProjectPermission[];
    };
}

interface PermissionGroup {
    group: string;
    label: string;
    icon: string;
    permissions: string[];
}

interface ProjectPermission {
    name: string;
    permission: string;
    icon: string;
}

export default function Roles({ permissionGroups: initialPermissionGroups }: RolesPageProps) {
    const groups = initialPermissionGroups?.groups || [];
    const projects = initialPermissionGroups?.projects || [];
    const t = useTranslation();
    const can = (usePage().props.userCan || {}) as UserCan;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('users.breadcrumb'), href: '/user-management/users' },
        { title: t('roles.breadcrumb'), href: '/user-management/roles' },
    ];

    const [openModal, setOpenModal] = useState(false);
    const [editing, setEditing] = useState(false);
    const [openDeleteModal, setOpenDeleteModal] = useState(false);
    const [openBulkDeleteModal, setOpenBulkDeleteModal] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [bulkDeletePassword, setBulkDeletePassword] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [selectedItems, setSelectedItems] = useState<Role[]>([]);
    const [bulkDeleteErrors, setBulkDeleteErrors] = useState<Record<string, string>>({});
    const dataTableRef = useRef<DataTableRef>(null);

    const routePrefix = '/api/roles';

    const [formData, setFormData] = useState({
        id: 0,
        name: '',
        permissions: [] as string[],
    });

    const resetForm = () => {
        setFormData({
            id: 0,
            name: '',
            permissions: [],
        });
        setErrors({});
    };

    const openNewModal = () => {
        resetForm();
        setEditing(false);
        setOpenModal(true);
    };

    const closeModal = () => {
        setOpenModal(false);
        setEditing(false);
        resetForm();
    };

    const handleSuccess = () => {
        dataTableRef.current?.fetchData();
    };

    const submitForm = async (e: React.FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        try {
            if (editing) {
                const response = await axios.put(`${routePrefix}/${formData.id}`, formData);
                toast.success(response.data.message);
            } else {
                const response = await axios.post(routePrefix, formData);
                toast.success(response.data.message);
            }

            setOpenModal(false);
            setEditing(false);
            resetForm();
            handleSuccess();
        } catch (error: any) {
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors);
            } else {
                toast.error(t('common.error'));
            }
        } finally {
            setProcessing(false);
        }
    };

    const editItem = (item: Role) => {
        if (!can.manage_roles) return;

        setFormData({
            id: item.id,
            name: item.name,
            permissions: item.permissions?.map(permission => permission.name) || [],
        });

        setEditing(true);
        setOpenModal(true);
    };

    const confirmDelete = () => {
        setOpenDeleteModal(true);
    };

    const confirmBulkDelete = () => {
        setOpenBulkDeleteModal(true);
    };

    const deleteItem = async () => {
        setProcessing(true);

        try {
            const response = await axios.delete(`${routePrefix}/${formData.id}`);
            toast.success(response.data.message);
            setOpenDeleteModal(false);
            closeModal();
            handleSuccess();
        } catch (error: any) {
            if (error.response?.status === 422) {
                toast.error(error.response.data.error);
                setOpenDeleteModal(false);
            } else {
                toast.error(error.response.data.error);
            }
        } finally {
            setProcessing(false);
        }
    };

    const deleteSelected = async () => {
        if (!bulkDeletePassword) {
            toast.error(t('roles.password_required'));
            return;
        }

        setProcessing(true);
        setBulkDeleteErrors({});

        try {
            const response = await axios.post(`${routePrefix}/bulk-delete`, {
                ids: selectedItems.map(item => item.id),
                password: bulkDeletePassword
            });
            toast.success(response.data.message);
            setOpenBulkDeleteModal(false);
            setSelectedItems([]);
            setBulkDeletePassword('');
            handleSuccess();
        } catch (error: any) {
            if (error.response?.status === 422) {
                if (error.response.data.errors) {
                    setBulkDeleteErrors(error.response.data.errors);
                } else if (error.response.data.error) {
                    toast.error(error.response.data.error);
                }
            } else if (error.response?.status === 403) {
                toast.error(t('roles.invalid_password'));
            } else {
                toast.error(t('roles.delete_error'));
            }
        } finally {
            setProcessing(false);
        }
    };

    const handleSelectAll = (_group: string, permissions: string[], checked: boolean) => {
        setFormData(prev => {
            const newPermissions = [...prev.permissions];

            if (checked) {
                permissions.forEach(permission => {
                    if (!newPermissions.includes(permission)) {
                        newPermissions.push(permission);
                    }
                });
            } else {
                permissions.forEach(permission => {
                    const index = newPermissions.indexOf(permission);
                    if (index > -1) {
                        newPermissions.splice(index, 1);
                    }
                });
            }

            return { ...prev, permissions: newPermissions };
        });
    };

    const handleSelectAllProjects = (checked: boolean) => {
        setFormData(prev => {
            const newPermissions = [...prev.permissions];

            if (checked) {
                projects.forEach((permission: ProjectPermission) => {
                    if (!newPermissions.includes(permission.permission)) {
                        newPermissions.push(permission.permission);
                    }
                });
            } else {
                projects.forEach((permission: ProjectPermission) => {
                    const index = newPermissions.indexOf(permission.permission);
                    if (index > -1) {
                        newPermissions.splice(index, 1);
                    }
                });
            }

            return { ...prev, permissions: newPermissions };
        });
    };

    const tableColumns = [
        {
            header: t('roles.col_name'),
            accessorKey: 'name',
            sortable: true,
            filter: {
                type: 'text' as const,
                placeholder: t('roles.filter_name')
            } as ColumnFilter
        },
        {
            header: t('roles.col_permissions'),
            accessorKey: 'permissions',
            cell: (item: Role) => (
                <div className="flex flex-wrap gap-1">
                    <Badge variant="secondary">
                        {t('roles.permission_count', { count: String(item.permissions?.length || 0) })}
                    </Badge>
                </div>
            ),
        },
        {
            header: t('roles.col_created'),
            accessorKey: 'created_at',
            sortable: true,
            filter: {
                type: 'date' as const,
                placeholder: t('roles.filter_date')
            } as ColumnFilter,
            cell: (item: Role) => (
                <div className="text-sm text-muted-foreground">
                    {new Date(item.created_at).toLocaleString()}
                </div>
            ),
        },
        {
            header: t('roles.col_updated'),
            accessorKey: 'updated_at',
            sortable: true,
            filter: {
                type: 'date' as const,
                placeholder: t('roles.filter_updated')
            } as ColumnFilter,
            cell: (item: Role) => (
                <div className="text-sm text-muted-foreground">
                    {new Date(item.updated_at).toLocaleString()}
                </div>
            ),
        },
    ];

    const tableActionButtons = [
        {
            label: t('roles.delete_selected'),
            onClick: confirmBulkDelete,
            variant: 'destructive' as const,
            show: can.manage_roles && selectedItems.length > 0,
        },
        {
            label: t('roles.create'),
            onClick: openNewModal,
            variant: 'default' as const,
            icon: <Plus className="h-4 w-4" />,
            show: can.manage_roles,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('roles.title')} />

            <UserManagementLayout can={can}>
                <div className="space-y-4">
                    <DataTable
                        ref={dataTableRef}
                        columns={tableColumns}
                        searchPlaceholder={t('roles.search')}
                        searchRoute={routePrefix}
                        actions={tableActionButtons.filter(button => button.show)}
                        selectable={can.manage_roles}
                        onSelectionChange={setSelectedItems}
                        selectedItems={selectedItems}
                        pageName="roles-table"
                        onRowClick={editItem}
                    />
                </div>
            </UserManagementLayout>

            <Dialog open={openModal} onOpenChange={(open) => {
                setOpenModal(open);
                if (!open) {
                    closeModal();
                }
            }}>
                <DialogContent className="sm:max-w-4xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="flex justify-between items-center">
                            <span>{editing ? t('roles.dialog_edit_title') : t('roles.dialog_create_title')}</span>
                            {editing && can.manage_roles && (
                                <Button variant="destructive" size="sm" onClick={confirmDelete}>
                                    {t('common.delete')}
                                </Button>
                            )}
                        </DialogTitle>
                        <DialogDescription className="sr-only">
                            {editing ? t('roles.dialog_edit_desc') : t('roles.dialog_create_desc')}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitForm} className="space-y-4 max-h-[60vh] overflow-y-auto px-2">
                        <div className="space-y-2">
                            <Label htmlFor="name">{t('roles.col_name')}</Label>
                            <Input
                                id="name"
                                type="text"
                                value={formData.name}
                                onChange={(e) => setFormData(prev => ({ ...prev, name: e.target.value }))}
                                required
                                className="max-w-md"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-2 xl:grid-cols-3">
                                {groups.map((group: PermissionGroup) => {
                                    const Icon = {
                                        'Users': Users,
                                        'Shield': Shield,
                                        'Key': Key,
                                    }[group.icon] || Users;

                                    return (
                                        <div key={group.group} className="p-4 border rounded-lg bg-card hover:bg-accent/50 transition-colors">
                                            <div className="flex justify-between items-center mb-3">
                                                <div className="flex items-center gap-2">
                                                    <Icon className="h-5 w-5 text-primary" />
                                                    <span className="font-medium">{group.label}</span>
                                                </div>
                                                <Checkbox
                                                    checked={group.permissions.every((perm: string) =>
                                                        formData.permissions.includes(perm)
                                                    )}
                                                    onCheckedChange={(checked) =>
                                                        handleSelectAll(group.group, group.permissions, checked as boolean)
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2 pl-6">
                                                {group.permissions.map((permission: string) => (
                                                    <div key={permission} className="flex items-center space-x-2">
                                                        <Checkbox
                                                            id={`${permission}`}
                                                            checked={formData.permissions.includes(`${permission}`)}
                                                            onCheckedChange={(checked) => {
                                                                setFormData(prev => {
                                                                    const newPermissions = [...prev.permissions];
                                                                    if (checked) {
                                                                        newPermissions.push(permission);
                                                                    } else {
                                                                        const index = newPermissions.indexOf(permission);
                                                                        if (index > -1) {
                                                                            newPermissions.splice(index, 1);
                                                                        }
                                                                    }
                                                                    return { ...prev, permissions: newPermissions };
                                                                });
                                                            }}
                                                        />
                                                        <Label htmlFor={`${permission}`} className="text-sm">
                                                            {permission.charAt(0).toUpperCase() + permission.slice(1)}
                                                        </Label>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            <div className="p-4 border rounded-lg bg-card hover:bg-accent/50 transition-colors">
                                <div className="flex justify-between items-center mb-3">
                                    <div className="flex items-center gap-2">
                                        <Projector className="h-5 w-5 text-primary" />
                                        <span className="font-medium">{t('roles.projects_group')}</span>
                                    </div>
                                    <Checkbox
                                        checked={projects.every((perm: ProjectPermission) =>
                                            formData.permissions.includes(perm.permission)
                                        )}
                                        onCheckedChange={(checked) =>
                                            handleSelectAllProjects(checked as boolean)
                                        }
                                    />
                                </div>
                                <div className="space-y-2 pl-6">
                                    {projects.map((permission: ProjectPermission) => (
                                        <div key={permission.permission} className="flex items-center space-x-2">
                                            <Checkbox
                                                id={permission.permission}
                                                checked={formData.permissions.includes(permission.permission)}
                                                onCheckedChange={(checked) => {
                                                    setFormData(prev => {
                                                        const newPermissions = [...prev.permissions];
                                                        if (checked) {
                                                            newPermissions.push(permission.permission);
                                                        } else {
                                                            const index = newPermissions.indexOf(permission.permission);
                                                            if (index > -1) {
                                                                newPermissions.splice(index, 1);
                                                            }
                                                        }
                                                        return { ...prev, permissions: newPermissions };
                                                    });
                                                }}
                                            />
                                            <Label htmlFor={permission.permission} className="text-sm">
                                                {permission.name}
                                            </Label>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>

                        <DialogFooter className="pt-4 border-t">
                            <Button type="submit" disabled={processing}>
                                {editing ? t('roles.update') : t('roles.create')}
                            </Button>
                            <Button type="button" variant="outline" onClick={closeModal}>
                                {t('common.cancel')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Delete Confirmation Modal */}
            <Dialog open={openDeleteModal} onOpenChange={setOpenDeleteModal}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('roles.delete_title')}</DialogTitle>
                        <DialogDescription>
                            {t('roles.delete_desc')}
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setOpenDeleteModal(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button variant="destructive" onClick={deleteItem} disabled={processing}>
                            {t('roles.delete_btn')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Bulk Delete Confirmation Modal */}
            <Dialog open={openBulkDeleteModal} onOpenChange={(open) => {
                setOpenBulkDeleteModal(open);
                if (!open) {
                    setBulkDeletePassword('');
                    setBulkDeleteErrors({});
                }
            }}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('roles.bulk_delete_title', { count: String(selectedItems.length) })}</DialogTitle>
                        <DialogDescription>
                            {t('roles.bulk_delete_desc')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        <div className="space-y-2">
                            <Label htmlFor="password">{t('roles.password_label')}</Label>
                            <div className="relative">
                                <Input
                                    id="password"
                                    type={showPassword ? "text" : "password"}
                                    value={bulkDeletePassword}
                                    onChange={(e) => setBulkDeletePassword(e.target.value)}
                                    placeholder={t('roles.password_placeholder')}
                                />
                                <button
                                    type="button"
                                    className="absolute inset-y-0 right-0 flex items-center pr-3"
                                    onClick={() => setShowPassword(!showPassword)}
                                >
                                    {showPassword ? (
                                        <EyeOff className="h-4 w-4 text-muted-foreground" />
                                    ) : (
                                        <Eye className="h-4 w-4 text-muted-foreground" />
                                    )}
                                </button>
                            </div>
                            <InputError message={bulkDeleteErrors.password?.[0]} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => {
                            setOpenBulkDeleteModal(false);
                            setBulkDeletePassword('');
                            setBulkDeleteErrors({});
                        }}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={deleteSelected}
                            disabled={processing || !bulkDeletePassword}
                        >
                            {t('roles.bulk_delete_btn')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

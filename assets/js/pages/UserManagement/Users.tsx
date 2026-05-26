import { useState, useRef } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';

import { generatePassword } from '@/lib/utils';
import { useTranslation } from '@/lib/i18n';

import { BreadcrumbItem, User, Role, UserCan } from '@/types';

import AppLayout from '@/layouts/app-layout';
import UserManagementLayout from './layout';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import MultiSelect from '@/components/ui/select/Select';
import InputError from '@/components/input-error';
import { Eye, EyeOff, Plus, Lock, KeyRound } from 'lucide-react';
import { DataTable, ColumnFilter, DataTableRef } from '@/components/ui/data-table';

interface UsersPageProps {
    roles: Role[];
}

export default function Users({ roles }: UsersPageProps) {
    const can = usePage().props.userCan as UserCan;
    const t = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('users.breadcrumb'), href: '/user-management/users' },
        { title: t('users.title'), href: '/user-management/users' },
    ];
    
    const [openModal, setOpenModal] = useState(false);
    const [editing, setEditing] = useState(false);
    const [openDeleteModal, setOpenDeleteModal] = useState(false);
    const [openBulkDeleteModal, setOpenBulkDeleteModal] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [bulkDeletePassword, setBulkDeletePassword] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [selectedItems, setSelectedItems] = useState<User[]>([]);
    const [bulkDeleteErrors, setBulkDeleteErrors] = useState<Record<string, string>>({});
    const dataTableRef = useRef<DataTableRef>(null);

    const routePrefix = '/user-management/api/users';
    
    const [formData, setFormData] = useState({
        id: 0,
        name: '',
        email: '',
        password: '',
        roles: [] as number[],
    });
    
    const resetForm = () => {
        setFormData({
            id: 0,
            name: '',
            email: '',
            password: '',
            roles: [],
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

    const editItem = (item: User) => {
        if (!can.update_users) return;
        
        setFormData({
            id: item.id,
            name: item.name,
            email: item.email,
            password: '',
            roles: item.roles.map(role => role.id),
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
            toast.error(t('users.password_required'));
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
                toast.error(t('users.invalid_password'));
            } else {
                toast.error(t('users.delete_error'));
            }
        } finally {
            setProcessing(false);
        }
    };

    const handleGeneratePassword = () => {
        setFormData(prev => ({
            ...prev,
            password: generatePassword()
        }));
        setShowPassword(true);
    };

    const tableColumns = [
        {
            header: t('users.col_name'),
            accessorKey: 'name',
            sortable: true,
            filter: {
                type: 'text' as const,
                placeholder: t('users.filter_name')
            } as ColumnFilter
        },
        {
            header: t('users.col_email'),
            accessorKey: 'email',
            sortable: true,
            filter: {
                type: 'text' as const,
                placeholder: t('users.filter_email')
            } as ColumnFilter
        },
        {
            header: t('users.col_roles'),
            accessorKey: 'roles',
            filter: {
                type: 'select' as const,
                placeholder: t('users.filter_role'),
                options: roles.map(role => ({
                    label: role.name,
                    value: role.id.toString()
                }))
            } as ColumnFilter,
            cell: (item: User) => (
                <div className="flex flex-wrap gap-1">
                    {item.roles.map((role) => (
                        <Badge key={role.id} variant="secondary">
                            {role.name}
                        </Badge>
                    ))}
                </div>
            ),
        },
        {
            header: t('users.col_created'),
            accessorKey: 'created_at',
            sortable: true,
            filter: {
                type: 'date' as const,
                placeholder: t('users.filter_date')
            } as ColumnFilter,
            cell: (item: User) => (
                <div className="text-sm text-muted-foreground">
                    {new Date(item.created_at).toLocaleString()}
                </div>
            ),
        },
        {
            header: t('users.col_updated'),
            accessorKey: 'updated_at',
            sortable: true,
            filter: {
                type: 'date' as const,
                placeholder: t('users.filter_updated')
            } as ColumnFilter,
            cell: (item: User) => (
                <div className="text-sm text-muted-foreground">
                    {new Date(item.updated_at).toLocaleString()}
                </div>
            ),
        },
    ];

    const tableActionButtons = [
        {
            label: t('users.delete_selected'),
            onClick: confirmBulkDelete,
            variant: 'destructive' as const,
            show: can.delete_users && selectedItems.length > 0,
        },
        {
            label: t('users.create'),
            onClick: openNewModal,
            variant: 'default' as const,
            icon: <Plus className="h-4 w-4" />,
            show: can.create_users,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('users.title')} />
            
            <UserManagementLayout can={can}>
                <div className="space-y-4">
                    <DataTable
                        ref={dataTableRef}
                        columns={tableColumns}
                        searchPlaceholder={t('users.search')}
                        searchRoute={routePrefix}
                        actions={tableActionButtons.filter(button => button.show)}
                        selectable={can.delete_users}
                        onSelectionChange={setSelectedItems}
                        selectedItems={selectedItems}
                        pageName="users-table"
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
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle className="flex justify-between items-center">
                            <span>{editing ? t('users.edit') : t('users.create')}</span>
                            {editing && can.delete_users && (
                                <Button variant="destructive" size="sm" onClick={confirmDelete}>
                                    {t('common.delete')}
                                </Button>
                            )}
                        </DialogTitle>
                        <DialogDescription className="sr-only">
                            {editing ? t('users.edit') : t('users.create')}
                        </DialogDescription>
                    </DialogHeader>
                    
                    <form onSubmit={submitForm} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="name">{t('common.name')}</Label>
                            <Input
                                id="name"
                                type="text"
                                value={formData.name}
                                onChange={(e) => setFormData(prev => ({ ...prev, name: e.target.value }))}
                                required
                            />
                            <InputError message={errors.name} />
                        </div>
                        
                        <div className="space-y-2">
                            <Label htmlFor="email">{t('common.email')}</Label>
                            <Input
                                id="email"
                                type="email"
                                value={formData.email}
                                onChange={(e) => setFormData(prev => ({ ...prev, email: e.target.value }))}
                                required
                            />
                            <InputError message={errors.email} />
                        </div>
                        
                        <div className="space-y-2">
                            <Label htmlFor="password">
                                {t('common.password')} {editing && <span className="text-sm text-muted-foreground">{t('common.password_keep')}</span>}
                            </Label>
                            <div className="flex rounded-md">
                                <span className="inline-flex items-center px-3 rounded-l-md border border-r-0 border-input bg-muted text-muted-foreground text-sm">
                                    <Lock className="h-4 w-4" />
                                </span>
                                <Input
                                    id="password"
                                    type={showPassword ? "text" : "password"}
                                    value={formData.password}
                                    onChange={(e) => setFormData(prev => ({ ...prev, password: e.target.value }))}
                                    required={!editing}
                                    className="rounded-none"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="rounded-r-md rounded-l-none border border-l-0 border-input bg-muted text-muted-foreground hover:bg-muted"
                                    onClick={() => setShowPassword(!showPassword)}
                                >
                                        {showPassword ? (
                                        <EyeOff className="h-4 w-4" />
                                    ) : (
                                        <Eye className="h-4 w-4" />
                                    )}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="rounded-r-md border border-input bg-muted text-muted-foreground hover:bg-muted ml-2"
                                    onClick={handleGeneratePassword}
                                >
                                    <KeyRound className="h-4 w-4" />
                                </Button>
                            </div>
                            <InputError message={errors.password} />
                        </div>
                        
                        <div className="space-y-2">
                            <Label htmlFor="roles">{t('users.roles')}</Label>
                            <MultiSelect
                                isMulti
                                value={roles.map(role => ({
                                    value: role.id,
                                    label: role.name
                                })).filter(option => formData.roles.includes(option.value))}
                                onChange={(selectedOptions: any) => {
                                    const selectedRoleIds = selectedOptions ? selectedOptions.map((option: any) => option.value) : [];
                                    setFormData(prev => ({ ...prev, roles: selectedRoleIds }));
                                }}
                                options={roles.map(role => ({
                                    value: role.id,
                                    label: role.name
                                }))}
                                placeholder={t('users.roles_placeholder')}
                            />
                            <InputError message={errors.roles} />
                        </div>
                        
                        <DialogFooter className="pt-4 border-t">
                            <Button type="submit" disabled={processing}>
                                {editing ? t('users.update') : t('users.create')}
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
                        <DialogTitle>{t('users.delete_title')}</DialogTitle>
                        <DialogDescription>
                            {t('users.delete_desc')}
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setOpenDeleteModal(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button variant="destructive" onClick={deleteItem} disabled={processing}>
                            {t('users.delete')}
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
                        <DialogTitle>{t('users.bulk_delete_title', { count: String(selectedItems.length) })}</DialogTitle>
                        <DialogDescription>
                            {t('users.bulk_delete_desc')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        <div className="space-y-2">
                            <Label htmlFor="password">{t('users.bulk_delete_password')}</Label>
                            <div className="relative">
                                <Input
                                    id="password"
                                    type={showPassword ? "text" : "password"}
                                    value={bulkDeletePassword}
                                    onChange={(e) => setBulkDeletePassword(e.target.value)}
                                    placeholder={t('users.password_placeholder')}
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
                            {t('users.delete_selected')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
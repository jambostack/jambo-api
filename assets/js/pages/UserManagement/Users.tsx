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
import { Eye, EyeOff, UserPlus, Trash2, KeyRound, Users2 } from 'lucide-react';
import { DataTable, ColumnFilter, DataTableRef } from '@/components/ui/data-table';

interface UsersPageProps { roles: Role[]; }

export default function Users({ roles }: UsersPageProps) {
  const can = (usePage().props.userCan || {}) as UserCan;
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
  const routePrefix = '/api/users';
  const [formData, setFormData] = useState({ id: 0, name: '', email: '', password: '', roles: [] as number[] });

  const resetForm = () => { setFormData({ id: 0, name: '', email: '', password: '', roles: [] }); setErrors({}); };
  const openNewModal = () => { resetForm(); setEditing(false); setOpenModal(true); };
  const closeModal = () => { setOpenModal(false); setEditing(false); resetForm(); };
  const handleSuccess = () => { dataTableRef.current?.fetchData(); };

  const submitForm = async (e: React.FormEvent) => {
    e.preventDefault(); setProcessing(true); setErrors({});
    try {
      if (editing) { await axios.put(`${routePrefix}/${formData.id}`, formData); toast.success(t('users.update')); }
      else { await axios.post(routePrefix, formData); toast.success(t('users.create')); }
      closeModal(); handleSuccess();
    } catch (error: any) {
      if (error.response?.status === 422) setErrors(error.response.data.errors);
      else toast.error(t('common.error'));
    } finally { setProcessing(false); }
  };

  const editItem = (item: User) => {
    if (!can.manage_users) return;
    setFormData({ id: item.id, name: item.name, email: item.email, password: '', roles: (item.roles || []).map((r: any) => r.id) });
    setEditing(true); setOpenModal(true);
  };

  const deleteItem = async () => {
    setProcessing(true);
    try { await axios.delete(`${routePrefix}/${formData.id}`); toast.success(t('users.delete')); setOpenDeleteModal(false); closeModal(); handleSuccess(); }
    catch (e: any) { toast.error(e?.response?.data?.error || t('common.error')); }
    finally { setProcessing(false); }
  };

  const deleteSelected = async () => {
    if (!bulkDeletePassword) { toast.error(t('users.password_required')); return; }
    setProcessing(true); setBulkDeleteErrors({});
    try {
      const res = await axios.post(`${routePrefix}/bulk-delete`, { ids: selectedItems.map(i => i.id), password: bulkDeletePassword });
      toast.success(res.data.message); setOpenBulkDeleteModal(false); setSelectedItems([]); setBulkDeletePassword(''); handleSuccess();
    } catch (error: any) {
      if (error.response?.status === 422) error.response.data.errors ? setBulkDeleteErrors(error.response.data.errors) : toast.error(error.response.data.error);
      else toast.error(error.response?.status === 403 ? t('users.invalid_password') : t('users.delete_error'));
    } finally { setProcessing(false); }
  };

  const tableColumns = [
    {
      header: t('users.col_name'), accessorKey: 'name', sortable: true,
      filter: { type: 'text' as const, placeholder: t('users.filter_name') },
    },
    {
      header: t('users.col_email'), accessorKey: 'email', sortable: true,
      filter: { type: 'text' as const, placeholder: t('users.filter_email') },
    },
    {
      header: t('users.col_roles'), accessorKey: 'roles',
      cell: (item: any) => (
        <div className="flex flex-wrap gap-1">
          {(item.roles || []).map((r: any) => (
            <Badge key={r.id} variant="secondary" className="text-xs">{r.label || r.name}</Badge>
          ))}
        </div>
      ),
    },
    {
      header: t('users.col_created'), accessorKey: 'created_at', sortable: true,
      filter: { type: 'date' as const, placeholder: t('users.filter_date') },
      cell: (item: any) => (
        <span className="text-sm text-muted-foreground tabular-nums">
          {item.created_at ? new Date(item.created_at).toLocaleDateString() : '-'}
        </span>
      ),
    },
  ];

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={t('users.title')} />
      <UserManagementLayout can={can}>
        <div className="space-y-4">
          <DataTable
            ref={dataTableRef} columns={tableColumns} searchPlaceholder={t('users.search')} searchRoute={routePrefix}
            actions={[
              { label: t('users.delete_selected'), onClick: () => setOpenBulkDeleteModal(true), variant: 'destructive' as const, show: can.manage_users && selectedItems.length > 0 },
              { label: t('users.create'), onClick: openNewModal, variant: 'default' as const, icon: <UserPlus className="h-4 w-4" />, show: can.manage_users },
            ].filter(b => b.show)}
            selectable={Boolean(can.manage_users)} onSelectionChange={setSelectedItems} selectedItems={selectedItems}
            pageName="users-table" onRowClick={editItem}
          />
        </div>
      </UserManagementLayout>

      <Dialog open={openModal} onOpenChange={o => { setOpenModal(o); if (!o) closeModal(); }}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle className="flex items-center justify-between">
              <span className="flex items-center gap-2"><UserPlus className="h-5 w-5" />{editing ? t('users.edit') : t('users.create')}</span>
              {editing && can.manage_users && (<Button variant="destructive" size="sm" onClick={() => setOpenDeleteModal(true)}><Trash2 className="h-4 w-4 mr-1" />{t('common.delete')}</Button>)}
            </DialogTitle>
            <DialogDescription className="sr-only">{editing ? t('users.edit') : t('users.create')}</DialogDescription>
          </DialogHeader>
          <form onSubmit={submitForm} className="space-y-4">
            <div className="space-y-2"><Label htmlFor="uname">{t('common.name')}</Label><Input id="uname" value={formData.name} onChange={e => setFormData(p => ({ ...p, name: e.target.value }))} required /><InputError message={errors.name} /></div>
            <div className="space-y-2"><Label htmlFor="uemail">{t('common.email')}</Label><Input id="uemail" type="email" value={formData.email} onChange={e => setFormData(p => ({ ...p, email: e.target.value }))} required /><InputError message={errors.email} /></div>
            <div className="space-y-2">
              <Label htmlFor="upw">{t('common.password')} {editing && <span className="text-xs text-muted-foreground">({t('common.password_keep')})</span>}</Label>
              <div className="flex gap-1">
                <div className="relative flex-1"><Input id="upw" type={showPassword ? 'text' : 'password'} value={formData.password} onChange={e => setFormData(p => ({ ...p, password: e.target.value }))} required={!editing} className="pr-10" /><button type="button" className="absolute inset-y-0 right-0 flex items-center pr-3" onClick={() => setShowPassword(!showPassword)}>{showPassword ? <EyeOff className="h-4 w-4 text-muted-foreground" /> : <Eye className="h-4 w-4 text-muted-foreground" />}</button></div>
                <Button type="button" variant="outline" size="icon" onClick={() => { setFormData(p => ({ ...p, password: generatePassword() })); setShowPassword(true); }}><KeyRound className="h-4 w-4" /></Button>
              </div>
              <InputError message={errors.password} />
            </div>
            <div className="space-y-2">
              <Label>{t('users.roles')}</Label>
              <MultiSelect isMulti value={(roles || []).filter(r => formData.roles.includes(r.id)).map(r => ({ value: r.id, label: r.label || r.name }))} onChange={(opts: any) => setFormData(p => ({ ...p, roles: opts ? opts.map((o: any) => o.value) : [] }))} options={(roles || []).map(r => ({ value: r.id, label: r.label || r.name }))} placeholder={t('users.roles_placeholder')} />
              <InputError message={errors.roles} />
            </div>
            <DialogFooter className="pt-4 border-t"><Button type="submit" disabled={processing}>{editing ? t('users.update') : t('users.create')}</Button><Button type="button" variant="outline" onClick={closeModal}>{t('common.cancel')}</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={openDeleteModal} onOpenChange={setOpenDeleteModal}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader><DialogTitle>{t('users.delete_title')}</DialogTitle><DialogDescription>{t('users.delete_desc')}</DialogDescription></DialogHeader>
          <DialogFooter><Button variant="outline" onClick={() => setOpenDeleteModal(false)}>{t('common.cancel')}</Button><Button variant="destructive" onClick={deleteItem} disabled={processing}>{t('users.delete')}</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={openBulkDeleteModal} onOpenChange={o => { setOpenBulkDeleteModal(o); if (!o) { setBulkDeletePassword(''); setBulkDeleteErrors({}); } }}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader><DialogTitle className="flex items-center gap-2"><Users2 className="h-5 w-5" />{t('users.bulk_delete_title', { count: String(selectedItems.length) })}</DialogTitle><DialogDescription>{t('users.bulk_delete_desc')}</DialogDescription></DialogHeader>
          <div className="space-y-4 py-2"><div className="space-y-2"><Label htmlFor="bulkpw">{t('users.bulk_delete_password')}</Label><Input id="bulkpw" type={showPassword ? 'text' : 'password'} value={bulkDeletePassword} onChange={e => setBulkDeletePassword(e.target.value)} placeholder={t('users.password_placeholder')} /><InputError message={bulkDeleteErrors.password?.[0]} /></div></div>
          <DialogFooter><Button variant="outline" onClick={() => setOpenBulkDeleteModal(false)}>{t('common.cancel')}</Button><Button variant="destructive" onClick={deleteSelected} disabled={processing || !bulkDeletePassword}>{t('users.delete_selected')}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}

import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState, useRef } from 'react';
import axios from 'axios';
import { toast } from 'sonner';

import type { Project, BreadcrumbItem, ProjectMember, ProjectMemberRole } from '@/types/index.d';
import type { UserCan } from '@/types/index.d';

import AppLayout from '@/layouts/app-layout';
import ProjectSettingsLayout from './layout';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';

interface Props {
    project: Project;
}

interface RoleOption {
    id: number;
    name: string;
    label: string;
}

interface UserSearchResult {
    id: number;
    name: string;
    email: string;
}

function isValidEmail(value: string): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function extractApiError(err: unknown, fallback: string): string {
    if (err && typeof err === 'object' && 'response' in err) {
        const res = (err as { response?: { data?: { error?: string } } }).response;
        if (res?.data?.error) return res.data.error;
    }
    return fallback;
}

export default function UserAccess({ project }: Props) {
    const t = useTranslation();
    const { props } = usePage();
    const userCan = (props.userCan as UserCan) ?? {};
    const canUpdate = userCan['access_user_access_settings'] ?? false;

    const [members, setMembers] = useState<ProjectMember[]>([]);
    const [roles, setRoles] = useState<RoleOption[]>([]);
    const [loading, setLoading] = useState(true);
    const [editingRoleId, setEditingRoleId] = useState<number | null>(null);
    const [pendingRoleId, setPendingRoleId] = useState<number | ''>('');

    // Add member form state
    const [showAddForm, setShowAddForm] = useState(false);
    const [searchInput, setSearchInput] = useState('');
    const [searchResults, setSearchResults] = useState<UserSearchResult[]>([]);
    const [showDropdown, setShowDropdown] = useState(false);
    const [selectedUser, setSelectedUser] = useState<UserSearchResult | null>(null);
    const [inviteEmail, setInviteEmail] = useState<string | null>(null);
    const [addRoleId, setAddRoleId] = useState<number | ''>('');
    const [addLoading, setAddLoading] = useState(false);

    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const abortControllerRef = useRef<AbortController | null>(null);
    const dropdownRef = useRef<HTMLDivElement>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('projects.settings.title'), href: route('projects.settings.project', project.id) },
        { title: t('projects.settings.ua.breadcrumb'), href: route('projects.settings.user-access', project.id) },
    ];

    // Load members and roles on mount
    useEffect(() => {
        const fetchData = async () => {
            try {
                const [membersRes, rolesRes] = await Promise.all([
                    axios.get(`/api/projects/${project.uuid}/settings/members`),
                    axios.get('/api/roles'),
                ]);
                setMembers(membersRes.data.data ?? membersRes.data);
                setRoles(rolesRes.data.data ?? rolesRes.data);
            } catch (err) {
                toast.error(extractApiError(err, t('projects.settings.ua.failed_load')));
            } finally {
                setLoading(false);
            }
        };
        fetchData();
    }, [project.uuid]);

    // Close dropdown on outside click
    useEffect(() => {
        const handleClickOutside = (e: MouseEvent) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
                setShowDropdown(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Cleanup abort controller and pending debounce on unmount
    useEffect(() => {
        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
        };
    }, []);

    // Debounced user search
    const handleSearchInput = (value: string) => {
        setSearchInput(value);
        setSelectedUser(null);
        setInviteEmail(null);

        if (debounceRef.current) clearTimeout(debounceRef.current);

        if (!value) {
            setSearchResults([]);
            setShowDropdown(false);
            return;
        }

        debounceRef.current = setTimeout(async () => {
            try {
                // Cancel previous request if still in flight
                if (abortControllerRef.current) {
                    abortControllerRef.current.abort();
                }
                abortControllerRef.current = new AbortController();

                const res = await axios.get('/api/users', {
                    params: { search: value, per_page: 10 },
                    signal: abortControllerRef.current.signal,
                });
                const results: UserSearchResult[] = res.data.data ?? res.data;
                // Filter out existing members
                const memberUserIds = members
                    .filter((m) => m.user !== null)
                    .map((m) => m.user!.id);
                const filtered = results.filter((u) => !memberUserIds.includes(u.id));
                setSearchResults(filtered);
                setShowDropdown(true);
            } catch (err) {
                if (axios.isCancel(err)) return; // Ignore cancelled requests
                setSearchResults([]);
                setShowDropdown(false);
            }
        }, 300);
    };

    const selectUser = (user: UserSearchResult) => {
        setSelectedUser(user);
        setInviteEmail(null);
        setSearchInput(`${user.name} <${user.email}>`);
        setShowDropdown(false);
    };

    const selectInvite = () => {
        setInviteEmail(searchInput);
        setSelectedUser(null);
        setShowDropdown(false);
    };

    const handleAddMember = async () => {
        if (!addRoleId) {
            toast.error(t('projects.settings.ua.role_required'));
            return;
        }
        if (!selectedUser && !inviteEmail) {
            toast.error(t('projects.settings.ua.user_required'));
            return;
        }
        setAddLoading(true);
        try {
            const payload = selectedUser
                ? { user_id: selectedUser.id, role_id: addRoleId }
                : { email: inviteEmail, role_id: addRoleId };

            const res = await axios.post(`/api/projects/${project.uuid}/members`, payload);
            const newMember: ProjectMember = res.data.data ?? res.data;
            setMembers((prev) => [...prev, newMember]);
            toast.success(selectedUser ? t('projects.settings.ua.member_added') : t('projects.settings.ua.invited'));
            // Reset form
            setShowAddForm(false);
            setSearchInput('');
            setSelectedUser(null);
            setInviteEmail(null);
            setAddRoleId('');
            setSearchResults([]);
        } catch (err) {
            toast.error(extractApiError(err, t('projects.settings.ua.failed_add')));
        } finally {
            setAddLoading(false);
        }
    };

    const handleRoleChange = async (memberId: number, roleId: number) => {
        try {
            const res = await axios.patch(`/api/projects/${project.uuid}/members/${memberId}/role`, { role_id: roleId });
            const updated: ProjectMember = res.data.data ?? res.data;
            setMembers((prev) => prev.map((m) => (m.id === memberId ? updated : m)));
            toast.success(t('projects.settings.ua.role_updated'));
        } catch (err) {
            toast.error(extractApiError(err, t('projects.settings.ua.failed_role')));
        }
    };

    const handleRemoveMember = async (memberId: number, memberName: string) => {
        if (!window.confirm(t('projects.settings.ua.remove_confirm', { name: memberName }))) return;
        try {
            await axios.delete(`/api/projects/${project.uuid}/members/${memberId}`);
            setMembers((prev) => prev.filter((m) => m.id !== memberId));
            toast.success(t('projects.settings.ua.member_removed'));
        } catch (err) {
            toast.error(extractApiError(err, t('projects.settings.ua.failed_remove')));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('projects.settings.ua.title')} />

            <ProjectSettingsLayout project={project}>
                <div className="space-y-8 max-w-2xl">
                    <div className="flex items-center justify-between">
                        <HeadingSmall title={t('projects.settings.ua.heading')} description={t('projects.settings.ua.heading_desc')} />
                        {canUpdate && !showAddForm && (
                            <Button size="sm" onClick={() => setShowAddForm(true)}>
                                {t('projects.settings.ua.add_member')}
                            </Button>
                        )}
                    </div>

                    {/* Add member inline form */}
                    {canUpdate && showAddForm && (
                        <div className="border rounded-md p-4 space-y-3 bg-muted/20">
                            <p className="text-sm font-medium">{t('projects.settings.ua.add_member_form')}</p>

                            {roles.length === 0 && !loading && (
                                <p className="text-xs text-amber-600">
                                    {t('projects.settings.ua.no_roles')}
                                </p>
                            )}

                            {/* User search input + dropdown */}
                            <div className="relative" ref={dropdownRef}>
                                <input
                                    type="text"
                                    className="w-full border rounded-md px-3 py-2 text-sm bg-background focus:outline-none focus:ring-1 focus:ring-ring"
                                    placeholder={t('projects.settings.ua.search_ph')}
                                    value={searchInput}
                                    onChange={(e) => handleSearchInput(e.target.value)}
                                />
                                {showDropdown && (
                                    <div className="absolute z-50 w-full mt-1 border rounded-md bg-popover shadow-md max-h-48 overflow-y-auto">
                                        {searchResults.length > 0 ? (
                                            searchResults.map((user) => (
                                                <button
                                                    key={user.id}
                                                    type="button"
                                                    className="w-full text-left px-3 py-2 text-sm hover:bg-muted"
                                                    onMouseDown={() => selectUser(user)}
                                                >
                                                    {user.name}{' '}
                                                    <span className="text-muted-foreground">&lt;{user.email}&gt;</span>
                                                </button>
                                            ))
                                        ) : null}
                                        {searchResults.length === 0 && isValidEmail(searchInput) && (
                                            <button
                                                type="button"
                                                className="w-full text-left px-3 py-2 text-sm hover:bg-muted text-blue-600"
                                                onMouseDown={selectInvite}
                                            >
                                                {t('projects.settings.ua.invite', { email: searchInput })}
                                            </button>
                                        )}
                                        {searchResults.length === 0 && !isValidEmail(searchInput) && (
                                            <div className="px-3 py-2 text-sm text-muted-foreground">
                                                {t('projects.settings.ua.no_results')}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>

                            {/* Invitation mode indicator */}
                            {inviteEmail && (
                                <p className="text-xs text-blue-600">
                                    {t('projects.settings.ua.invite_mode')} <strong>{inviteEmail}</strong>
                                </p>
                            )}

                            {/* Role select */}
                            <select
                                className="w-full border rounded-md px-3 py-2 text-sm bg-background focus:outline-none focus:ring-1 focus:ring-ring"
                                value={addRoleId}
                                onChange={(e) => setAddRoleId(e.target.value ? Number(e.target.value) : '')}
                            >
                                <option value="">{t('projects.settings.ua.select_role')}</option>
                                {roles.map((role) => (
                                    <option key={role.id} value={role.id}>
                                        {role.label ?? role.name}
                                    </option>
                                ))}
                            </select>

                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    disabled={addLoading || (!selectedUser && !inviteEmail) || !addRoleId}
                                    onClick={handleAddMember}
                                >
                                    {addLoading ? t('projects.settings.ua.adding') : t('projects.settings.ua.add_btn')}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        setShowAddForm(false);
                                        setSearchInput('');
                                        setSelectedUser(null);
                                        setInviteEmail(null);
                                        setAddRoleId('');
                                        setSearchResults([]);
                                    }}
                                >
                                    {t('projects.settings.ua.cancel')}
                                </Button>
                            </div>
                        </div>
                    )}

                    {/* Members table */}
                    <div className="border rounded-md overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-3 py-2 text-left font-medium">{t('projects.settings.ua.col_user')}</th>
                                    <th className="px-3 py-2 text-left font-medium">{t('projects.settings.ua.col_role')}</th>
                                    <th className="px-3 py-2 text-left font-medium">{t('projects.settings.ua.col_status')}</th>
                                    {canUpdate && <th className="px-3 py-2 font-medium">{t('projects.settings.ua.col_actions')}</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {loading && (
                                    <tr>
                                        <td className="px-3 py-4 text-muted-foreground" colSpan={canUpdate ? 4 : 3}>
                                            {t('projects.settings.ua.loading')}
                                        </td>
                                    </tr>
                                )}
                                {!loading && members.length === 0 && (
                                    <tr>
                                        <td className="px-3 py-4 text-muted-foreground" colSpan={canUpdate ? 4 : 3}>
                                            {t('projects.settings.ua.no_members')}
                                        </td>
                                    </tr>
                                )}
                                {!loading &&
                                    members.map((member) => (
                                        <tr key={member.id} className="border-t">
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                <div className="font-medium">{member.user?.name ?? '—'}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {member.user?.email ?? member.email}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {canUpdate ? (
                                                    editingRoleId === member.id ? (
                                                        <div className="flex items-center gap-1">
                                                            <select
                                                                className="border rounded px-2 py-1 text-sm bg-background focus:outline-none focus:ring-1 focus:ring-ring"
                                                                value={pendingRoleId}
                                                                onChange={(e) => setPendingRoleId(e.target.value ? Number(e.target.value) : '')}
                                                            >
                                                                <option value="" disabled>—</option>
                                                                {roles.map((role) => (
                                                                    <option key={role.id} value={role.id}>
                                                                        {role.label ?? role.name}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                            <Button
                                                                size="sm"
                                                                disabled={!pendingRoleId}
                                                                onClick={async () => {
                                                                    await handleRoleChange(member.id, Number(pendingRoleId));
                                                                    setEditingRoleId(null);
                                                                }}
                                                            >
                                                                ✓
                                                            </Button>
                                                            <Button size="sm" variant="ghost" onClick={() => setEditingRoleId(null)}>
                                                                ✕
                                                            </Button>
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-sm hover:underline"
                                                            onClick={() => {
                                                                setEditingRoleId(member.id);
                                                                setPendingRoleId(member.role?.id ?? '');
                                                            }}
                                                        >
                                                            {member.role?.label ?? member.role?.name ?? <span className="text-muted-foreground">—</span>}
                                                        </button>
                                                    )
                                                ) : (
                                                    <span>{member.role?.label ?? member.role?.name ?? '—'}</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {member.status === 'active' ? (
                                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                        {t('projects.settings.ua.status_active')}
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                        {t('projects.settings.ua.status_pending')}
                                                    </span>
                                                )}
                                            </td>
                                            {canUpdate && (
                                                <td className="px-3 py-2 text-center whitespace-nowrap">
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="text-red-600 hover:text-red-700 hover:bg-red-50"
                                                        onClick={() => handleRemoveMember(member.id, member.user?.name ?? member.email)}
                                                    >
                                                        {t('projects.settings.ua.remove')}
                                                    </Button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </ProjectSettingsLayout>
        </AppLayout>
    );
}

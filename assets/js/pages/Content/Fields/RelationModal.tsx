import { useEffect, useState } from 'react';
import axios from 'axios';
import moment from 'moment';
import { useTranslation } from '@/lib/i18n';

import { Collection, Field, ContentEntry, ColumnDef } from '@/types';

import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DataTable } from '@/components/ui/data-table';
import { Badge } from '@/components/ui/badge';
import { FileText } from 'lucide-react';


export default function RelationModal({ isOpen, onClose, field, value, onSelect }: { isOpen: boolean, onClose: () => void, field: Field, value: any, onSelect: (items: ContentEntry | ContentEntry[], fields: Field[]) => void }) {
    const t = useTranslation();
    const [relationCollection, setRelationCollection] = useState<Collection | null>(null);
    const [searchRoute, setSearchRoute] = useState<string | null>(null);
    const [selectedItems, setSelectedItems] = useState<ContentEntry[]>([]);
    
    const isEndUsers = field.options?.targetCollection === 'end_users';

    // Fetch the relation collection with its fields from db
    const getRelationCollection = async () => {
        const projectUuid = field.project_uuid;
        const collSlug = field.options?.relation?.collection_slug;
        if (!projectUuid) return;

        if (isEndUsers) {
            // EndUsers → utiliser l'API admin end-users
            setSearchRoute(`/api/projects/${projectUuid}/end-users?per_page=50`);
            // Fake collection-shaped object avec les colonnes EndUser
            setRelationCollection({ fields: [
                { name: 'email', label: 'Email', type: 'email' },
                { name: 'name', label: 'Name', type: 'text' },
                { name: 'status', label: 'Status', type: 'text' },
                { name: 'created_at', label: 'Created', type: 'datetime' },
            ] } as any);
            return;
        }

        if (!collSlug) return;
        const response = await axios.get(`/api/projects/${projectUuid}/collections/${collSlug}/fields`);
        const statusParam = field.options?.includeDraft ? '' : '&status=published';
        setSearchRoute(`/api/projects/${projectUuid}/collections/${collSlug}/entries?per_page=50${statusParam}`);
        // Wrap fields array into a collection-shaped object expected by generateColumns
        const fields = response.data.data ?? response.data ?? [];
        setRelationCollection({ fields } as any);
    };

    useEffect(() => {
        getRelationCollection();
        if (value && Array.isArray(value)) {
            setSelectedItems(value as ContentEntry[]);
        } else if (value) {
            // single object
            setSelectedItems([value as ContentEntry]);
        }
    }, []);

    // Generate dynamic columns based on collection fields
    const generateColumns = (): ColumnDef[] => {
        // EndUsers → colonnes spécifiques
        if (isEndUsers) {
            return [
                {
                    header: "Status",
                    accessorKey: "status",
                    sortable: true,
                    filter: {
                        type: 'select',
                        options: [
                            { label: 'Active', value: 'active' },
                            { label: 'Banned', value: 'banned' },
                            { label: 'Pending', value: 'pending' },
                        ]
                    },
                    cell: (item: any) => (
                        <Badge variant={item.status === 'active' ? 'default' : 'outline'} className={
                            item.status === 'active'
                                ? 'bg-green-600 hover:bg-green-700'
                                : item.status === 'banned'
                                ? 'bg-red-600 hover:bg-red-700'
                                : 'text-amber-600 border-amber-300'
                        }>
                            {item.status === 'active' ? 'Active' : item.status === 'banned' ? 'Banned' : 'Pending'}
                        </Badge>
                    ),
                },
                {
                    header: "Email",
                    accessorKey: "email",
                    sortable: true,
                    cell: (item: any) => <span>{item.email || '-'}</span>,
                },
                {
                    header: "Name",
                    accessorKey: "name",
                    sortable: true,
                    cell: (item: any) => <span>{item.name || '-'}</span>,
                },
                {
                    header: "Created",
                    accessorKey: "created_at",
                    sortable: true,
                    cell: (item: any) => (
                        <span>{item.created_at ? new Date(item.created_at).toLocaleString() : '-'}</span>
                    ),
                },
            ];
        }
        const columns: ColumnDef[] = [
            {
                header: "Status",
                accessorKey: "status",
                sortable: true,
                filter: {
                    type: 'select',
                    options: [
                        { label: t('content.draft'), value: 'draft' },
                        { label: t('content.published'), value: 'published' },
                    ]
                },
                cell: (item: ContentEntry) => (
                    <Badge variant={item.status === 'published' ? 'default' : 'outline'} className={
                        item.status === 'published'
                            ? 'bg-green-600 hover:bg-green-700'
                            : 'text-amber-600 border-amber-300'
                    }>
                        {item.status === 'published' ? t('content.published') : t('content.draft')}
                    </Badge>
                ),
            },
            {
                header: "Created",
                accessorKey: "created_at",
                sortable: true,
                filter: {
                    type: 'date',
                },
                cell: (item: ContentEntry) => (
                    <div className="flex flex-col">
                        <span>{new Date(item.created_at).toLocaleString()}</span>
                        <span className="text-xs text-muted-foreground">by {item.creator?.name || t('content.form.unknown')}</span>
                    </div>
                ),
            },
            {
                header: "Updated",
                accessorKey: "updated_at",
                sortable: true,
                filter: {
                    type: 'date',
                },
                cell: (item: ContentEntry) => (
                    <div className="flex flex-col">
                        <span>{new Date(item.updated_at).toLocaleString()}</span>
                        <span className="text-xs text-muted-foreground">by {item.updater?.name || t('content.form.unknown')}</span>
                    </div>
                ),
            }
        ];
        
        // Add field columns from loaded fields
        if (relationCollection?.fields && relationCollection?.fields.length > 0) {
            // Filter out fields that shouldn't be displayed (password, json)
            const displayableFields = relationCollection?.fields.filter((field: Field) => 
                field.type !== 'password' && 
                field.type !== 'json' &&
                !field.options?.hideInContentList
            );
            
            // Add columns for each field
            displayableFields.forEach((field: Field) => {
                columns.push({
                    header: field.label,
                    accessorKey: field.name,
                    sortable: true,
                    cell: (item: ContentEntry) => {
                        const value = item[field.name];
                        
                        if (value === null || value === undefined || value === '') {
                            return '-';
                        }
                        
                        switch (field.type) {
                            case 'text':
                                // Truncate long text
                                return typeof value === 'string' && value.length > 30
                                    ? `${value.substring(0, 30)}...`
                                    : value;
                            case 'email':
                            case 'slug':
                                return value;
                            case 'richtext':
                                // Truncate long text
                                return typeof value === 'string' && value.length > 30
                                    ? `${value.substring(0, 30)}...`
                                    : value;
                            case 'longtext':
                                // Truncate long text
                                return typeof value === 'string' && value.length > 30
                                    ? `${value.substring(0, 30)}...`
                                    : value;
                            case 'date':
                                if (!value) return '-';
                                let format = 'YYYY-MM-DD' + (field.options?.includeTime ? ' HH:mm' : '');
                                if (field.options?.mode === 'range') {
                                    return value.split(' - ').map((date: any) => moment.parseZone(date).format(format)).join(' / ');
                                }
                                return moment.parseZone(value).format(format);
                            case 'boolean':
                                return value ? 'Yes' : 'No';
                            case 'enumeration':
                                if (Array.isArray(value)) {
                                    return value.join(', ');
                                }
                                if (typeof value === 'string') {
                                    try {
                                        const parsedValue = JSON.parse(value);
                                        if (Array.isArray(parsedValue)) {
                                            return parsedValue.join(', ');
                                        }
                                    } catch (e) {
                                        // Not valid JSON, return as is
                                    }
                                }
                                return value;
                            case 'number':
                                return value === null ? '-' : Number(value).toString();
                            case 'media':
                                if (!value) return '-';
                                if (Array.isArray(value)) {
                                    return (
                                        <div className="flex flex-wrap gap-1">
                                            {value.map((asset, index) => (
                                                <div key={index} className="w-8 h-8">
                                                    {asset.thumbnail_url ? (
                                                        <img 
                                                            src={asset.thumbnail_url} 
                                                            alt=""
                                                            className="w-full h-full object-cover rounded"
                                                        />
                                                    ) : (
                                                        <div className="w-full h-full flex items-center justify-center bg-muted rounded">
                                                            <FileText className="w-4 h-4" />
                                                        </div>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    );
                                }
                                return '-';
                            default:
                                return value;
                        }
                    }
                });
            });
        }
        
        return columns;
    };

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent className="sm:max-w-6xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle></DialogTitle>
                    <DialogDescription className="sr-only"></DialogDescription>
                </DialogHeader>

                <div className="space-y-4 overflow-x-auto">
                    {relationCollection && (
                        <DataTable
                            columns={generateColumns()}
                            searchRoute={searchRoute ?? ''}
                            pageName={`relation_${relationCollection?.project_id}_${relationCollection?.id}`}
                            onRowClick={(item) => {
                                const itemId = isEndUsers ? item.uuid : item.id;
                                if (field.options?.relation?.type === 1) {
                                    onSelect(item as unknown as ContentEntry, relationCollection?.fields || []);
                                    onClose();
                                } else {
                                    const isSelected = selectedItems.some(selectedItem => {
                                        if (typeof selectedItem === 'object') {
                                            return isEndUsers
                                                ? (selectedItem as any).uuid === item.uuid
                                                : (selectedItem as ContentEntry).id === item.id;
                                        }
                                        return isEndUsers ? selectedItem === item.uuid : selectedItem === item.id;
                                    });

                                    if (isSelected) {
                                        setSelectedItems(selectedItems.filter(selectedItem => {
                                            if (typeof selectedItem === 'object') {
                                                return isEndUsers
                                                    ? (selectedItem as any).uuid !== item.uuid
                                                    : (selectedItem as ContentEntry).id !== item.id;
                                            }
                                            return isEndUsers ? selectedItem !== item.uuid : selectedItem !== item.id;
                                        }));
                                    } else {
                                        setSelectedItems([...selectedItems, item]);
                                    }
                                }
                            }}
                            selectable={true}
                            onSelectionChange={setSelectedItems}
                            selectedItems={selectedItems}
                            actions={
                                field.options?.relation?.type === 2 ? selectedItems.length > 0 ? [
                                    {
                                        label: t('fields.relation.add_selected'),
                                        onClick: () => {
                                            onSelect(selectedItems as unknown as ContentEntry, relationCollection?.fields || []);
                                            onClose();
                                        },
                                    }
                                ] : [] : []
                            }
                        />
                    )}
                    
                </div>
            </DialogContent>
        </Dialog>
    );
}
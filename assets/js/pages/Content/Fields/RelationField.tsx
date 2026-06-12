import { useState, useEffect } from 'react';
import FieldBase, { FieldProps } from './FieldBase';
import { Button } from "@/components/ui/button";
import RelationModal from './RelationModal';
import RelationEntriesTable from '@/components/ui/relation-entries-table';
import { ContentEntry, Field as CollectionField } from '@/types';
import { PlusIcon, TrashIcon } from 'lucide-react';
import axios from 'axios';
import { toast } from 'sonner';
import { useTranslation } from '@/lib/i18n';
import { END_USER_DISPLAY_FIELDS } from './endUserRelation';

export default function RelationField({ field, value, onChange, processing, errors }: FieldProps) {
    const t = useTranslation();
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedEntries, setSelectedEntries] = useState<ContentEntry[]>([]);
    const [displayFields, setDisplayFields] = useState<CollectionField[]>([]);

    const isEndUsers = field.options?.targetCollection === 'end_users';
    const getItemId = (item: any) => isEndUsers ? item.uuid : item.id;
    const getItemIdRaw = (item: any) => isEndUsers ? item.uuid ?? item : item.id ?? item;

    const handleSelectedItems = (
        items: ContentEntry | ContentEntry[] | number | number[],
        fields: CollectionField[]
    ) => {
        if (field.options?.relation?.type === 1) {
            const id = typeof items === 'object' ? getItemId(items) : items;
            onChange(field, id);
            if (typeof items === 'object') {
                setSelectedEntries([items as ContentEntry]);
            }
        } else {
            let ids: (number|string)[] = [];
            if (Array.isArray(items)) {
                ids = items.map((item: any) => getItemIdRaw(item));
                const objs = items.filter((i: any) => typeof i === 'object') as ContentEntry[];
                setSelectedEntries(objs);
            } else {
                ids = [typeof items === 'object' ? getItemId(items) : items as any];
                if (typeof items === 'object') {
                    setSelectedEntries([items as ContentEntry]);
                }
            }
            onChange(field, ids);
        }

        // Save fields for display table (exclude hidden fields)
        const filtered = fields.filter(f => !f.options?.hideInContentList && f.type !== 'password' && f.type !== 'json');
        setDisplayFields(filtered);
    }
    
    // Load existing entries (edit mode)
    useEffect(() => {
        if (!value || selectedEntries.length > 0) return;

        const isEndUsers = field.options?.targetCollection === 'end_users';
        const projectUuid = field.project_uuid;

        // Normalise value to an array of numeric IDs regardless of how it was stored
        const toIds = (v: any): string[] => {
            if (Array.isArray(v)) return v.map(String).filter(s => s.length > 0);
            if (typeof v === 'number') return [String(v)];
            if (typeof v === 'string') {
                try {
                    const parsed = JSON.parse(v);
                    if (Array.isArray(parsed)) return parsed.map(String).filter(s => s.length > 0);
                } catch {}
                return [v];
            }
            return [];
        };

        const ids = toIds(value);
        if (ids.length === 0) return;

        if (isEndUsers) {
            // EndUsers: fetch depuis l'API admin (filtre uuids[] côté serveur)
            const params = new URLSearchParams();
            ids.forEach(id => params.append('uuids[]', id));
            const euUrl = `/api/projects/${projectUuid}/end-users?${params.toString()}`;
            axios.get(euUrl)
                .then(res => {
                    const users = res.data.data ?? res.data ?? [];
                    setSelectedEntries(users);
                    setDisplayFields(END_USER_DISPLAY_FIELDS);
                })
                .catch(() => toast.error(t('fields.relation.load_error')));
            return;
        }

        const collSlug = field.options?.relation?.collection_slug;
        if (!collSlug) return;

        const entriesUrl = `/api/projects/${projectUuid}/collections/${collSlug}/entries`;
        const fieldsUrl  = `/api/projects/${projectUuid}/collections/${collSlug}/fields`;

        axios
            .get(entriesUrl, { params: { ids: ids.join(',') } })
            .then(res => {
                setSelectedEntries(res.data.data ?? res.data);
                return axios.get(fieldsUrl);
            })
            .then(res => {
                const relFields: CollectionField[] = res.data.data ?? [];
                const filtered = relFields.filter(f => !f.options?.hideInContentList && f.type !== 'password' && f.type !== 'json');
                setDisplayFields(filtered);
            })
            .catch(() => toast.error(t('fields.relation.load_error')));
    }, [value]);

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <div className="flex flex-col gap-2">
                <div className="flex gap-2">
                    <Button 
                        type="button" 
                        variant="outline"
                        disabled={processing}
                        onClick={() => setIsModalOpen(true)}
                    >
                        <PlusIcon className="w-4 h-4" />
                        {selectedEntries.length > 0
                            ? (field.options?.relation?.type === 1 ? t('fields.relation.change_single') : t('fields.relation.change_multi'))
                            : (field.options?.relation?.type === 1 ? t('fields.relation.select_single') : t('fields.relation.select_multi'))
                        }
                    </Button>

                    {selectedEntries.length > 0 && (
                        <Button 
                            type="button" 
                            variant="outline" 
                            disabled={processing} 
                            onClick={() => {
                                setSelectedEntries([]);
                                onChange(field, []);
                            }}>
                            <TrashIcon className="w-4 h-4" />
                            {t('fields.relation.clear')}
                        </Button>
                    )}
                </div>
                {selectedEntries.length > 0 && (
                    <RelationEntriesTable
                        fields={displayFields}
                        entries={selectedEntries}
                        showStatus={false}
                        showCreated={false}
                    />
                )}
            </div>

            {isModalOpen && (
                <RelationModal
                    isOpen={isModalOpen}
                    onClose={() => setIsModalOpen(false)}
                    field={field}
                    value={selectedEntries}
                    onSelect={handleSelectedItems}
                />
            )}
        </FieldBase>
    );
}

/* Removed duplicate renderFieldValue – now handled by RelationEntriesTable */ 
import { useState, useEffect } from 'react';
import FieldBase, { FieldProps } from './FieldBase';
import { Button } from "@/components/ui/button";
import RelationModal from './RelationModal';
import RelationEntriesTable from '@/components/ui/relation-entries-table';
import { ContentEntry, Field as CollectionField } from '@/types';
import { PlusIcon, TrashIcon } from 'lucide-react';
import axios from 'axios';
import { useTranslation } from '@/lib/i18n';

export default function RelationField({ field, value, onChange, processing, errors }: FieldProps) {
    const t = useTranslation();
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedEntries, setSelectedEntries] = useState<ContentEntry[]>([]);
    const [displayFields, setDisplayFields] = useState<CollectionField[]>([]);

    const handleSelectedItems = (
        items: ContentEntry | ContentEntry[] | number | number[],
        fields: CollectionField[]
    ) => {
        if (field.options?.relation?.type === 1) {
            const id = typeof items === 'object' ? (items as ContentEntry).id : items as number;
            onChange(field, id);
            if (typeof items === 'object') {
                setSelectedEntries([items as ContentEntry]);
            }
        } else {
            let ids: number[] = [];
            if (Array.isArray(items)) {
                ids = items.map((item: any) => (typeof item === 'object' ? item.id : item));
                // Replace table data with the received objects (filter out primitives)
                const objs = items.filter((i: any) => typeof i === 'object') as ContentEntry[];
                setSelectedEntries(objs);
            } else {
                ids = [typeof items === 'object' ? (items as ContentEntry).id : (items as number)];
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

        // Normalise value to an array of numeric IDs regardless of how it was stored
        const toIds = (v: any): number[] => {
            if (Array.isArray(v)) return v.map(Number).filter(n => !isNaN(n));
            if (typeof v === 'number') return [v];
            if (typeof v === 'string') {
                try {
                    const parsed = JSON.parse(v);
                    if (Array.isArray(parsed)) return parsed.map(Number).filter(n => !isNaN(n));
                } catch {}
                const n = Number(v);
                return isNaN(n) ? [] : [n];
            }
            return [];
        };

        const ids = toIds(value);
        if (ids.length === 0) return;

        const collSlug = field.options?.relation?.collection_slug;
        if (!collSlug) return;

        const projectUuid = field.project_uuid;
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
            .catch(() => {});
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
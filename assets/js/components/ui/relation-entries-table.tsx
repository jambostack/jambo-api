import React from 'react';
import moment from 'moment';
import { Badge } from '@/components/ui/badge';
import { FileText } from 'lucide-react';

import type { Field, ContentEntry } from '@/types';

interface RelationEntriesTableProps {
    /** Field definitions to display (already filtered for visibility) */
    fields: Field[];
    /** Entries to render */
    entries: ContentEntry[];
    /** Show the status column */
    showStatus?: boolean;
    /** Show the created at column */
    showCreated?: boolean;
}

/**
 * Lightweight read-only table for rendering related content entries.
 *
 * The component mirrors the display logic of ContentList / DataTable so we
 * don't repeat rendering code in multiple places (ContentList relation dialog
 * and RelationField preview table).
 */
export default function RelationEntriesTable({
    fields,
    entries,
    showStatus = true,
    showCreated = true,
}: RelationEntriesTableProps) {
    /** Render a single field value similar to ContentList logic */
    const renderFieldValue = (field: Field, value: any) => {
        if (value === null || value === undefined || value === '') return '-';

        // Handle repeatable simple values by showing first value + counter
        if (field.options?.repeatable && Array.isArray(value)) {
            if (value.length === 0) return '-';
            const first = value[0];
            if (typeof first !== 'object') {
                return `${first}${value.length > 1 ? ` (+${value.length - 1} more)` : ''}`;
            }
        }

        switch (field.type) {
            case 'text':
            case 'longtext':
                return typeof value === 'string' && value.length > 30
                    ? `${value.substring(0, 30)}...`
                    : value;
            case 'email':
            case 'slug':
                return value;
            case 'richtext': {
                // Strip HTML tags, then truncate
                const plain = typeof value === 'string'
                    ? value.replace(/<[^>]*>/g, '')
                    : String(value);
                return plain.length > 30 ? `${plain.substring(0, 30)}...` : plain;
            }
            case 'date': {
                const format = 'YYYY-MM-DD' + (field.options?.includeTime ? ' HH:mm' : '');
                if (field.options?.mode === 'range' && typeof value === 'string') {
                    return value.split(' - ').map((d: any) => moment.parseZone(d).format(format)).join(' / ');
                }
                return moment.parseZone(value).format(format);
            }
            case 'boolean':
                return value ? 'Yes' : 'No';
            case 'enumeration':
                if (Array.isArray(value)) return value.join(', ');
                if (typeof value === 'string') {
                    try {
                        const parsed = JSON.parse(value);
                        if (Array.isArray(parsed)) return parsed.join(', ');
                    } catch {/* ignore */}
                }
                return value;
            case 'number':
                return value === null ? '-' : Number(value).toString();
            case 'media':
                if (!value) return '-';
                if (Array.isArray(value)) {
                    return (
                        <div className="flex flex-wrap gap-1">
                            {value.map((asset: any, index: number) => (
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
            case 'relation':
                if (Array.isArray(value)) return `${value.length} relation${value.length === 1 ? '' : 's'}`;
                return value ? '1 relation' : '-';
            default:
                return value;
        }
    };

    return (
        <div className="overflow-x-auto w-full">
            <table className="min-w-full text-sm">
                <thead className="bg-muted/50">
                    <tr>
                        {showStatus && <th className="px-4 py-2 text-left whitespace-nowrap">Status</th>}
                        {showCreated && <th className="px-4 py-2 text-left whitespace-nowrap">Created</th>}
                        {fields.map((f) => (
                            <th key={f.id} className="px-4 py-2 text-left whitespace-nowrap">
                                {f.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {entries.map((ent) => (
                        <tr key={ent.id} className="border-t hover:bg-muted/20">
                            {showStatus && (
                                <td className="px-4 py-2 whitespace-nowrap">
                                    <Badge
                                        variant={ent.status === 'published' ? 'default' : ent.status === 'trashed' ? 'destructive' : 'outline'}
                                        className={
                                            ent.status === 'published'
                                                ? 'bg-green-600 hover:bg-green-700'
                                                : ent.status === 'trashed'
                                                ? 'bg-red-600 hover:bg-red-700'
                                                : 'text-amber-600 border-amber-300'
                                        }
                                    >
                                        {ent.status === 'published' ? 'Published' : ent.status === 'trashed' ? 'Trashed' : 'Draft'}
                                    </Badge>
                                </td>
                            )}
                            {showCreated && (
                                <td className="px-4 py-2 whitespace-nowrap">
                                    {new Date(ent.created_at).toLocaleString()}
                                </td>
                            )}
                            {fields.map((f) => (
                                <td key={f.id} className="px-4 py-2 whitespace-nowrap">
                                    {renderFieldValue(f, (ent as any)[f.name])}
                                </td>
                            ))}
                        </tr>
                    ))}
                    {entries.length === 0 && (
                        <tr>
                            <td
                                className="px-4 py-6 text-muted-foreground text-center"
                                colSpan={fields.length + (showStatus ? 1 : 0) + (showCreated ? 1 : 0)}
                            >
                                No related entries
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
} 
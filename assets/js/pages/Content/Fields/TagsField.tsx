import { useState } from 'react';
import FieldBase, { FieldProps } from './FieldBase';
import { Input } from "@/components/ui/input";
import { X } from 'lucide-react';

export default function TagsField({ field, value, onChange, processing, errors }: FieldProps) {
    const [draft, setDraft] = useState('');
    const tags: string[] = Array.isArray(value) ? value : [];

    const addTag = () => {
        const t = draft.trim();
        if (t && !tags.includes(t)) {
            onChange(field, [...tags, t]);
        }
        setDraft('');
    };

    const removeTag = (index: number) => {
        const next = [...tags];
        next.splice(index, 1);
        onChange(field, next);
    };

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <div className="space-y-2">
                <div className="flex flex-wrap gap-1">
                    {tags.map((tag, index) => (
                        <span key={index} className="inline-flex items-center gap-1 rounded-full bg-secondary px-2.5 py-0.5 text-xs font-medium">
                            {tag}
                            <button type="button" onClick={() => removeTag(index)} disabled={processing} className="hover:text-destructive">
                                <X className="h-3 w-3" />
                            </button>
                        </span>
                    ))}
                </div>
                <Input
                    id={field.slug}
                    type="text"
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ',') {
                            e.preventDefault();
                            addTag();
                        }
                    }}
                    onBlur={addTag}
                    disabled={processing}
                    placeholder={field.placeholder || 'Ajouter puis Entrée'}
                />
            </div>
        </FieldBase>
    );
}

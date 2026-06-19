import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useState, useCallback } from 'react';
import { useAutocomplete } from './useAutocomplete';

interface SchemaFormProps {
    schema: Record<string, any>;
    values: Record<string, any>;
    onChange: (values: Record<string, any>) => void;
}

export default function SchemaForm({ schema, values, onChange }: SchemaFormProps) {
    const suggestions = useAutocomplete();
    const properties = schema?.properties ?? {};
    const required: string[] = schema?.required ?? [];

    const updateField = (key: string, value: any) => {
        onChange({ ...values, [key]: value });
    };

    const [autocompleteIndex, setAutocompleteIndex] = useState<{ field: string; pos: number } | null>(null);

    const handleKeyDown = useCallback(
        (e: React.KeyboardEvent, field: string) => {
            const target = e.target as HTMLInputElement;
            const cursorPos = target.selectionStart ?? 0;
            const textBeforeCursor = target.value.substring(0, cursorPos);

            if (e.key === '{') {
                const prev = textBeforeCursor.slice(-1);
                if (prev === '{') {
                    setAutocompleteIndex({ field, pos: cursorPos });
                }
            }
            if (e.key === 'Escape') setAutocompleteIndex(null);
            if (e.key === 'Enter' && autocompleteIndex) {
                e.preventDefault();
                setAutocompleteIndex(null);
            }
        },
        [autocompleteIndex],
    );

    return (
        <div className="space-y-4">
            {Object.entries(properties).map(([key, prop]: [string, any]) => {
                const isTemplate = prop.template === true;
                const value = values[key] ?? prop.default ?? '';

                return (
                    <div key={key}>
                        <div className="flex items-center justify-between mb-1">
                            <Label className="text-xs">
                                {prop.title ?? key}
                                {required.includes(key) && (
                                    <span className="text-destructive ml-0.5">*</span>
                                )}
                            </Label>
                            {isTemplate && (
                                <span className="text-[10px] bg-muted px-1.5 py-0.5 rounded text-muted-foreground">
                                    {'{{ }}'}
                                </span>
                            )}
                        </div>

                        {prop.enum ? (
                            <Select
                                value={String(value)}
                                onValueChange={(v) => updateField(key, v)}
                            >
                                <SelectTrigger className="h-8 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {prop.enum.map((opt: string) => (
                                        <SelectItem key={opt} value={opt} className="text-xs">
                                            {opt}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        ) : prop.type === 'boolean' ? (
                            <Switch
                                checked={!!value}
                                onCheckedChange={(v) => updateField(key, v)}
                            />
                        ) : prop.format === 'textarea' ||
                          prop.format === 'richtext' ||
                          prop.format === 'json' ? (
                            <Textarea
                                className="text-xs min-h-[80px] font-mono"
                                value={
                                    typeof value === 'object'
                                        ? JSON.stringify(value, null, 2)
                                        : String(value)
                                }
                                onChange={(e) => {
                                    try {
                                        if (prop.format === 'json')
                                            updateField(
                                                key,
                                                JSON.parse(e.target.value),
                                            );
                                        else updateField(key, e.target.value);
                                    } catch {
                                        updateField(key, e.target.value);
                                    }
                                }}
                                onKeyDown={(e) => handleKeyDown(e, key)}
                            />
                        ) : prop.type === 'number' ? (
                            <Input
                                type="number"
                                className="h-8 text-xs"
                                value={value}
                                onChange={(e) =>
                                    updateField(
                                        key,
                                        parseFloat(e.target.value) || 0,
                                    )
                                }
                            />
                        ) : (
                            <Input
                                className="h-8 text-xs"
                                value={String(value)}
                                onChange={(e) =>
                                    updateField(key, e.target.value)
                                }
                                onKeyDown={(e) => handleKeyDown(e, key)}
                                placeholder={prop.description ?? ''}
                            />
                        )}

                        {prop.description && (
                            <p className="text-[10px] text-muted-foreground mt-0.5">
                                {prop.description}
                            </p>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

import FieldBase from './FieldBase';

import { FieldRendererProps } from './index';

import { Button } from '@/components/ui/button';
import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';

export default function ColorField({ field, value, onChange, processing, errors }: FieldRendererProps) {
    const addRepeatableField = () => {
        if (!Array.isArray(value)) {
            onChange(field, [{ value: null }]);
            return;
        }
        const newValue = [...value, { value: null }];
        onChange(field, newValue);
    };

    const removeRepeatableField = (index: number) => {
        if (!Array.isArray(value)) return;
        const newValue = [...value];
        newValue.splice(index, 1);
        onChange(field, newValue);
    };

    if (field.options?.repeatable) {
        const values = Array.isArray(value) ? value : [{ value: null }];
        
        return (
            <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
                <div className="space-y-2">
                    {values.map((item: { value: string }, index: number) => (
                        <div key={index} className="space-y-1">
                            <div className="flex gap-2">
                                <div className="flex items-center gap-2 flex-1">
                                    <input
                                        type="color"
                                        value={item.value || '#000000'}
                                        onChange={(e) => {
                                            const updatedValues = [...values];
                                            updatedValues[index] = { value: e.target.value };
                                            onChange(field, updatedValues);
                                        }}
                                        disabled={processing}
                                        className="h-10 w-20 rounded border border-input bg-background px-3 py-2"
                                    />
                                    <input
                                        type="text"
                                        value={item.value || ''}
                                        onChange={(e) => {
                                            const updatedValues = [...values];
                                            updatedValues[index] = { value: e.target.value };
                                            onChange(field, updatedValues);
                                        }}
                                        disabled={processing}
                                        placeholder="#000000"
                                        className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                    />
                                </div>
                                {index !== 0 && (
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        size="icon"
                                        onClick={() => removeRepeatableField(index)}
                                        disabled={processing}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                )}
                            </div>
                            <InputError message={errors[`fields.${field.slug}.${index}.value`]} /> 
                        </div>
                    ))}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={addRepeatableField}
                        disabled={processing}
                        className="text-xs"
                    >
                        <Plus className="h-4 w-4 mr-1" />
                        Add a new color
                    </Button>
                </div>
            </FieldBase>
        );
    }

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <div className="flex items-center gap-2">
                <input
                    type="color"
                    value={value || '#000000'}
                    onChange={(e) => onChange(field, e.target.value)}
                    disabled={processing}
                    className="h-10 w-20 rounded border border-input bg-background px-3 py-2"
                />
                <input
                    type="text"
                    value={value || ''}
                    onChange={(e) => onChange(field, e.target.value)}
                    disabled={processing}
                    placeholder="#000000"
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                />
            </div>
        </FieldBase>
    );
} 
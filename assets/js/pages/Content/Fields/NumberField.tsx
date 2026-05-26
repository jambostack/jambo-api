import FieldBase, { FieldProps } from './FieldBase';

import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Plus, Trash2, Hash } from 'lucide-react';
import InputError from '@/components/input-error';

const formatNumber = (value: string | number | null): string => {
    if (value === null || value === '') return '';
    const num = Number(value);
    if (isNaN(num)) return '';
    return num.toString();
};

export default function NumberField({ field, value, onChange, processing, errors }: FieldProps) {
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
                            <div className="flex">
                                <span className="inline-flex items-center px-3 rounded-l-md border border-r-0 border-input bg-muted text-muted-foreground text-sm">
                                    <Hash className="h-4 w-4" />
                                </span>
                                <Input
                                    id={`${field.slug}-${index}`}
                                    type="number"
                                    step="any"
                                    required={field.required}
                                    value={formatNumber(item.value)}
                                    onChange={(e) => {
                                        const updatedValues = [...values];
                                        updatedValues[index] = { value: e.target.value };
                                        onChange(field, updatedValues);
                                    }}
                                    disabled={processing}
                                    placeholder={field.placeholder}
                                    className="rounded-l-none"
                                />
                                {index !== 0 && (
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        size="icon"
                                        onClick={() => removeRepeatableField(index)}
                                        disabled={processing}
                                        className="ml-2"
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
                        Add a new line
                    </Button>
                </div>
            </FieldBase>
        );
    }

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <div className="flex rounded-md">
                <span className="inline-flex items-center px-3 rounded-l-md border border-r-0 border-input bg-muted text-muted-foreground text-sm">
                    <Hash className="h-4 w-4" />
                </span>
                <Input
                    id={field.slug}
                    type="number"
                    step="any"
                    required={field.required}
                    value={formatNumber(value)}
                    onChange={(e) => onChange(field, e.target.value)}
                    disabled={processing}
                    placeholder={field.placeholder}
                    className="rounded-l-none"
                />
            </div>
        </FieldBase>
    );
} 
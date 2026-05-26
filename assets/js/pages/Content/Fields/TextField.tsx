import FieldBase, { FieldProps } from './FieldBase';

import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';

export default function TextField({ field, value, onChange, processing, errors }: FieldProps) {
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
                                <Input
                                    id={`${field.slug}-${index}`}
                                    type="text"
                                    required={field.required}
                                    value={item.value || ''}
                                    onChange={(e) => {
                                        const updatedValues = [...values];
                                        updatedValues[index] = { value: e.target.value };
                                        onChange(field, updatedValues);
                                    }}
                                    disabled={processing}
                                    placeholder={field.placeholder}
                                    className="flex-1"
                                />
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
                        Add a new line
                    </Button>
                </div>
            </FieldBase>
        );
    }

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <Input
                id={field.slug}
                type="text"
                required={field.required}
                value={value || ''}
                onChange={(e) => onChange(field, e.target.value)}
                disabled={processing}
                placeholder={field.placeholder}
            />
        </FieldBase>
    );
} 
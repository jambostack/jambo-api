import { useRef } from 'react';
import FieldBase from './FieldBase';
import type { FieldProps } from './FieldBase';

import { Button } from '@/components/ui/button';
import { Plus, Trash2, Clock } from 'lucide-react';
import InputError from '@/components/input-error';

export default function TimeField({ field, value, onChange, processing, errors }: FieldProps) {
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

    const handleTimeChange = (time: string | null, index?: number) => {
        if (field.options?.repeatable && typeof index === 'number') {
            const newValue = [...(value as Array<{ value: string | null }>)];
            newValue[index] = { value: time };
            onChange(field, newValue);
        } else {
            onChange(field, time);
        }
    };

    const TimeInputWithIcon = ({ value, onChange, disabled }: { 
        value: string | null, 
        onChange: (value: string) => void, 
        disabled?: boolean 
    }) => {
        const inputRef = useRef<HTMLInputElement>(null);

        const handleIconClick = () => {
            if (inputRef.current && !disabled) {
                inputRef.current.focus();
                inputRef.current.showPicker();
            }
        };

        return (
            <div className="relative inline-block w-auto">
                <div 
                    className="absolute inset-y-0 right-0 flex items-center pr-3 cursor-pointer"
                    onClick={handleIconClick}
                >
                    <Clock className="h-4 w-4 text-foreground opacity-70 hover:opacity-100" />
                </div>
                <input
                    ref={inputRef}
                    type="time"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value)}
                    disabled={disabled}
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 text-foreground pr-10 [&::-webkit-calendar-picker-indicator]:opacity-0 [&::-webkit-calendar-picker-indicator]:absolute [&::-webkit-calendar-picker-indicator]:inset-0 [&::-webkit-calendar-picker-indicator]:appearance-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-clear-button]:appearance-none"
                />
            </div>
        );
    };

    if (field.options?.repeatable) {
        const values = Array.isArray(value) ? value : [{ value: null }];
        
        return (
            <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
                <div className="space-y-2">
                    {values.map((item: { value: string | null }, index: number) => (
                        <div key={index} className="space-y-1">
                            <div className="flex gap-2 items-center">
                                <TimeInputWithIcon
                                    value={item.value}
                                    onChange={(time) => handleTimeChange(time, index)}
                                    disabled={processing}
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
            <div className="flex gap-2 items-center space-y-1">
                <TimeInputWithIcon
                    value={value as string | null}
                    onChange={(time) => handleTimeChange(time)}
                    disabled={processing}
                />
            </div>
        </FieldBase>
    );
} 
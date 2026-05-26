import FieldBase from './FieldBase';
import type { FieldProps } from './FieldBase';
import { format, parse, isValid } from 'date-fns';
import { useState, useEffect } from 'react';

import { DatePicker } from '@/components/ui/date-picker';
import { Button } from '@/components/ui/button';
import { Plus, Trash2 } from 'lucide-react';
import { DateRange } from 'react-day-picker';
import InputError from '@/components/input-error';
import { useTranslation } from '@/lib/i18n';

const parseDateStr = (str: string | null | undefined): Date | undefined => {
    if (!str) return undefined;
    // Try ISO first, then common formats
    const formats = ['yyyy-MM-dd HH:mm:ss', 'yyyy-MM-dd', "yyyy-MM-dd'T'HH:mm:ssxxx"];
    for (const fmt of formats) {
        const d = parse(str.trim(), fmt, new Date());
        if (isValid(d)) return d;
    }
    // Fallback: native Date parse
    const d = new Date(str);
    return isValid(d) ? d : undefined;
};

const buildRangeDrafts = (value: any, repeatable: boolean): { start?: Date; end?: Date }[] => {
    const vals = repeatable ? (Array.isArray(value) ? value : [{ value: null }]) : [{ value } as any];
    return vals.map((v: any) => {
        const valStr = v?.value as string | null;
        if (valStr && valStr.includes(' - ')) {
            const [s, e] = valStr.split(' - ');
            return { start: parseDateStr(s), end: parseDateStr(e) };
        }
        return {};
    });
};

export default function DateField({ field, value, onChange, processing, errors }: FieldProps) {
    const t = useTranslation();
    const isRange = field.options?.mode === 'range';
    const [rangeDrafts, setRangeDrafts] = useState<{ start?: Date; end?: Date }[]>(() =>
        isRange ? buildRangeDrafts(value, !!field.options?.repeatable) : []
    );

    // Keep rangeDrafts in sync when the value changes from outside (edit mode load)
    useEffect(() => {
        if (isRange) {
            setRangeDrafts(buildRangeDrafts(value, !!field.options?.repeatable));
        }
    }, [value]);

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
        onChange(field, newValue.map(item => ({ value: item.value })));
    };

    const handleDateChange = (date: Date | DateRange | undefined, index?: number) => {
        if (!date) {
            if (field.options?.repeatable && typeof index === 'number') {
                const newValue = Array.isArray(value) ? [...value] : [{ value: null }];
                newValue[index] = { value: null };
                onChange(field, newValue);
            } else {
                onChange(field, null);
            }
            return;
        }

        let formattedDate: string | null = null;

        if ('from' in date) {
            // Handle date range
            if (date.from) {
                // For date ranges, always use datetime format to ensure consistency
                const formatString = 'yyyy-MM-dd HH:mm:ss';
                const fromDate = format(date.from, formatString);
                const toDate = date.to ? format(date.to, formatString) : null;
                // Only format and update if we have both from and to dates
                if (date.to) {
                    formattedDate = `${fromDate} - ${toDate}`;
                }
            }
        } else {
            // Handle single date
            if (field.options?.includeTime) {
                // Use datetime format if time is included
                formattedDate = format(date, 'yyyy-MM-dd HH:mm:ss');
            } else {
                // Use date-only format if time is not included
                formattedDate = format(date, 'yyyy-MM-dd');
            }
        }

        if (field.options?.repeatable && typeof index === 'number') {
            const newValue = Array.isArray(value) ? [...value] : [{ value: null }];
            // Only update if we have a complete date range or a single date
            if (formattedDate) {
                newValue[index] = { value: formattedDate };
                onChange(field, newValue);
            }
        } else {
            onChange(field, formattedDate);
        }
    };

    const handleRangePartChange = (part: 'start' | 'end', date: Date | undefined, index?: number) => {
        if (!date) return;

        // Save draft
        if (typeof index === 'number') {
            const drafts = [...rangeDrafts];
            drafts[index] = { ...drafts[index], [part]: date };
            setRangeDrafts(drafts);

            const { start, end } = drafts[index];
            if (!(start && end)) return; // wait for both selections

            const fmt = field.options?.includeTime ? 'yyyy-MM-dd HH:mm:ss' : 'yyyy-MM-dd';
            const combined = `${format(start, fmt)} - ${format(end, fmt)}`;

            if (field.options?.repeatable) {
                const newValue = Array.isArray(value) ? [...value] : [{ value: null }];
                newValue[index] = { value: combined };
                onChange(field, newValue);
            } else {
                onChange(field, combined);
            }
        }
    };

    const parseDate = (dateString: string | null, part?: 'start' | 'end'): Date | undefined | DateRange => {
        if (!dateString) return undefined;

        if (dateString.includes(' - ')) {
            const [fromStr, toStr] = dateString.split(' - ');
            if (part === 'start') return parseDateStr(fromStr);
            if (part === 'end') return parseDateStr(toStr);
            return { from: parseDateStr(fromStr), to: parseDateStr(toStr) };
        }

        return parseDateStr(dateString);
    };

    if (field.options?.repeatable) {
        const values = Array.isArray(value) ? value : [{ value: null }];
        
        return (
            <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
                <div className="space-y-2 w-full flex-1">
                    {values.map((item: { value: string | null }, index: number) => (
                        <div key={index} className="space-y-1">
                            <div className="flex gap-2 w-full items-start">
                                {field.options?.mode === 'range' ? (
                                    <div className="grid grid-cols-2 gap-2 w-full">
                                        <DatePicker
                                            key={`${index}-start`}
                                            date={parseDate(item.value, 'start') as Date}
                                            onSelect={(date) => handleRangePartChange('start', date as Date, index)}
                                            disabled={processing}
                                            placeholder={t('fields.date_start_ph')}
                                            includeTime={field.options?.includeTime}
                                            className="w-full"
                                        />
                                        <DatePicker
                                            key={`${index}-end`}
                                            date={parseDate(item.value, 'end') as Date}
                                            onSelect={(date) => handleRangePartChange('end', date as Date, index)}
                                            disabled={processing}
                                            placeholder={t('fields.date_end_ph')}
                                            includeTime={field.options?.includeTime}
                                            className="w-full"
                                        />
                                    </div>
                                ) : (
                                    <div className="flex-1">
                                    <DatePicker
                                        key={index}
                                        date={parseDate(item.value) as Date}
                                        onSelect={(date) => handleDateChange(date, index)}
                                        disabled={processing}
                                        placeholder={field.placeholder || t('fields.date_select_ph')}
                                        includeTime={field.options?.includeTime}
                                        className="w-full"
                                        />
                                    </div>
                                )}
                                {index !== 0 ? (
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        size="icon"
                                        onClick={() => removeRepeatableField(index)}
                                        disabled={processing}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                ) : (
                                    <div className="w-9" />
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
            {field.options?.mode === 'range' ? (
                <div className="grid grid-cols-2 gap-2 w-full">
                    <DatePicker
                        date={parseDate(value as string | null, 'start') as Date}
                        onSelect={(d) => handleRangePartChange('start', d as Date, 0)}
                        disabled={processing}
                        placeholder={t('fields.date_start_ph')}
                        includeTime={field.options?.includeTime}
                        className="w-full"
                    />
                    <DatePicker
                        date={parseDate(value as string | null, 'end') as Date}
                        onSelect={(d) => handleRangePartChange('end', d as Date, 0)}
                        disabled={processing}
                        placeholder={t('fields.date_end_ph')}
                        includeTime={field.options?.includeTime}
                        className="w-full"
                    />
                </div>
            ) : (
                <DatePicker
                    date={parseDate(value as string | null)}
                    onSelect={(date) => handleDateChange(date)}
                    disabled={processing}
                    placeholder={field.placeholder || t('fields.date_select_ph')}
                    includeTime={field.options?.includeTime}
                    className="w-full"
                />
            )}
        </FieldBase>
    );
}
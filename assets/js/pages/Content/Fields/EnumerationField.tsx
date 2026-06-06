import FieldBase, { FieldProps } from './FieldBase';
import MultiSelect from "@/components/ui/select/Select";
import { useTranslation } from '@/lib/i18n';

export default function EnumerationField({ field, value, onChange, processing, errors }: FieldProps) {
    const t = useTranslation();
    // Get options for the select component
    // Supports both storage formats:
    //   Format A (API/public-API): { values: ["FR", "BE"] }
    //   Format B (admin UI):       { enumeration: { list: ["FR", "BE"] } }
    const getOptions = () => {
        try {
            // Format A: options.values (canonical, used by API-created fields)
            if (Array.isArray(field.options?.values)) {
                return field.options.values.map((value: string) => ({
                    value,
                    label: value
                }));
            }
            // Format B: options.enumeration.list (legacy/admin UI format)
            if (Array.isArray(field.options?.enumeration?.list)) {
                return field.options.enumeration.list.map((value: string) => ({
                    value,
                    label: value
                }));
            }
            return [];
        } catch (error) {
            return [];
        }
    };

    // Format the value for the select component
    const formatValue = () => {
        // Handle null/undefined/empty values
        if (!value) {
            return field.options?.multiple ? [] : null;
        }

        // Handle multiple select
        if (field.options?.multiple) {
            // If value is already an array
            if (Array.isArray(value)) {
                const result = value.map(v => ({
                    value: String(v),
                    label: String(v)
                }));
                return result;
            }
            
            // If value is a string, try to parse it as JSON
            if (typeof value === 'string') {
                try {
                    const parsedValue = JSON.parse(value);
                    if (Array.isArray(parsedValue)) {
                        return parsedValue.map(v => ({
                            value: String(v),
                            label: String(v)
                        }));
                    }
                } catch (e) {
                    // If parsing fails, treat it as a comma-separated string
                    return value.split(',').map(v => ({
                        value: v.trim(),
                        label: v.trim()
                    }));
                }
            }
            return [];
        }

        // Handle single select
        if (Array.isArray(value)) {
            // If it's an array but we're in single select mode, take the first value
            if (value.length > 0) {
                const result = {
                    value: String(value[0]),
                    label: String(value[0])
                };
                return result;
            }
            return null;
        }
        
        if (typeof value === 'string') {
            try {
                const parsedValue = JSON.parse(value);
                if (Array.isArray(parsedValue) && parsedValue.length > 0) {
                    return {
                        value: String(parsedValue[0]),
                        label: String(parsedValue[0])
                    };
                }
            } catch (e) {
                // If parsing fails, use the string as is
                return {
                    value: value,
                    label: value
                };
            }
        }

        return null;
    };

    const handleChange = (newValue: any) => {
        if (field.options?.multiple) {
            const values = Array.isArray(newValue) 
                ? newValue.map((option: any) => option.value)
                : [];
            onChange(field, values);
        } else {
            const selectedValue = newValue ? newValue.value : '';
            onChange(field, selectedValue);
        }
    };

    // Precompute options once per render
    const options = getOptions();

    // Updated formatting relying on existing option references
    const formattedValue = (() => {
        if (!value) {
            return field.options?.multiple ? [] : null;
        }

        // Multiple select handling
        if (field.options?.multiple) {
            const valuesArray = Array.isArray(value) ? value : (() => {
                if (typeof value === 'string') {
                    try {
                        const parsed = JSON.parse(value);
                        return Array.isArray(parsed) ? parsed : value.split(',');
                    } catch {
                        return value.split(',');
                    }
                }
                return [];
            })();

            return valuesArray
                .map((v: string) => options.find(o => o.value === String(v)))
                .filter(Boolean);
        }

        // Single select handling
        const singleVal = Array.isArray(value) ? value[0] : (typeof value === 'string' ? (() => {
            try {
                const parsed = JSON.parse(value);
                return Array.isArray(parsed) ? parsed[0] : value;
            } catch {
                return value;
            }
        })() : value);

        return options.find(o => o.value === String(singleVal)) || null;
    })();

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <MultiSelect
                isMulti={!!field.options?.multiple}
                value={formattedValue}
                onChange={handleChange}
                isDisabled={processing}
                placeholder={field.placeholder || t('fields.select_ph')}
                options={options}
                isClearable={!field.required}
            />
        </FieldBase>
    );
} 
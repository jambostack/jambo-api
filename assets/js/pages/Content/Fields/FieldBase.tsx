import React from 'react';
import { Field } from "@/types";

import { Label } from "@/components/ui/label";
import InputError from '@/components/input-error';

export interface FieldProps {
    field: Field;
    value: any;
    onChange: (field: Field, value: any, index?: number) => void;
    processing: boolean;
    errors: Record<string, string>;
    project?: any;
}

export default function FieldBase({ field, children, errors }: React.PropsWithChildren<FieldProps>) {
    const getFieldError = (index?: number) => {
        if (!errors) return undefined;
        if (field.options?.repeatable && typeof index === 'number') {
            return errors[`fields.${field.slug}.${index}.value`];
        }
        return errors[`fields.${field.slug}`];
    };

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between mb-2">
                <Label htmlFor={field.slug}>
                    <span className="font-medium text-md">{field.label}</span>
                    {field.required && <span className="text-red-500 ml-1">*</span>}
                </Label>
                <span className="text-xs text-gray-600 dark:text-gray-300 ml-1">
                    #<span className="select-all">{field.slug}</span>
                </span>
            </div>
            {children}
            {!field.options?.repeatable && (
                <InputError message={getFieldError()} />
            )}
            {field.description && (
                <p className="text-sm text-gray-500">{field.description}</p>
            )}
        </div>
    );
} 
import React, { useEffect } from 'react';

import { generatePassword } from '@/lib/utils';

import FieldBase, { FieldProps } from './FieldBase';

import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Eye, EyeOff, Lock, KeyRound } from 'lucide-react';

export default function PasswordField({ field, value, onChange, processing, errors }: FieldProps) {
    const [visibility, setVisibility] = React.useState<boolean>(false);
    const [localValue, setLocalValue] = React.useState<string>(value ?? '');

    // Sync with incoming value when the parent loads initial data (edit mode).
    // Only overwrite if value changed AND the local field is still untouched (empty).
    useEffect(() => {
        if (value != null && localValue === '') {
            setLocalValue(value);
        }
    }, [value]); // eslint-disable-line react-hooks/exhaustive-deps

    const toggleVisibility = () => {
        setVisibility(prev => !prev);
    };

    const handleGeneratePassword = () => {
        const password = generatePassword();
        setLocalValue(password);
        onChange(field, password);
        setVisibility(true);
    };

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        setLocalValue(e.target.value);
        // Send null when cleared so the backend skips overwriting an existing password
        onChange(field, e.target.value || null);
    };

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <div className="flex rounded-md">
                <span className="inline-flex items-center px-3 rounded-l-md border border-r-0 border-input bg-muted text-muted-foreground text-sm">
                    <Lock className="h-4 w-4" />
                </span>
                <Input
                    id={field.slug}
                    type={visibility ? 'text' : 'password'}
                    required={field.required}
                    value={localValue}
                    onChange={handleChange}
                    disabled={processing}
                    placeholder={field.placeholder}
                    className="rounded-none"
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="rounded-r-md rounded-l-none border border-l-0 border-input bg-muted text-muted-foreground hover:bg-muted"
                    onClick={toggleVisibility}
                >
                    {visibility ? (
                        <EyeOff className="h-4 w-4" />
                    ) : (
                        <Eye className="h-4 w-4" />
                    )}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="rounded-r-md border border-input bg-muted text-muted-foreground hover:bg-muted ml-2"
                    onClick={handleGeneratePassword}
                >
                    <KeyRound className="h-4 w-4" />
                </Button>
            </div>
        </FieldBase>
    );
} 
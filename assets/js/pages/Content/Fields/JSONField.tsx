import FieldBase, { FieldProps } from './FieldBase';
import Editor from 'react-simple-code-editor';
import { highlight, languages } from 'prismjs';
import 'prismjs/components/prism-json';
import 'prismjs/themes/prism.css';

import { Button } from '@/components/ui/button';
import { Code, Check, AlertCircle } from 'lucide-react';

export default function JSONField({ field, value, onChange, processing, errors }: FieldProps) {
    // Function to validate JSON format
    const isValidJSON = (json: string): boolean => {
        try {
            if (json === '') return true;
            JSON.parse(json);
            return true;
        } catch (e) {
            return false;
        }
    };

    // Function to initialize with an empty object
    const initializeEmptyObject = () => {
        onChange(field, '{\n  \n}');
    };

    // Convert value to string if it's an object
    const stringValue = typeof value === 'object' && value !== null 
        ? JSON.stringify(value, null, 2)
        : value || '';

    const isValid = isValidJSON(stringValue);
    const isEmpty = !stringValue || stringValue === '';

    return (
        <FieldBase field={field} value={stringValue} onChange={onChange} processing={processing} errors={errors}>
            <div className="space-y-2">
                <div 
                    className={`border rounded-md overflow-hidden focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 
                    ${!isValid && !isEmpty ? 'border-red-500' : 'border-input'}`}
                >
                    <div className="flex items-center justify-between bg-muted py-1 px-3 border-b">
                        <div className="flex items-center">
                            <Code className="h-4 w-4 mr-2 text-muted-foreground" />
                            <span className="text-xs font-medium text-muted-foreground">JSON Editor</span>
                        </div>
                        <div className="flex items-center">
                            {isValid && !isEmpty && (
                                <span className="flex items-center text-xs text-green-500 mr-2">
                                    <Check className="h-3 w-3 mr-1" /> Valid JSON
                                </span>
                            )}
                            {!isValid && !isEmpty && (
                                <span className="flex items-center text-xs text-red-500 mr-2">
                                    <AlertCircle className="h-3 w-3 mr-1" /> Invalid JSON
                                </span>
                            )}
                        </div>
                    </div>
                    <Editor
                        value={stringValue}
                        onValueChange={(code) => onChange(field, code)}
                        highlight={(code) => {
                            // Ensure code is a string and handle any potential non-string values
                            const codeStr = typeof code === 'string' ? code : String(code || '');
                            return highlight(codeStr, languages.json, 'json');
                        }}
                        padding={12}
                        disabled={processing}
                        placeholder={field.placeholder || '{\n  "key": "value"\n}'}
                        style={{
                            fontFamily: '"Fira code", "Fira Mono", monospace, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New"',
                            fontSize: 14,
                            minHeight: '200px',
                        }}
                        className="min-h-[200px]"
                    />
                </div>
                <div className="flex gap-2">
                    {isEmpty && (
                        <Button
                            type="button"
                            onClick={initializeEmptyObject}
                            variant="outline"
                            size="sm"
                            disabled={processing}
                            className="text-xs"
                        >
                            Initialize Empty Object
                        </Button>
                    )}
                </div>
            </div>
        </FieldBase>
    );
} 
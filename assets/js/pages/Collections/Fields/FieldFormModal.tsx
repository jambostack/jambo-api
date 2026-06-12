import React from 'react';
import { useState, useEffect } from 'react';
import axios from 'axios';
import { cn, slugify } from '@/lib/utils';
import { toast } from 'sonner';
import { useTranslation } from '@/lib/i18n';

import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import MultiSelect from '@/components/ui/select/Select';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { ScrollArea } from '@/components/ui/scroll-area';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { TextCursor, AlignLeft, Link, AtSign, Lock, Hash, ListOrdered, CheckSquare, Droplet, Calendar, Clock, Image, GitBranch, Code } from 'lucide-react';

import fieldTypes from '@/lib/fields.json';
import InputError from '@/components/input-error';

export interface FieldFormModalProps {
    isOpen: boolean;
    onClose: () => void;
    fieldType: string;
    collectionId: number;
    projectId: number;
    projectUuid: string;
    collectionSlug: string;
    /** Surcharge le chemin d'API par défaut (pour EndUsers, etc.) */
    apiBasePath?: string;
    onFieldSaved?: () => void;
    collections: Array<{
        id: number;
        name: string;
    }>;
    collectionFields: Array<{
        id: number;
        name: string;
        label: string;
        type: string;
        options?: {
            repeatable?: boolean;
        };
    }>;
    editField?: {
        id: number;
        slug: string;
        type: string;
        label: string;
        name: string;
        description: string;
        placeholder: string;
        validations: Validations;
        options: FieldFormData['options'];
    };
    can: {
        create_field?: boolean;
        update_field?: boolean;
        delete_field?: boolean;
    };
}

export type ValidationRule = {
    status: boolean;
    message?: string;
    type?: string;
    min?: number | null;
    max?: number | null;
}

export type Validations = {
    required: ValidationRule;
    unique: ValidationRule;
    charcount: ValidationRule;
}

export type FieldFormData = {
    type: string;
    label: string;
    name: string;
    description: string;
    placeholder: string;
    validations: Validations;
    options: {
        repeatable: boolean;
        hideInContentList: boolean;
        hiddenInAPI: boolean;
        editor?: {
            type: number;
        };
        enumeration?: {
            list: string[];
        };
        multiple?: boolean;
        relation?: {
            collection: number | null;
            type: number;
        };
        slug?: {
            field: string | null;
            readonly: boolean;
        };
        // Date field options
        includeTime?: boolean;
        mode?: "single" | "range";
        media?: {
            type: number;
        };
        includeDraft?: boolean;
    };
}

type FieldInfo = {
    label: string;
    icon: string;
    bg: string;
}

type Fields = {
    [key: string]: FieldInfo;
}

export default function FieldFormModal({ isOpen, onClose, fieldType, collectionId, projectId, projectUuid, collectionSlug, apiBasePath, onFieldSaved, collections, collectionFields, editField, can }: FieldFormModalProps) {
    const t = useTranslation();
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [data, setDataState] = useState<FieldFormData>({
        type: fieldType,
        label: '',
        name: '',
        description: '',
        placeholder: '',
        validations: {
            required: {
                status: false,
                message: ''
            },
            unique: {
                status: false,
                message: ''
            },
            charcount: {
                status: false,
                message: '',
                type: 'Between',
                min: null,
                max: null
            }
        },
        options: {
            repeatable: false,
            hideInContentList: false,
            hiddenInAPI: false,
            editor: {
                type: 1
            },
            enumeration: {
                list: []
            },
            multiple: false,
            relation: {
                collection: null,
                type: 1
            },
            slug: {
                field: null,
                readonly: false
            },
            // Date field options
            includeTime: false,
            mode: 'single',
            media: {
                type: 1
            },
            includeDraft: false
        }
    });

    const setData = (
        keyOrObjOrFn: string | Partial<FieldFormData> | ((prev: FieldFormData) => FieldFormData),
        value?: any
    ) => {
        if (typeof keyOrObjOrFn === 'function') {
            setDataState(keyOrObjOrFn);
        } else if (typeof keyOrObjOrFn === 'object') {
            setDataState(prev => ({ ...prev, ...keyOrObjOrFn }));
        } else {
            setDataState(prev => ({ ...prev, [keyOrObjOrFn]: value }));
        }
    };

    const reset = () => setDataState(prev => ({ ...prev, label: '', name: '', description: '', placeholder: '' }));

    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);

    useEffect(() => {
        if (editField) {
            // Normalise les options : convertit le format API {values:[...]} vers
            // le format interne {enumeration:{list:[...]}} pour que l'UI affiche
            // les valeurs existantes.
            const normalizedOptions = { ...editField.options };
            if (Array.isArray(normalizedOptions.values) && !normalizedOptions.enumeration) {
                normalizedOptions.enumeration = { list: normalizedOptions.values };
            }
            // Normalise le format relation DB targetCollection (string slug)
            // vers le format interne relation.collection (numeric id).
            // id=-1 correspond à end_users (entité système, pas une Collection entity).
            // Préserve relation.type existant (ne pas réinitialiser One-to-Many → One-to-One).
            if (normalizedOptions.targetCollection && !normalizedOptions.relation?.collection) {
                const collectionId = normalizedOptions.targetCollection === 'end_users' ? -1 : null;
                normalizedOptions.relation = {
                    ...(normalizedOptions.relation ?? {}),
                    collection: collectionId,
                    type: normalizedOptions.relation?.type ?? 1,
                };
            }
            // Rétrocompatibilité : si relation.collection est un slug string
            // (données antérieures au correctif de serializeField), convertir en id.
            if (typeof normalizedOptions.relation?.collection === 'string') {
                const slug = normalizedOptions.relation.collection;
                if (slug === 'end_users') {
                    normalizedOptions.relation = { ...normalizedOptions.relation, collection: -1 };
                } else {
                    // Chercher l'ID de la collection correspondant au slug
                    const matched = collections?.find((c: any) => c.slug === slug);
                    if (matched) {
                        normalizedOptions.relation = { ...normalizedOptions.relation, collection: matched.id };
                    }
                }
            }
            setData({
                type: editField.type,
                label: editField.label,
                name: editField.name,
                description: editField.description,
                placeholder: editField.placeholder,
                validations: editField.validations,
                options: normalizedOptions
            });
        }
    }, [editField]);

    const [enumerationItem, setEnumerationItem] = useState('');
    const [enumerationValid, setEnumerationValid] = useState(true);

    const addToEnumList = () => {
        if (enumerationItem) {
            setData('options', {
                ...data.options,
                enumeration: {
                    ...data.options.enumeration,
                    list: [...(data.options.enumeration?.list || []), enumerationItem]
                }
            });
            setEnumerationItem('');
            setEnumerationValid(true);
        } else {
            setEnumerationValid(false);
        }
    };

    const removeEnumItem = (index: number) => {
        const newList = [...(data.options.enumeration?.list || [])];
        newList.splice(index, 1);
        setData('options', {
            ...data.options,
            enumeration: {
                ...data.options.enumeration,
                list: newList
            }
        });
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        // Construit les options à envoyer. Convertit relation.collection=-1
        // (end_users virtuel) ou relation.collection='end_users' (slug rétrocompatibilité)
        // vers le format canonique targetCollection.
        const submitData = { ...data, options: { ...data.options, relation: { ...data.options.relation } } };
        if (submitData.options?.relation?.collection === -1 || submitData.options?.relation?.collection === 'end_users') {
            submitData.options = {
                ...submitData.options,
                targetCollection: 'end_users',
            };
            // Nettoie relation.collection pour ne pas polluer le stockage
            if (submitData.options.relation) {
                delete submitData.options.relation.collection;
            }
        }

        const base = apiBasePath ?? `/api/projects/${projectUuid}/collections/${collectionSlug}/fields`;

        try {
            if (editField) {
                await axios.patch(`${base}/${editField.slug}`, submitData);
                toast.success(t('fields.save_success'));
            } else {
                await axios.post(base, submitData);
                toast.success(t('fields.save_success'));
            }
            reset();
            onClose();
            onFieldSaved?.();
        } catch (err: any) {
            const errs = err.response?.data?.errors ?? {};
            setErrors(errs);
            toast.error(t('fields.save_error'));
        } finally {
            setProcessing(false);
        }
    };

    const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
        const { name, value } = e.target;
        
        if (name === 'label') {
            // Generate slug from label
            const slug = slugify(value);
            
            setData(data => ({
                ...data,
                label: value,
                name: slug,
            }));
        } else {
            setData(data => ({
                ...data,
                [name]: value
            }));
        }
    };

    const handleValidationChange = (rule: keyof Validations, field: string, value: unknown) => {
        setData(data => ({
            ...data,
            validations: {
                ...data.validations,
                [rule]: {
                    ...data.validations[rule],
                    [field]: value
                }
            }
        }));
    };

    const fieldInfo = (fieldTypes as Fields)[fieldType];

    const showValidations = fieldType !== 'boolean';

    const handleDelete = async () => {
        if (!editField) return;
        setProcessing(true);
        try {
            const base = apiBasePath ?? `/api/projects/${projectUuid}/collections/${collectionSlug}/fields`;
            await axios.delete(`${base}/${editField.slug}`);
            toast.success(t('fields.delete_success'));
            reset();
            onClose();
            onFieldSaved?.();
        } catch {
            toast.error(t('fields.delete_error'));
        } finally {
            setProcessing(false);
            setIsDeleteDialogOpen(false);
        }
    };

    return (
        <>
            <Dialog open={isOpen} onOpenChange={onClose}>
                <DialogContent className="sm:max-w-3xl">
                    <DialogHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center space-x-3 ml-1">
                                <div className={cn('rounded-md p-2', fieldInfo.bg)}>
                                    {React.createElement(
                                        {
                                            TextCursor,
                                            TextAlignLeft: AlignLeft,
                                            Link,
                                            AtSign,
                                            Lock,
                                            SortNumericUp: Hash,
                                            ListOrdered,
                                            CheckSquare,
                                            Tint: Droplet,
                                            Calendar,
                                            CalendarCheck: Clock,
                                            PhotoVideo: Image,
                                            ExchangeAlt: GitBranch,
                                            Code
                                        }[fieldInfo.icon] || TextCursor,
                                        { className: 'text-white' }
                                    )}
                                </div>
                                <DialogTitle>{editField ? t('fields.form_title_edit', { type: fieldInfo.label }) : t('fields.form_title_create', { type: fieldInfo.label })}</DialogTitle>
                            </div>
                            {editField && can.delete_field && (
                                <Button
                                    variant="destructive"
                                    onClick={() => setIsDeleteDialogOpen(true)}
                                    className='mr-4'
                                >
                                    {t('fields.delete_btn')}
                                </Button>
                            )}
                        </div>
                        <DialogDescription className='sr-only'>
                            {editField ? t('fields.form_title_edit', { type: fieldInfo.label }) : t('fields.form_title_create', { type: fieldInfo.label })}
                        </DialogDescription>
                    </DialogHeader>
                    <ScrollArea className="h-[80vh] pr-4">
                    <form onSubmit={handleSubmit} className="space-y-4 overflow-y-auto p-1">
                        <div className="space-y-2">
                            <Label htmlFor="label">Label</Label>
                            <Input
                                id="label"
                                name="label"
                                value={data.label}
                                onChange={handleChange}
                                placeholder="Enter field label"
                                required
                            />
                            <InputError message={errors.label} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="name">Field Name</Label>
                            <Input
                                id="name"
                                name="name"
                                value={data.name}
                                onChange={handleChange}
                                placeholder="Enter field name"
                                required
                            />
                            <p className="text-sm text-muted-foreground">
                                This will be used as the field identifier in the API. It is automatically generated from the label.
                            </p>
                            <InputError message={errors.name} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="description">Description</Label>
                            <Textarea
                                id="description"
                                name="description"
                                value={data.description}
                                onChange={handleChange}
                                placeholder="Enter field description"
                                rows={3}
                            />
                            <InputError message={errors.description} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="placeholder">Placeholder</Label>
                            <Input
                                id="placeholder"
                                name="placeholder"
                                value={data.placeholder}
                                onChange={handleChange}
                                placeholder="Enter placeholder text"
                            />
                            <InputError message={errors.placeholder} />
                        </div>

                        

                        {fieldType === 'slug' && (
                            <div className="space-y-4 border-t pt-4">
                                <h2 className="font-bold">SLUG OPTIONS</h2>
                                
                                <div className="space-y-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="attachSlug">Attach slug to a field (optional)</Label>

                                        <MultiSelect
                                            isClearable
                                            value={data.options.slug?.field ? {
                                                value: data.options.slug.field,
                                                label: data.options.slug.field,
                                            } : null}
                                            onChange={(value: any) => {
                                                setData(data => ({
                                                    ...data,
                                                    options: {
                                                        ...data.options,
                                                        slug: {
                                                            field: value?.value || null,
                                                            readonly: data.options.slug?.readonly ?? false
                                                        }
                                                    }
                                                }))
                                            }}
                                            options={collectionFields?.filter(field => field.type === 'text' && !field.options?.repeatable).map(field => ({
                                                value: field.name,
                                                label: field.label
                                            }))}
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            Slug will be generated from the selected field. Only <strong>text fields</strong> can be selected
                                        </p>
                                    </div>

                                    {data.options.slug?.field && (
                                        <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400 mt-4">
                                            <Checkbox
                                                id="readonly"
                                                checked={data.options.slug?.readonly ?? false}
                                                onCheckedChange={(checked) => 
                                                    setData(data => ({
                                                        ...data,
                                                        options: {
                                                            ...data.options,
                                                            slug: {
                                                                field: data.options.slug?.field ?? null,
                                                                readonly: checked as boolean
                                                            }
                                                        }
                                                    }))
                                                }
                                                className="mt-1"
                                            />
                                            <div className="space-y-1">
                                                <Label htmlFor="readonly" className="font-medium">Read Only</Label>
                                                <p className="text-sm text-muted-foreground">
                                                    Prevents editing slug field
                                                </p>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* {fieldType === 'richtext' && (
                            <div className="space-y-4 border-t pt-4">
                                <h2 className="font-bold">EDITOR OPTIONS</h2>
                                
                                <RadioGroup
                                    value={data.options.editor?.type.toString()}
                                    onValueChange={(value) => 
                                        setData('options', {
                                            ...data.options,
                                            editor: {
                                                ...data.options.editor,
                                                type: parseInt(value)
                                            }
                                        })
                                    }
                                    className="flex space-x-2"
                                >
                                    <div className="flex items-center space-x-2 p-4 rounded-md flex-1 border border-dashed border-gray-600 dark:border-gray-400">
                                        <RadioGroupItem value="1" id="richtext_type_1"  />
                                        <Label htmlFor="richtext_type_1" className="font-medium">TinyMCE Editor</Label>
                                    </div>
                                </RadioGroup>
                            </div>
                        )} */}

                        {fieldType === 'enumeration' && (
                            <div className="space-y-4 border-t pt-4">
                                <h2 className="font-bold">ENUMERATION OPTIONS</h2>
                                
                                <div className="space-y-2">
                                    <div className="space-y-2">
                                        <Label>List of Values</Label>
                                        <div className="flex space-x-2">
                                            <Input
                                                value={enumerationItem}
                                                onChange={(e) => setEnumerationItem(e.target.value)}
                                                placeholder="Enter value"
                                            />
                                            <Button type="button" onClick={addToEnumList}>+ Add</Button>
                                        </div>
                                        {!enumerationValid && (
                                            <p className="text-sm text-red-500">! Enter a value</p>
                                        )}
                                    </div>

                                    <div className="flex flex-wrap gap-2">
                                        {data.options.enumeration?.list.map((item, index) => (
                                            <div
                                                key={index}
                                                className="bg-blue-200 dark:bg-blue-700 dark:text-gray-300 rounded-md px-3 py-1 text-sm  items-center gap-2 cursor-pointer hover:bg-blue-100 dark:hover:bg-blue-600"
                                                onClick={() => removeEnumItem(index)}
                                            >
                                                {item}
                                                <i className="fas fa-times-circle" />
                                            </div>
                                        ))}
                                    </div>

                                    <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                                        <Checkbox
                                            id="multiple"
                                            checked={data.options.multiple}
                                            onCheckedChange={(checked) => 
                                                setData('options', {
                                                    ...data.options,
                                                    multiple: checked as boolean
                                                })
                                            }
                                            className="mt-1"
                                        />
                                        <div className="space-y-1">
                                            <Label htmlFor="multiple" className="font-medium">Allow multiple</Label>
                                            <p className="text-sm text-muted-foreground">
                                                Allows multiple values to be selected
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {fieldType === 'date' && (
                            <div className="space-y-4 border-t pt-4">
                                <h2 className="font-bold">DATE OPTIONS</h2>
                                
                                <div className="space-y-2">
                                    <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                                        <Checkbox
                                            id="includeTime"
                                            checked={data.options.includeTime}
                                            onCheckedChange={(checked) => 
                                                setData('options', {
                                                    ...data.options,
                                                    includeTime: checked as boolean
                                                })
                                            }
                                            className="mt-1"
                                        />
                                        <div className="space-y-1">
                                            <Label htmlFor="includeTime" className="font-medium">Include time picker</Label>
                                            <p className="text-sm text-muted-foreground">
                                                Allows time to be selected along with date
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                                        <Checkbox
                                            id="range"
                                            checked={data.options.mode === "range"}
                                            onCheckedChange={(checked) => 
                                                setData('options', {
                                                    ...data.options,
                                                    mode: checked ? "range" : "single"
                                                })
                                            }
                                            className="mt-1"
                                        />
                                        <div className="space-y-1">
                                            <Label htmlFor="range" className="font-medium">Allow range select</Label>
                                            <p className="text-sm text-muted-foreground">
                                                Allows selecting a range of dates
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {fieldType === 'media' && (
                            <div className="space-y-4 border-t pt-4">
                                <h2 className="font-bold">MEDIA OPTIONS</h2>
                                
                                <RadioGroup
                                    value={data.options.media?.type.toString()}
                                    onValueChange={(value) => 
                                        setData('options', {
                                            ...data.options,
                                            media: {
                                                ...data.options.media,
                                                type: parseInt(value)
                                            }
                                        })
                                    }
                                    className="flex space-x-2"
                                >
                                    <div className="flex items-start space-x-2 p-4 rounded-md flex-1 border border-dashed border-gray-600 dark:border-gray-400">
                                        <RadioGroupItem value="1" id="media_single" />
                                        <Label htmlFor="media_single" className="font-medium">Single File</Label>
                                    </div>

                                    <div className="flex items-start space-x-2 p-4 rounded-md flex-1 border border-dashed border-gray-600 dark:border-gray-400">
                                        <RadioGroupItem value="2" id="media_multi" />
                                        <Label htmlFor="media_multi" className="font-medium">Multiple Files</Label>
                                    </div>
                                </RadioGroup>
                            </div>
                        )}

                        {fieldType === 'relation' && (
                            <div className="space-y-4 border-t pt-4">
                                <h2 className="font-bold">RELATION OPTIONS</h2>
                                
                                <div className="space-y-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="relationCollection">Relation Collection</Label>
                                        <MultiSelect
                                            value={data.options.relation?.collection ? { value: data.options.relation.collection, label: (data.options.relation.collection === -1 || data.options.relation.collection === 'end_users') ? 'End Users (system)' : (collections.find(c => c.id === data.options.relation!.collection)?.name ?? '') } : null}
                                            onChange={(selected) => {
                                                const val = (selected as any)?.value ?? null;
                                                setData('options', {
                                                    ...data.options,
                                                    relation: {
                                                        collection: val,
                                                        type: data.options.relation?.type ?? 1,
                                                    },
                                                });
                                            }}
                                            options={collections.map(c => ({ value: c.id, label: c.name }))}
                                            placeholder="Select a collection"
                                            isClearable
                                            classNamePrefix="react-select"
                                        />
                                    </div>

                                    <RadioGroup
                                        value={data.options.relation?.type.toString()}
                                        onValueChange={(value) => 
                                            setData('options', {
                                                ...data.options,
                                                relation: {
                                                    collection: data.options.relation?.collection ?? null,
                                                    type: parseInt(value)
                                                }
                                            })
                                        }
                                        className="flex space-x-2 mt-4"
                                    >
                                        <div className="flex items-center space-x-2 p-4 rounded-md flex-1 border border-dashed border-gray-600 dark:border-gray-400">
                                            <RadioGroupItem value="1" id="relation_one_to_one" />
                                            <Label htmlFor="relation_one_to_one" className="font-medium">One to One</Label>
                                        </div>

                                        <div className="flex items-center space-x-2 p-4 rounded-md flex-1 border border-dashed border-gray-600 dark:border-gray-400">
                                            <RadioGroupItem value="2" id="relation_one_to_many" />
                                            <Label htmlFor="relation_one_to_many" className="font-medium">One to Many</Label>
                                        </div>
                                    </RadioGroup>
                                    
                                    <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                                        <Checkbox
                                            id="includeDraft"
                                            checked={data.options.includeDraft ?? false}
                                            onCheckedChange={(checked) => 
                                                setData('options', {
                                                    ...data.options,
                                                    includeDraft: checked as boolean
                                                })
                                            }
                                            className="mt-1"
                                        />
                                        <div className="space-y-1">
                                            <Label htmlFor="includeDraft" className="font-medium">Include Draft</Label>
                                            <p className="text-sm text-muted-foreground">
                                                Allows draft content to be included in the relation
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {showValidations && (
                            <div className="space-y-4 border-t pt-4">
                                <h2 className="font-bold">VALIDATIONS</h2>
                                
                                <div className="space-y-2">
                                    <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                                        <Checkbox
                                            id="required"
                                            checked={data.validations.required.status}
                                            onCheckedChange={(checked) => 
                                                handleValidationChange('required', 'status', checked)
                                            }
                                            className="mt-1"
                                        />
                                        <div className="space-y-1 w-full">
                                            <Label htmlFor="required" className="font-medium">Required</Label>
                                            <p className="text-sm text-muted-foreground">
                                                Prevents saving content if this field is empty
                                            </p>
                                            {data.validations.required.status && (
                                                <Input
                                                    placeholder="Custom error message"
                                                    value={data.validations.required.message || ''}
                                                    onChange={(e) => handleValidationChange('required', 'message', e.target.value)}
                                                    className="mt-2"
                                                />
                                            )}
                                        </div>
                                    </div>

                                    {fieldType !== 'richtext' && 
                                     fieldType !== 'password' && 
                                     fieldType !== 'enumeration' && 
                                     fieldType !== 'boolean' && 
                                     fieldType !== 'color' && 
                                     fieldType !== 'date' && 
                                     fieldType !== 'time' && 
                                     fieldType !== 'media' && 
                                     fieldType !== 'relation' && 
                                     fieldType !== 'json' && (
                                        <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                                            <Checkbox
                                                id="unique"
                                                checked={data.validations.unique.status}
                                                onCheckedChange={(checked) => 
                                                    handleValidationChange('unique', 'status', checked)
                                                }
                                                disabled={data.options.repeatable}
                                                className="mt-1"
                                            />
                                            <div className="space-y-1 w-full">
                                                <Label htmlFor="unique" className="font-medium">Unique</Label>
                                                <p className="text-sm text-muted-foreground">
                                                    {data.options.repeatable 
                                                        ? "Unique validation cannot be used with repeatable fields"
                                                        : "Prevents saving content if this field value is already used"}
                                                </p>
                                                {data.validations.unique.status && !data.options.repeatable && (
                                                    <Input
                                                        placeholder="Custom error message"
                                                        value={data.validations.unique.message || ''}
                                                        onChange={(e) => handleValidationChange('unique', 'message', e.target.value)}
                                                        className="mt-2"
                                                    />
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {fieldType !== 'richtext' && 
                                     fieldType !== 'enumeration' && 
                                     fieldType !== 'boolean' && 
                                     fieldType !== 'color' && 
                                     fieldType !== 'date' && 
                                     fieldType !== 'time' && 
                                     fieldType !== 'media' && 
                                     fieldType !== 'relation' && 
                                     fieldType !== 'json' && (
                                        <div className="space-y-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                                            <div className="flex items-start space-x-2">
                                                <Checkbox
                                                    id="charcount"
                                                    checked={data.validations.charcount.status}
                                                    onCheckedChange={(checked) => 
                                                        handleValidationChange('charcount', 'status', checked)
                                                    }
                                                    className="mt-1"
                                                />
                                                <div className="space-y-1">
                                                    <Label htmlFor="charcount" className="font-medium">
                                                        {fieldType === 'number' ? 'Integer limitations' : 'Character count'}
                                                    </Label>
                                                    <p className="text-sm text-muted-foreground">
                                                        {fieldType === 'number' 
                                                            ? 'Specifies a minimum and/or maximum allowed numbers'
                                                            : 'Specifies a minimum and/or maximum allowed number of characters'}
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            {data.validations.charcount.status && (
                                                <div className="ml-6 space-y-2">
                                                    <div className="flex space-x-2">
                                                        <MultiSelect
                                                            className="w-[180px]"
                                                            value={{ value: data.validations.charcount.type, label: data.validations.charcount.type }}
                                                            // eslint-disable-next-line @typescript-eslint/no-explicit-any
                                                            onChange={(option: any) => handleValidationChange('charcount', 'type', option?.value)}
                                                            options={[
                                                                { value: 'Between', label: 'Between' },
                                                                { value: 'Min', label: 'Min' },
                                                                { value: 'Max', label: 'Max' },
                                                            ]}
                                                            isClearable={false}
                                                        />

                                                        <div className="flex space-x-2">
                                                            {(data.validations.charcount.type === 'Between' || 
                                                            data.validations.charcount.type === 'Min') && (
                                                                <Input
                                                                    type="number"
                                                                    placeholder="Min"
                                                                    value={data.validations.charcount.min ?? ''}
                                                                    onChange={(e) => handleValidationChange('charcount', 'min', e.target.value ? Number(e.target.value) : null)}
                                                                    className="w-24"
                                                                />
                                                            )}
                                                            {(data.validations.charcount.type === 'Between' || 
                                                            data.validations.charcount.type === 'Max') && (
                                                                <Input
                                                                    type="number"
                                                                    placeholder="Max"
                                                                    value={data.validations.charcount.max ?? ''}
                                                                    onChange={(e) => handleValidationChange('charcount', 'max', e.target.value ? Number(e.target.value) : null)}
                                                                    className="w-24"
                                                                />
                                                            )}
                                                        </div>
                                                    </div>

                                                    <Input
                                                        placeholder="Custom error message"
                                                        value={data.validations.charcount.message || ''}
                                                        onChange={(e) => handleValidationChange('charcount', 'message', e.target.value)}
                                                    />
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {fieldType !== 'password' && (
                            <div className="space-y-4 border-t pt-4">
                                <h2 className="font-bold">OTHER OPTIONS</h2>
                                
                                <div className="space-y-2">
                                    {fieldType !== 'richtext' && 
                                     fieldType !== 'slug' && 
                                     fieldType !== 'enumeration' && 
                                     fieldType !== 'boolean' && 
                                     fieldType !== 'media' && 
                                     fieldType !== 'relation' && 
                                     fieldType !== 'json' && (
                                        <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                                            <Checkbox
                                                id="repeatable"
                                                checked={data.options.repeatable}
                                                onCheckedChange={(checked) => {
                                                    setData('options', {
                                                        ...data.options,
                                                        repeatable: checked as boolean
                                                    });
                                                    if (checked) {
                                                        handleValidationChange('unique', 'status', false);
                                                    }
                                                }}
                                                className="mt-1"
                                            />
                                            <div className="space-y-1">
                                                <Label htmlFor="repeatable" className="font-medium">Repeatable Field</Label>
                                                <p className="text-sm text-muted-foreground">
                                                    Allows multiple values to be added to this field
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                                        <Checkbox
                                            id="hideInContentList"
                                            checked={data.options.hideInContentList}
                                            onCheckedChange={(checked) => 
                                                setData('options', {
                                                    ...data.options,
                                                    hideInContentList: checked as boolean
                                                })
                                            }
                                            className="mt-1"
                                        />
                                        <div className="space-y-1 w-full">
                                            <Label htmlFor="hideInContentList" className="font-medium">Hide in content list</Label>
                                            <p className="text-sm text-muted-foreground">
                                                Prevents this field from being displayed in the content list
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                                        <Checkbox
                                            id="hiddenInAPI"
                                            checked={data.options.hiddenInAPI}
                                            onCheckedChange={(checked) => 
                                                setData('options', {
                                                    ...data.options,
                                                    hiddenInAPI: checked as boolean
                                                })
                                            }
                                            className="mt-1"
                                        />
                                        <div className="space-y-1 w-full">
                                            <Label htmlFor="hiddenInAPI" className="font-medium">Hidden in API</Label>
                                            <p className="text-sm text-muted-foreground">
                                                Prevents this field from being included in the API response
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="flex justify-end space-x-2 pt-4">
                            <Button variant="outline" type="button" onClick={onClose}>
                                {t('fields.cancel')}
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? (editField ? t('fields.updating') : t('fields.adding')) : (editField ? t('fields.update_btn') : t('fields.add_btn'))}
                            </Button>
                        </div>
                    </form>
                    </ScrollArea>
                </DialogContent>
            </Dialog>

            <AlertDialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('fields.delete_title')}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('fields.delete_desc')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('fields.cancel')}</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleDelete}
                            className="bg-destructive hover:bg-destructive/90"
                        >
                            {t('fields.delete_btn')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
} 
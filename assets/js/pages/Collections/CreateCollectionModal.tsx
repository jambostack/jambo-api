import { useEffect, useState } from 'react';
import { toSnakeCase } from '@/lib/naming';
import axios from 'axios';
import { useTranslation } from '@/lib/i18n';

import { Collection } from '@/types/index.d';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/input-error';
import MultiSelect from '@/components/ui/select/Select';
import type { ActionMeta } from 'react-select';
import { Checkbox } from '@/components/ui/checkbox';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    projectUuid: string;
    collection?: Collection;
    onSuccess?: () => void;
}

interface Template {
    id: number;
    name: string;
    is_singleton?: boolean;
}

export default function CreateCollectionModal({ open, onOpenChange, projectId, projectUuid, collection, onSuccess }: Props) {
    const t = useTranslation();
    const [data, setDataState] = useState({
        name: collection?.name ?? '',
        slug: collection?.slug ?? '',
        template_id: '',
        is_singleton: false as boolean,
    });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const setData = (key: string, value: any) => setDataState(prev => ({ ...prev, [key]: value }));
    const reset = () => setDataState({ name: '', slug: '', template_id: '', is_singleton: false });
    const clearErrors = () => setErrors({});

    const [templates, setTemplates] = useState<Template[]>([]);

    useEffect(() => {
        if (!collection && open) {
            axios.get('/api/collection-templates')
                .then(res => setTemplates(res.data.data ?? res.data))
                .catch(() => setTemplates([]));
        }
    }, [open, collection]);

    // Generate slug from name
    useEffect(() => {
        if (data.name) {
            const generatedSlug = toSnakeCase(data.name);
            setData('slug', generatedSlug);
        }
    }, [data.name]);

    // Reset form when modal opens/closes or collection changes
    useEffect(() => {
        if (open) {
            setDataState({
                name: collection?.name ?? '',
                slug: collection?.slug ?? '',
                template_id: '',
                is_singleton: false as boolean,
            });
        } else {
            reset();
            clearErrors();
        }
    }, [open, collection]);

    // Also clear errors whenever modal is opened fresh
    useEffect(() => {
        if (open) {
            clearErrors();
        }
    }, [open]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        try {
            if (collection) {
                await axios.patch(`/api/projects/${projectUuid}/collections/${collection.slug}`, data);
            } else {
                await axios.post(`/api/projects/${projectUuid}/collections`, {
                    ...data,
                    template_id: data.template_id ? Number(data.template_id) : null,
                });
            }
            reset();
            onOpenChange(false);
            onSuccess?.();
        } catch (err: any) {
            setErrors(err.response?.data?.errors ?? {});
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader className='border-b pb-3'>
                    <DialogTitle>{collection ? t('collections.edit') : t('collections.create')}</DialogTitle>
                    <DialogDescription className='sr-only'>
                        Fill in the details below to create a new collection.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="name">{t('collections.name_label')}</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                            placeholder={t('collections.name_placeholder')}
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="slug">{t('collections.slug_label')}</Label>
                        <Input
                            id="slug"
                            value={data.slug}
                            onChange={(e) => setData('slug', e.target.value)}
                            required
                            placeholder={t('collections.slug_placeholder')}
                        />
                        <InputError message={errors.slug} />
                    </div>

                    {!collection && (
                        <div className="space-y-2">
                            <Label htmlFor="template">{t('collections.template_label')}</Label>
                            <MultiSelect
                                instanceId="template-select"
                                options={templates.map(tmpl => ({ value: tmpl.id.toString(), label: tmpl.name }))}
                                isClearable
                                isSearchable
                                placeholder={t('collections.template_placeholder')}
                                value={templates.map(tmpl => ({ value: tmpl.id.toString(), label: tmpl.name })).find(o => o.value === data.template_id) || null}
                                onChange={(newValue: any, _action: ActionMeta<any>) => {
                                    const tplId = newValue ? newValue.value : '';
                                    setData('template_id', tplId);
                                    if (tplId) {
                                        const tpl = templates.find(tmpl => tmpl.id.toString() === tplId);
                                        if (tpl) {
                                            setData('is_singleton', !!(tpl as any).is_singleton);
                                        }
                                    }
                                }}
                            />
                        </div>
                    )}

                    {/* Singleton toggle (only when creating) */}
                    {!collection && (
                        <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                            <Checkbox
                                id="is_singleton"
                                checked={!!data.is_singleton}
                                disabled={!!data.template_id}
                                onCheckedChange={(checked) => setData('is_singleton', checked === true)}
                                className='mt-1'
                            />
                            <div className="space-y-1">
                                <Label htmlFor="is_singleton">{t('collections.singleton_label')}</Label>
                                <p className="text-sm text-muted-foreground">{t('collections.singleton_desc')}</p>
                            </div>
                        </div>
                    )}

                    <DialogFooter className='border-t pt-3'>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {collection ? t('collections.update') : t('collections.create')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
} 
import { ActionMeta } from 'react-select'
import axios from 'axios';
import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Textarea } from '@/components/ui/textarea';
import MultiSelect from '@/components/ui/select/Select'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import InputError from '@/components/input-error';
import locales from '@/lib/locales.json'
import { useTranslation } from '@/lib/i18n';

type LocaleOption = {
    value: string;
    label: string;
};

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export default function CreateProjectModal({ open, onOpenChange }: Props) {
    const t = useTranslation();

    const localeOptions: LocaleOption[] = Object.entries(locales).map(([value, label]) => ({
        value,
        label: `${value} - ${label}`,
    }));

    const [data, setDataState] = useState({
        name: '',
        description: '',
        default_locale: 'en',
        template_slug: '',
        create_type: 'blank',
        with_demo_data: false as boolean,
    });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const setData = (key: string, value: any) => setDataState(prev => ({ ...prev, [key]: value }));
    const reset = () => setDataState({ name: '', description: '', default_locale: 'en', template_slug: '', create_type: 'blank', with_demo_data: false });

    const [templates, setTemplates] = useState<{ slug: string; name: string; description?: string; has_demo_data?: boolean }[]>([]);

    const templateOptions = templates.map((tmpl) => ({ value: tmpl.slug, label: tmpl.name }));

    useEffect(() => {
        if (open) {
            axios.get(route('project-templates.index'))
                .then(res => setTemplates(Array.isArray(res.data) ? res.data : (res.data.data ?? [])))
                .catch(() => setTemplates([]));
        }
    }, [open]);

    const selectedTemplate = templates.find((tmpl) => tmpl.slug === data.template_slug);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});
        try {
            const res = await axios.post('/api/projects', data);
            reset();
            onOpenChange(false);
            const projectId = res.data?.data?.id;
            if (projectId) {
                router.visit(route('projects.show', projectId));
            } else {
                router.reload();
            }
        } catch (err: any) {
            setErrors(err.response?.data?.errors ?? {});
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader className='border-b pb-3'>
                    <DialogTitle>{t('projects.create.title')}</DialogTitle>
                    <DialogDescription className='sr-only'>
                        {t('projects.create.title')}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="name">{t('projects.create.name')}</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                            placeholder={t('projects.create.name_placeholder')}
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="description">{t('projects.create.description')}</Label>
                        <Textarea
                            id="description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder={t('projects.create.desc_placeholder')}
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="default_locale">{t('projects.create.locale')}</Label>
                        <MultiSelect
                            defaultValue={localeOptions.find((opt) => opt.value === data.default_locale)}
                            isSearchable={true}
                            isClearable={true}
                            options={localeOptions}
                            onChange={(newValue: unknown, _actionMeta: ActionMeta<unknown>) => {
                                const option = newValue as LocaleOption | null;
                                setData('default_locale', option ? option.value : '');
                            }}
                        />
                        <InputError message={errors.default_locale} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="project_type">{t('projects.create.type')}</Label>
                        <RadioGroup
                            value={data.create_type}
                            onValueChange={(value) => {
                                setData('create_type', value);
                                if (value === 'blank') {
                                    setData('template_slug', '');
                                    setData('with_demo_data', false);
                                }
                            }}
                            className="grid gap-2"
                        >
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                                <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                                    <RadioGroupItem value="blank" id="type_blank" className="mt-1" />
                                    <div className="space-y-1">
                                        <Label htmlFor="type_blank" className="font-medium">{t('projects.create.blank')}</Label>
                                        <p className="text-sm text-muted-foreground">{t('projects.create.blank_desc')}</p>
                                    </div>
                                </div>

                                <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                                    <RadioGroupItem value="template" id="type_template" className="mt-1" />
                                    <div className="space-y-1">
                                        <Label htmlFor="type_template" className="font-medium">{t('projects.create.template')}</Label>
                                        <p className="text-sm text-muted-foreground">{t('projects.create.template_desc')}</p>
                                    </div>
                                </div>
                            </div>
                        </RadioGroup>

                        {data.create_type === 'template' && (
                            <div className="space-y-2 pt-2">
                                <Label htmlFor="template_select">{t('projects.create.template_select')}</Label>
                                <MultiSelect
                                    defaultValue={templateOptions.find((opt) => opt.value === data.template_slug)}
                                    isSearchable
                                    isClearable
                                    options={templateOptions}
                                    onChange={(newValue: unknown, _actionMeta: ActionMeta<unknown>) => {
                                        const option = newValue as { value: string } | null;
                                        setData('template_slug', option ? option.value : '');
                                        setData('with_demo_data', false);
                                    }}
                                />
                                <InputError message={errors.template_slug} />
                            </div>
                        )}

                        {data.create_type === 'template' && selectedTemplate?.has_demo_data && (
                            <div className="flex items-start space-x-2 p-4 rounded-md border border-dashed border-gray-600 dark:border-gray-400">
                                <Checkbox
                                    id="with_demo_data"
                                    checked={data.with_demo_data}
                                    onCheckedChange={(checked) => setData('with_demo_data', !!checked)}
                                    className='mt-1'
                                />
                                <div className="space-y-1">
                                    <Label htmlFor="with_demo_data" className="font-medium">{t('projects.create.demo_data')}</Label>
                                    <p className="text-sm text-muted-foreground">
                                        {t('projects.create.demo_data_desc')}
                                    </p>
                                </div>
                            </div>
                        )}
                    </div>

                    <DialogFooter className='border-t pt-3'>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {t('projects.create.submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

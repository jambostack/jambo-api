import { useForm } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import HeadingSmall from '@/components/heading-small';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { useTranslation } from '@/lib/i18n';

interface Props {
    projectId: number;
    projectUuid: string;
    projectName: string;
}

export default function DeleteProject({ projectId, projectUuid, projectName }: Props) {
    const t = useTranslation();
    const nameInput = useRef<HTMLInputElement>(null);
    const { data, setData, delete: destroy, processing, reset, errors, clearErrors } = useForm<{ confirm_name: string }>({ confirm_name: '' });

    const deleteProject: FormEventHandler = (e) => {
        e.preventDefault();

        destroy(`/api/projects/${projectUuid}`, {
            preserveScroll: true,
        });
    };

    const closeModal = () => {
        clearErrors();
        reset();
    };

    const isConfirmed = data.confirm_name === projectName;

    return (
        <div className="space-y-6">
            <HeadingSmall title={t('projects.delete.heading')} description={t('projects.delete.heading_desc')} />
            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">{t('projects.delete.warning')}</p>
                    <p className="text-sm">{t('projects.delete.warning_text')}</p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button variant="destructive">{t('projects.delete.btn')}</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>{t('projects.delete.dialog_title')}</DialogTitle>
                        <DialogDescription>
                            {t('projects.delete.dialog_desc')}
                        </DialogDescription>
                        <form className="space-y-6" onSubmit={deleteProject}>
                            <div className="space-y-2">
                                <div>{t('projects.delete.type_to_confirm')} <span className="font-mono bg-muted px-1 py-0.5 rounded border border-input inline-block select-all">{projectName}</span></div>

                                <Input
                                    id="confirm_name"
                                    name="confirm_name"
                                    ref={nameInput}
                                    value={data.confirm_name}
                                    onChange={(e) => setData('confirm_name', e.target.value)}
                                    placeholder={projectName}
                                />

                                <InputError message={errors.confirm_name} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary" onClick={closeModal} type="button">
                                        {t('projects.delete.cancel')}
                                    </Button>
                                </DialogClose>

                                <Button variant="destructive" disabled={processing || !isConfirmed} asChild>
                                    <button type="submit">{t('projects.delete.btn')}</button>
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}

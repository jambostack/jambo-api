import { useForm } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import HeadingSmall from '@/components/heading-small';
import { useTranslation } from '@/lib/i18n';

import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

export default function DeleteUser() {
    const t = useTranslation();
    const passwordInput = useRef<HTMLInputElement>(null);
    const { data, setData, delete: destroy, processing, reset, errors, clearErrors } = useForm<Required<{ password: string }>>({ password: '' });

    const deleteUser: FormEventHandler = (e) => {
        e.preventDefault();

        destroy('/api/settings/profile', {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => passwordInput.current?.focus(),
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        clearErrors();
        reset();
    };

    return (
        <div className="space-y-6">
            <HeadingSmall title={t('settings.delete_user.heading')} description={t('settings.delete_user.heading_desc')} />
            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">{t('settings.delete_user.warning')}</p>
                    <p className="text-sm">{t('settings.delete_user.warning_text')}</p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button variant="destructive">{t('settings.delete_user.btn')}</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>{t('settings.delete_user.dialog_title')}</DialogTitle>
                        <DialogDescription>
                            {t('settings.delete_user.dialog_desc')}
                        </DialogDescription>
                        <form className="space-y-6" onSubmit={deleteUser}>
                            <div className="grid gap-2">
                                <Label htmlFor="password" className="sr-only">
                                    {t('common.password')}
                                </Label>

                                <Input
                                    id="password"
                                    type="password"
                                    name="password"
                                    ref={passwordInput}
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    placeholder={t('common.password')}
                                    autoComplete="current-password"
                                />

                                <InputError message={errors.password} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary" onClick={closeModal}>
                                        {t('common.cancel')}
                                    </Button>
                                </DialogClose>

                                <Button variant="destructive" disabled={processing} asChild>
                                    <button type="submit">{t('settings.delete_user.btn')}</button>
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}

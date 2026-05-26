import { Head } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { useTranslation } from '@/lib/i18n';

interface LoginProps {
    status?: string;
    canResetPassword?: boolean;
    csrfToken: string;
    error?: string;
    lastUsername?: string;
}

export default function Login({ status, canResetPassword, csrfToken, error, lastUsername }: LoginProps) {
    const [processing, setProcessing] = useState(false);
    const formRef = useRef<HTMLFormElement>(null);
    const t = useTranslation();

    const submit: FormEventHandler = (e) => {
        setProcessing(true);
        // Let the native form submit — Symfony form_login handles everything
    };

    return (
        <AuthLayout title={t('auth.login.title')} description={t('auth.login.description')}>
            <Head title={t('auth.login.page_title')} />

            {error && (
                <div className="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                    {error}
                </div>
            )}

            {/* Native POST — Symfony form_login reads email/password from the encoded body */}
            <form
                ref={formRef}
                className="flex flex-col gap-6"
                action={route('login')}
                method="POST"
                onSubmit={submit}
            >
                <input type="hidden" name="_csrf_token" value={csrfToken} />

                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="email">{t('common.email')}</Label>
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="email"
                            defaultValue={lastUsername ?? ''}
                            placeholder="email@example.com"
                        />
                    </div>

                    <div className="grid gap-2">
                        <div className="flex items-center">
                            <Label htmlFor="password">{t('common.password')}</Label>
                            {canResetPassword && (
                                <TextLink href={route('password.request')} className="ml-auto text-sm" tabIndex={5}>
                                    {t('auth.forgot_password')}
                                </TextLink>
                            )}
                        </div>
                        <Input
                            id="password"
                            name="password"
                            type="password"
                            required
                            tabIndex={2}
                            autoComplete="current-password"
                            placeholder={t('common.password')}
                        />
                    </div>

                    <div className="flex items-center space-x-3">
                        <Checkbox
                            id="remember"
                            name="_remember_me"
                            tabIndex={3}
                        />
                        <Label htmlFor="remember">{t('auth.remember_me')}</Label>
                    </div>

                    <Button type="submit" className="mt-4 w-full" tabIndex={4} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        {t('auth.login.submit')}
                    </Button>
                </div>
            </form>

            {status && <div className="mb-4 text-center text-sm font-medium text-green-600">{status}</div>}
        </AuthLayout>
    );
}

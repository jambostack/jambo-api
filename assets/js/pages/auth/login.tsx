import { Head } from '@inertiajs/react';
import { Building2, LoaderCircle } from 'lucide-react';
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
    socialProviders?: string[];
    oidcProviders?: Array<{ id: string; name: string }>;
}

const SOCIAL_BUTTONS: Record<string, { label: string; icon: string; bg: string }> = {
    google:    { label: 'Google',    icon: 'G',  bg: '#4285F4' },
    microsoft: { label: 'Microsoft', icon: 'M',  bg: '#00A4EF' },
    github:    { label: 'GitHub',    icon: 'Gh', bg: '#24292e' },
    gitlab:    { label: 'GitLab',    icon: 'Gi', bg: '#FC6D26' },
};

export default function Login({ status, canResetPassword, csrfToken, error, lastUsername, socialProviders, oidcProviders }: LoginProps) {
    const [processing, setProcessing] = useState(false);
    const formRef = useRef<HTMLFormElement>(null);
    const t = useTranslation();

    const submit: FormEventHandler = (e) => {
        setProcessing(true);
    };

    const hasSocial = socialProviders && socialProviders.length > 0;

    return (
        <AuthLayout title={t('auth.login.title')} description={t('auth.login.description')}>
            <Head title={t('auth.login.page_title')} />

            {error && (
                <div className="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                    {error === 'oidc_failed' ? t('oidc.error_failed') : error}
                </div>
            )}

            {hasSocial && (
                <div className="flex flex-col gap-2 mb-6">
                    {socialProviders.map((p) => {
                        const cfg = SOCIAL_BUTTONS[p];
                        if (!cfg) return null;
                        return (
                            <a
                                key={p}
                                href={`/connect/${p}`}
                                className="flex items-center justify-center gap-3 rounded-md px-4 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90"
                                style={{ backgroundColor: cfg.bg }}
                            >
                                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-white/20 text-xs font-bold">
                                    {cfg.icon}
                                </span>
                                {t('auth.social.connect_with', { provider: cfg.label })}
                            </a>
                        );
                    })}
                    <div className="relative my-2">
                        <div className="absolute inset-0 flex items-center">
                            <span className="w-full border-t border-border" />
                        </div>
                        <div className="relative flex justify-center text-xs uppercase">
                            <span className="bg-card px-2 text-muted-foreground">{t('auth.social.or')}</span>
                        </div>
                    </div>
                </div>
            )}

            {oidcProviders && oidcProviders.length > 0 && (
                <div className="flex flex-col gap-2 mb-6">
                    {!hasSocial && (
                        <div className="relative my-2">
                            <div className="absolute inset-0 flex items-center">
                                <span className="w-full border-t border-border" />
                            </div>
                            <div className="relative flex justify-center text-xs uppercase">
                                <span className="bg-card px-2 text-muted-foreground">{t('auth.social.or')}</span>
                            </div>
                        </div>
                    )}
                    {oidcProviders.map((p) => (
                        <a
                            key={p.id}
                            href={`/oidc/start/${p.id}`}
                            className="flex items-center justify-center gap-3 rounded-md border border-border px-4 py-3 text-sm font-semibold transition-colors hover:bg-muted"
                        >
                            <Building2 className="h-5 w-5" />
                            {p.name}
                        </a>
                    ))}
                </div>
            )}

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

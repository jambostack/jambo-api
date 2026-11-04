import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp';
import { cn } from '@/lib/utils';
import { useTranslation } from '@/lib/i18n';

export default function TwoFactorChallenge({ error }: { error?: string }) {
    const t = useTranslation();
    const [code, setCode] = useState('');
    const [useBackup, setUseBackup] = useState(false);
    const [sending, setSending] = useState(false);

    const { post, processing } = useForm({ code: '', use_backup: false });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/two-factor-challenge', {
            data: { code, use_backup: useBackup },
            onError: () => setCode(''),
        });
    };

    const resendEmail = async () => {
        setSending(true);
        const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
        await fetch('/two-factor-challenge/send-email', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf } });
        setSending(false);
    };

    return (
        <div className="flex min-h-screen items-center justify-center p-4">
            <div className="w-full max-w-sm space-y-6">
                <div className="text-center">
                    <h1 className="text-xl font-semibold">{t('auth.2fa.title')}</h1>
                    <p className="text-sm text-muted-foreground mt-2">
                        {useBackup
                            ? t('auth.2fa.backup_desc')
                            : t('auth.2fa.totp_desc')}
                    </p>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    {!useBackup ? (
                        <div className="flex justify-center">
                            <InputOTP maxLength={6} value={code} onChange={setCode}>
                                <InputOTPGroup>
                                    <InputOTPSlot index={0} />
                                    <InputOTPSlot index={1} />
                                    <InputOTPSlot index={2} />
                                    <InputOTPSlot index={3} />
                                    <InputOTPSlot index={4} />
                                    <InputOTPSlot index={5} />
                                </InputOTPGroup>
                            </InputOTP>
                        </div>
                    ) : (
                        <input
                            type="text"
                            value={code}
                            onChange={e => setCode(e.target.value)}
                            placeholder="XXXX-XXXX-XXXX-XXXX"
                            className="w-full px-3 py-2 border rounded-md text-center font-mono text-sm"
                            autoComplete="off"
                        />
                    )}

                    {error && (
                        <p className="text-sm text-red-500 text-center">{t(error)}</p>
                    )}

                    <Button type="submit" disabled={processing || code.length < 6} className="w-full">
                        {t('auth.2fa.verify_btn')}
                    </Button>
                </form>

                <div className="flex flex-col gap-2 items-center">
                    <button
                        type="button"
                        onClick={resendEmail}
                        disabled={sending}
                        className="text-xs text-primary hover:underline"
                    >
                        {sending ? t('auth.2fa.sending') : t('auth.2fa.resend_email')}
                    </button>
                    <button
                        type="button"
                        onClick={() => { setUseBackup(!useBackup); setCode(''); }}
                        className="text-xs text-muted-foreground hover:underline"
                    >
                        {useBackup ? t('auth.2fa.use_totp') : t('auth.2fa.use_backup')}
                    </button>
                </div>
            </div>
        </div>
    );
}

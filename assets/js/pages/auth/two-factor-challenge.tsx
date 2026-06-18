import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp';
import { cn } from '@/lib/utils';

export default function TwoFactorChallenge({ error }: { error?: string }) {
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
        await fetch('/two-factor-challenge/send-email', { method: 'POST' });
        setSending(false);
    };

    return (
        <div className="flex min-h-screen items-center justify-center p-4">
            <div className="w-full max-w-sm space-y-6">
                <div className="text-center">
                    <h1 className="text-xl font-semibold">Vérification en deux étapes</h1>
                    <p className="text-sm text-muted-foreground mt-2">
                        {useBackup
                            ? 'Entrez un de vos codes de secours.'
                            : 'Entrez le code à 6 chiffres depuis votre application d\'authentification.'}
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
                        <p className="text-sm text-red-500 text-center">{error}</p>
                    )}

                    <Button type="submit" disabled={processing || code.length < 6} className="w-full">
                        Vérifier
                    </Button>
                </form>

                <div className="flex flex-col gap-2 items-center">
                    <button
                        type="button"
                        onClick={resendEmail}
                        disabled={sending}
                        className="text-xs text-primary hover:underline"
                    >
                        {sending ? 'Envoi en cours...' : 'Envoyer un code par email'}
                    </button>
                    <button
                        type="button"
                        onClick={() => { setUseBackup(!useBackup); setCode(''); }}
                        className="text-xs text-muted-foreground hover:underline"
                    >
                        {useBackup ? 'Utiliser l\'application d\'authentification' : 'Utiliser un code de secours'}
                    </button>
                </div>
            </div>
        </div>
    );
}

import React, { useState, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Shield, Key, Mail, AlertTriangle, Link, Unlink } from 'lucide-react';

const SOCIAL_LABELS: Record<string, string> = {
    google: 'Google', microsoft: 'Microsoft', github: 'GitHub', gitlab: 'GitLab',
};

export default function Security() {
    const [status, setStatus] = useState<any>(null);
    const [loading, setLoading] = useState(true);
    const [setupData, setSetupData] = useState<any>(null);
    const [code, setCode] = useState('');
    const [method, setMethod] = useState<'totp' | 'email'>('totp');
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [backupCodes, setBackupCodes] = useState<string[]>([]);
    const [showBackupCodes, setShowBackupCodes] = useState(false);
    const [socialLinked, setSocialLinked] = useState<Record<string, boolean>>({});
    const [socialProviders, setSocialProviders] = useState<string[]>([]);

    const csrf = () => (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';

    const fetchStatus = async () => {
        const res = await fetch('/api/settings/security');
        const data = await res.json();
        setStatus(data);
        setLoading(false);
    };

    useEffect(() => {
        fetchStatus();
        fetchSocial();
    }, []);

    const api = async (url: string, body?: any) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'An error occurred');
        return data;
    };

    const setupTotp = async () => {
        setError(''); setMessage('');
        const pw = prompt('Entrez votre mot de passe pour continuer :');
        if (!pw) return;
        try {
            const data = await api('/api/settings/security/totp/setup', { password: pw });
            setSetupData(data);
            setMethod('totp');
        } catch (e: any) { setError(e.message); }
    };

    const confirmTotp = async () => {
        setError(''); setMessage('');
        try {
            const data = await api('/api/settings/security/totp/confirm', { code });
            setMessage(data.message);
            setBackupCodes(data.backup_codes ?? []);
            setSetupData(null); setCode('');
            fetchStatus();
        } catch (e: any) { setError(e.message); }
    };

    const setupEmail = async () => {
        setError(''); setMessage('');
        const pw = prompt('Entrez votre mot de passe pour continuer :');
        if (!pw) return;
        try {
            await api('/api/settings/security/email/enable', { password: pw });
            setMethod('email');
            setMessage('Code envoyé à votre adresse email.');
        } catch (e: any) { setError(e.message); }
    };

    const confirmEmail = async () => {
        setError(''); setMessage('');
        try {
            const data = await api('/api/settings/security/email/confirm', { code });
            setMessage(data.message);
            setBackupCodes(data.backup_codes ?? []);
            setCode('');
            fetchStatus();
        } catch (e: any) { setError(e.message); }
    };

    const disable = async () => {
        const pw = prompt('Entrez votre mot de passe pour confirmer :');
        if (!pw) return;
        setError(''); setMessage('');
        try {
            const data = await api('/api/settings/security/disable', { password: pw });
            setMessage(data.message);
            setBackupCodes([]);
            fetchStatus();
        } catch (e: any) { setError(e.message); }
    };

    const fetchSocial = async () => {
        try {
            const res = await fetch('/api/settings/security/social');
            if (res.ok) {
                const data = await res.json();
                setSocialLinked(data.linked ?? {});
                setSocialProviders(data.providers ?? []);
            }
        } catch {}
    };

    const unlinkSocial = async (provider: string) => {
        setError(''); setMessage('');
        try {
            const res = await fetch(`/api/settings/security/social/${provider}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf() },
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error);
            setMessage(data.message);
            fetchSocial();
        } catch (e: any) { setError(e.message); }
    };

    const regenerateCodes = async () => {
        setError(''); setMessage('');
        try {
            const data = await api('/api/settings/security/backup-codes');
            setMessage(data.message);
            setBackupCodes(data.backup_codes ?? []);
        } catch (e: any) { setError(e.message); }
    };

    if (loading) return <p className="text-sm text-muted-foreground">Loading...</p>;

    return (
        <div className="space-y-6">
            {/* Status Card */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Shield className="h-4 w-4" />
                        Authentification à deux facteurs
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex items-center gap-2">
                        <span className="text-sm">Statut :</span>
                        {status?.two_factor_enabled ? (
                            <Badge variant="default" className="bg-green-600">Activée ({status.two_factor_method})</Badge>
                        ) : (
                            <Badge variant="outline">Désactivée</Badge>
                        )}
                    </div>

                    {message && <p className="text-sm text-green-600 bg-green-50 dark:bg-green-950/20 p-2 rounded">{message}</p>}
                    {error && <p className="text-sm text-red-600 bg-red-50 dark:bg-red-950/20 p-2 rounded">{error}</p>}

                    {!status?.two_factor_enabled && (
                        <div className="space-y-3">
                            {/* TOTP Setup */}
                            {!setupData && method === 'totp' && (
                                <Button variant="outline" onClick={setupTotp} className="w-full">
                                    <Key className="h-4 w-4 mr-2" />
                                    Configurer avec une application d'authentification (TOTP)
                                </Button>
                            )}

                            {setupData && (
                                <div className="space-y-3 p-3 border rounded-lg">
                                    <p className="text-xs text-muted-foreground">
                                        Scannez ce QR code avec Google Authenticator, Authy ou une application compatible :
                                    </p>
                                    <img src={setupData.qr_code_uri} alt="QR Code" className="w-48 h-48 mx-auto" />
                                    <p className="text-xs font-mono text-center select-all">{setupData.secret}</p>
                                    <div className="flex justify-center">
                                        <InputOTP maxLength={6} value={code} onChange={setCode}>
                                            <InputOTPGroup>
                                                <InputOTPSlot index={0} /><InputOTPSlot index={1} /><InputOTPSlot index={2} />
                                                <InputOTPSlot index={3} /><InputOTPSlot index={4} /><InputOTPSlot index={5} />
                                            </InputOTPGroup>
                                        </InputOTP>
                                    </div>
                                    <Button onClick={confirmTotp} disabled={code.length < 6} className="w-full" size="sm">
                                        Vérifier et activer
                                    </Button>
                                </div>
                            )}

                            {/* Email Setup */}
                            <div className="border-t pt-3">
                                <Button variant="outline" onClick={setupEmail} className="w-full">
                                    <Mail className="h-4 w-4 mr-2" />
                                    Recevoir un code par email
                                </Button>
                            </div>

                            {method === 'email' && !status?.two_factor_enabled && !setupData && (
                                <div className="space-y-3 p-3 border rounded-lg">
                                    <div className="flex justify-center">
                                        <InputOTP maxLength={6} value={code} onChange={setCode}>
                                            <InputOTPGroup>
                                                <InputOTPSlot index={0} /><InputOTPSlot index={1} /><InputOTPSlot index={2} />
                                                <InputOTPSlot index={3} /><InputOTPSlot index={4} /><InputOTPSlot index={5} />
                                            </InputOTPGroup>
                                        </InputOTP>
                                    </div>
                                    <Button onClick={confirmEmail} disabled={code.length < 6} className="w-full" size="sm">
                                        Vérifier et activer
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Backup Codes */}
                    {status?.has_backup_codes && (
                        <div className="space-y-2 p-3 border rounded-lg">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold">Codes de secours</span>
                                <div className="flex gap-2">
                                    <Button variant="ghost" size="sm" className="text-xs h-6"
                                        onClick={() => setShowBackupCodes(!showBackupCodes)}>
                                        {showBackupCodes ? 'Masquer' : 'Afficher'}
                                    </Button>
                                    <Button variant="ghost" size="sm" className="text-xs h-6" onClick={regenerateCodes}>
                                        Régénérer
                                    </Button>
                                </div>
                            </div>
                            {showBackupCodes && backupCodes.length > 0 && (
                                <div className="grid grid-cols-2 gap-1">
                                    {backupCodes.map((c: string, i: number) => (
                                        <code key={i} className="text-xs font-mono p-1 bg-muted rounded">{c}</code>
                                    ))}
                                </div>
                            )}
                            {(!showBackupCodes || backupCodes.length === 0) && (
                                <p className="text-xs text-muted-foreground">8 codes de secours disponibles. À usage unique.</p>
                            )}
                        </div>
                    )}

                    {/* Disable */}
                    {status?.two_factor_enabled && (
                        <div className="border-t pt-3">
                            <Button variant="destructive" size="sm" onClick={disable} className="w-full">
                                <AlertTriangle className="h-4 w-4 mr-2" />
                                Désactiver la 2FA
                            </Button>
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* Linked Accounts */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Link className="h-4 w-4" />
                        Comptes liés
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {socialProviders.length === 0 ? (
                        <p className="text-xs text-muted-foreground">Aucun fournisseur social configuré.</p>
                    ) : (
                        <div className="space-y-3">
                            {socialProviders.map((p) => (
                                <div key={p} className="flex items-center justify-between border-b pb-2 last:border-b-0 last:pb-0">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-medium">{SOCIAL_LABELS[p] ?? p}</span>
                                        {socialLinked[p] ? (
                                            <Badge variant="default" className="bg-green-600 text-xs">Lié</Badge>
                                        ) : (
                                            <Badge variant="outline" className="text-xs">Non lié</Badge>
                                        )}
                                    </div>
                                    {socialLinked[p] ? (
                                        <Button variant="ghost" size="sm" className="text-xs h-7 text-destructive"
                                            onClick={() => unlinkSocial(p)}>
                                            <Unlink className="h-3 w-3 mr-1" />
                                            Dissocier
                                        </Button>
                                    ) : (
                                        <a href={`/connect/${p}`}>
                                            <Button variant="outline" size="sm" className="text-xs h-7">
                                                <Link className="h-3 w-3 mr-1" />
                                                Lier
                                            </Button>
                                        </a>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

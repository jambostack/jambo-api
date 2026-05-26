import AppLogoIcon from '@/components/app-logo-icon';
import { Link } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';

export default function AuthCardLayout({
    children,
    title,
    description,
}: PropsWithChildren<{
    name?: string;
    title?: string;
    description?: string;
}>) {
    const appName = import.meta.env.APP_NAME ?? 'JamboAPI';

    return (
        <div className="min-h-svh flex flex-col lg:flex-row">
            {/* ── Brand panel ───────────────────────────────── */}
            <div className="hidden lg:flex lg:w-[440px] xl:w-[520px] flex-col justify-between p-12 bg-foreground text-background relative overflow-hidden flex-shrink-0">
                {/* Grid texture */}
                <div
                    className="absolute inset-0 pointer-events-none"
                    style={{
                        backgroundImage:
                            'linear-gradient(oklch(1 0 0 / 0.04) 1px, transparent 1px), linear-gradient(90deg, oklch(1 0 0 / 0.04) 1px, transparent 1px)',
                        backgroundSize: '72px 72px',
                    }}
                />
                {/* Corner accent glows */}
                <div
                    className="absolute -top-32 -right-32 w-96 h-96 rounded-full opacity-20 pointer-events-none"
                    style={{ background: 'radial-gradient(circle, var(--primary), transparent 70%)' }}
                />
                <div
                    className="absolute -bottom-24 -left-24 w-72 h-72 rounded-full opacity-10 pointer-events-none"
                    style={{ background: 'radial-gradient(circle, var(--accent-foreground), transparent 70%)' }}
                />

                {/* Logo */}
                <div className="relative z-10">
                    <Link href="/" className="inline-flex items-center gap-3 group">
                        <div className="flex items-center justify-center w-10 h-10 rounded-xl bg-primary/20 group-hover:bg-primary/30 transition-colors">
                            <AppLogoIcon className="size-6 fill-current text-primary" />
                        </div>
                        <span
                            className="text-lg font-bold tracking-tight text-background"
                            style={{ fontFamily: 'Syne, sans-serif' }}
                        >
                            {appName}
                        </span>
                    </Link>
                </div>

                {/* Hero copy */}
                <div className="relative z-10 space-y-8">
                    <div className="space-y-4">
                        <div
                            className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold tracking-widest uppercase text-primary"
                            style={{
                                background: 'oklch(from var(--primary) l c h / 0.15)',
                                border: '1px solid oklch(from var(--primary) l c h / 0.25)',
                            }}
                        >
                            <span className="w-1.5 h-1.5 rounded-full bg-current animate-pulse" />
                            Headless CMS
                        </div>
                        <h1
                            className="text-4xl xl:text-5xl font-bold leading-[1.08] tracking-tight"
                            style={{ fontFamily: 'Syne, sans-serif' }}
                        >
                            Your content,
                            <br />
                            <span className="text-primary">your API.</span>
                        </h1>
                        <p className="text-base leading-relaxed max-w-xs opacity-55">
                            Structure your content, build flexible schemas, and deliver data anywhere via REST API.
                        </p>
                    </div>

                    {/* Stats row */}
                    <div className="grid grid-cols-3 gap-4 pt-2">
                        {[
                            { label: 'Collections', value: '∞' },
                            { label: 'API Ready', value: 'REST' },
                            { label: 'Headless', value: '</>' },
                        ].map(({ label, value }) => (
                            <div
                                key={label}
                                className="rounded-xl p-3 text-center"
                                style={{ background: 'oklch(1 0 0 / 0.05)', border: '1px solid oklch(1 0 0 / 0.08)' }}
                            >
                                <div
                                    className="text-2xl font-bold text-primary"
                                    style={{ fontFamily: 'Syne, sans-serif' }}
                                >
                                    {value}
                                </div>
                                <div className="text-[10px] font-medium uppercase tracking-wider mt-1 opacity-35">
                                    {label}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Footer */}
                <div className="relative z-10 text-xs opacity-25">
                    © {new Date().getFullYear()} {appName}. All rights reserved.
                </div>
            </div>

            {/* ── Form panel ────────────────────────────────── */}
            <div className="flex-1 flex flex-col items-center justify-center p-6 md:p-10 bg-background min-h-svh lg:min-h-0">
                {/* Mobile logo */}
                <div className="lg:hidden mb-10 flex items-center gap-3">
                    <div className="flex items-center justify-center w-9 h-9 rounded-xl bg-primary/10">
                        <AppLogoIcon className="size-5 fill-current text-primary" />
                    </div>
                    <span className="text-lg font-bold tracking-tight" style={{ fontFamily: 'Syne, sans-serif' }}>
                        {appName}
                    </span>
                </div>

                <div className="w-full max-w-[380px] space-y-6">
                    {(title || description) && (
                        <div className="text-center space-y-1.5">
                            {title && (
                                <h2
                                    className="text-2xl font-bold tracking-tight"
                                    style={{ fontFamily: 'Syne, sans-serif' }}
                                >
                                    {title}
                                </h2>
                            )}
                            {description && <p className="text-sm text-muted-foreground">{description}</p>}
                        </div>
                    )}
                    {children}
                </div>
            </div>
        </div>
    );
}

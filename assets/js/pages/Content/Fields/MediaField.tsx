import { useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { PageProps as InertiaPageProps } from '@inertiajs/core';
import { useTranslation } from '@/lib/i18n';

import { Asset, Project } from '@/types';

import FieldBase, { FieldProps } from './FieldBase';

import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { FileImage, FileText, FileVideo, FileAudio, File, X, FolderOpen, Plus, ImagePlus } from 'lucide-react';

import { MediaLibraryModal } from '@/pages/Assets/MediaFieldSelectModal';

interface PageProps extends InertiaPageProps {
    project: Project;
}

export default function MediaField({ field, value, onChange, processing, errors }: FieldProps) {
    const t = useTranslation();
    const { project } = usePage<PageProps>().props;

    const isMultiple = field.options?.multiple || (field.options?.media?.type === 2);

    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedAssets, setSelectedAssets] = useState<Asset[]>([]);

    useEffect(() => {
        if (!value) {
            setSelectedAssets([]);
            return;
        }

        if (!isMultiple) {
            const assetId = Array.isArray(value) ? value[0] : value;
            if (!assetId) {
                setSelectedAssets([]);
                return;
            }

            fetch(route('assets.api.show', [project.uuid, assetId]))
                .then(res => res.json())
                .then(res => {
                    const asset = res.data ?? res;
                    setSelectedAssets([asset]);
                })
                .catch(error => {
                    console.error('Failed to load asset:', error);
                    setSelectedAssets([]);
                });
            return;
        }

        if (!Array.isArray(value) || value.length === 0) {
            setSelectedAssets([]);
            return;
        }

        Promise.all(
            value.map(id =>
                fetch(route('assets.api.show', [project.uuid, id]))
                    .then(res => res.json())
                    .then(res => res.data ?? res)
            )
        )
            .then(assets => {
                setSelectedAssets(assets);
            })
            .catch(error => {
                console.error('Failed to load assets:', error);
                setSelectedAssets([]);
            });
    }, [value, isMultiple, project.uuid]);

    const handleOpenModal = () => setIsModalOpen(true);
    const handleCloseModal = () => setIsModalOpen(false);

    const handleSelectAssets = (assets: Asset[]) => {
        setSelectedAssets(assets);
        if (isMultiple) {
            onChange(field, assets.map(asset => asset.uuid));
        } else {
            onChange(field, assets.length > 0 ? assets[0].uuid : null);
        }
    };

    const handleRemoveAsset = (assetToRemove: Asset) => {
        const updatedAssets = selectedAssets.filter(asset => asset.uuid !== assetToRemove.uuid);
        setSelectedAssets(updatedAssets);
        if (isMultiple) {
            onChange(field, updatedAssets.map(asset => asset.uuid));
        } else {
            onChange(field, updatedAssets.length > 0 ? updatedAssets[0].uuid : null);
        }
    };

    const getFileMeta = (asset: Asset): { icon: JSX.Element; tone: string } => {
        const ext = (asset?.extension || '').toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(ext)) {
            return { icon: <FileImage className="h-7 w-7" />, tone: 'text-sky-600 dark:text-sky-400' };
        }
        if (['mp4', 'webm', 'mov', 'avi', 'wmv', 'flv'].includes(ext)) {
            return { icon: <FileVideo className="h-7 w-7" />, tone: 'text-violet-600 dark:text-violet-400' };
        }
        if (['mp3', 'wav', 'ogg', 'aac', 'flac'].includes(ext)) {
            return { icon: <FileAudio className="h-7 w-7" />, tone: 'text-emerald-600 dark:text-emerald-400' };
        }
        if (['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'].includes(ext)) {
            return { icon: <FileText className="h-7 w-7" />, tone: 'text-amber-600 dark:text-amber-400' };
        }
        return { icon: <File className="h-7 w-7" />, tone: 'text-muted-foreground' };
    };

    const renderEmptyState = () => (
        <button
            type="button"
            onClick={handleOpenModal}
            disabled={processing}
            className="group relative w-full overflow-hidden rounded-xl border border-dashed border-border bg-gradient-to-br from-muted/40 via-background to-muted/20 px-6 py-10 text-start transition-all duration-300 hover:border-primary/50 hover:from-primary/[0.04] hover:to-muted/30 hover:shadow-[0_1px_2px_rgba(0,0,0,0.04),0_8px_24px_-12px_rgba(0,0,0,0.12)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        >
            {/* Decorative grid overlay */}
            <div
                className="pointer-events-none absolute inset-0 opacity-[0.025] [background-image:linear-gradient(to_right,currentColor_1px,transparent_1px),linear-gradient(to_bottom,currentColor_1px,transparent_1px)] [background-size:24px_24px]"
                aria-hidden="true"
            />
            {/* Ambient glow on hover */}
            <div
                className="pointer-events-none absolute -top-12 left-1/2 h-32 w-32 -translate-x-1/2 rounded-full bg-primary/20 opacity-0 blur-3xl transition-opacity duration-500 group-hover:opacity-100"
                aria-hidden="true"
            />

            <div className="relative flex flex-col items-center gap-4 text-center sm:flex-row sm:gap-5 sm:text-start">
                {/* Stacked icon medallion */}
                <div className="relative flex shrink-0 items-center justify-center">
                    {/* Back card (rotated -8deg) */}
                    <div
                        className="absolute -left-2 top-0 h-12 w-12 rotate-[-10deg] rounded-lg border border-border/70 bg-background/80 shadow-sm transition-all duration-300 group-hover:-translate-x-1 group-hover:-rotate-[14deg]"
                        aria-hidden="true"
                    >
                        <FileText className="absolute inset-0 m-auto h-5 w-5 text-muted-foreground/60" />
                    </div>
                    {/* Back card 2 (rotated +8deg) */}
                    <div
                        className="absolute -right-2 top-0 h-12 w-12 rotate-[10deg] rounded-lg border border-border/70 bg-background/80 shadow-sm transition-all duration-300 group-hover:translate-x-1 group-hover:rotate-[14deg]"
                        aria-hidden="true"
                    >
                        <FileVideo className="absolute inset-0 m-auto h-5 w-5 text-muted-foreground/60" />
                    </div>
                    {/* Front card */}
                    <div className="relative z-10 flex h-14 w-14 items-center justify-center rounded-xl border border-border bg-background shadow-sm transition-all duration-300 group-hover:-translate-y-1 group-hover:shadow-md">
                        <ImagePlus className="h-6 w-6 text-foreground/70 transition-colors duration-300 group-hover:text-primary" />
                    </div>
                </div>

                <div className="flex flex-1 flex-col gap-0.5 sm:ms-4">
                    <span className="text-sm font-semibold tracking-tight text-foreground">
                        {selectedAssets.length === 0
                            ? (isMultiple ? t('fields.media.select_multi') : t('fields.media.select_single'))
                            : (isMultiple ? t('fields.media.change_multi') : t('fields.media.change_single'))
                        }
                    </span>
                    <span className="text-xs leading-relaxed text-muted-foreground">
                        {t('fields.media.empty_hint')}
                    </span>
                </div>

                {/* Chevron-like cue */}
                <div className="hidden shrink-0 items-center gap-1.5 self-center rounded-md border border-border/70 bg-background/60 px-2.5 py-1 text-[10px] font-medium uppercase tracking-wider text-muted-foreground transition-colors duration-300 group-hover:border-primary/40 group-hover:bg-primary/[0.06] group-hover:text-primary sm:flex">
                    <FolderOpen className="h-3 w-3" />
                    <span>{t('common.browse')}</span>
                </div>
            </div>
        </button>
    );

    const renderSelectedGrid = () => (
        <div className="space-y-3">
            {/* Header: count + actions */}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <span className="inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-primary/10 px-2 text-[11px] font-semibold tabular-nums text-primary">
                        {selectedAssets.length}
                    </span>
                    <span className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        {t('fields.media.selected_label')}
                    </span>
                </div>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={processing}
                    onClick={handleOpenModal}
                    className="h-8 gap-1.5 rounded-md text-xs"
                >
                    {isMultiple ? (
                        <>
                            <Plus className="h-3.5 w-3.5" />
                            <span>{t('fields.media.add_more')}</span>
                        </>
                    ) : (
                        <>
                            <FolderOpen className="h-3.5 w-3.5" />
                            <span>{t('fields.media.change_single')}</span>
                        </>
                    )}
                </Button>
            </div>

            {/* Asset cards */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5">
                {selectedAssets.map((asset, index) => {
                    const meta = getFileMeta(asset);
                    return (
                        <div
                            key={asset.uuid ?? `${asset.id}-${index}`}
                            className="group relative overflow-hidden rounded-lg border border-border bg-card transition-all duration-300 hover:border-foreground/20 hover:shadow-[0_1px_2px_rgba(0,0,0,0.04),0_8px_20px_-10px_rgba(0,0,0,0.15)]"
                        >
                            {/* Preview area */}
                            <div className="relative aspect-[4/3] overflow-hidden bg-gradient-to-br from-muted/30 to-muted/60 dark:from-muted/40 dark:to-muted/20">
                                {asset.thumbnail_url ? (
                                    <img
                                        src={asset.thumbnail_url}
                                        alt={asset.metadata?.alt_text || asset.original_filename || ''}
                                        className="h-full w-full object-cover transition-transform duration-500 ease-out group-hover:scale-[1.04]"
                                        loading="lazy"
                                    />
                                ) : (
                                    <div className={`flex h-full w-full items-center justify-center ${meta.tone}`}>
                                        {meta.icon}
                                    </div>
                                )}

                                {/* Gradient veil from bottom on hover */}
                                <div
                                    className="pointer-events-none absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-black/55 via-black/15 to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100"
                                    aria-hidden="true"
                                />

                                {/* Remove button (top-right) */}
                                <button
                                    type="button"
                                    onClick={() => handleRemoveAsset(asset)}
                                    disabled={processing}
                                    aria-label={t('fields.media.remove')}
                                    className="absolute end-1.5 top-1.5 flex h-7 w-7 items-center justify-center rounded-full border border-white/15 bg-black/55 text-white opacity-0 backdrop-blur-md transition-all duration-200 hover:scale-105 hover:bg-red-500/95 hover:border-red-400 group-hover:opacity-100 focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500/50"
                                >
                                    <X className="h-3.5 w-3.5" strokeWidth={2.5} />
                                </button>

                                {/* Extension chip (bottom-left) */}
                                <div className="absolute bottom-1.5 start-1.5 inline-flex items-center gap-1 rounded-md border border-white/15 bg-black/55 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-white backdrop-blur-md">
                                    {asset.extension?.toUpperCase() || 'FILE'}
                                </div>
                            </div>

                            {/* Caption bar */}
                            <div className="flex items-center justify-between gap-2 border-t border-border/70 px-2.5 py-2">
                                <TooltipProvider>
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <div className="min-w-0 flex-1 truncate text-xs font-medium text-foreground" title={asset.original_filename || ''}>
                                                {asset.original_filename}
                                            </div>
                                        </TooltipTrigger>
                                        <TooltipContent side="bottom">{asset.original_filename}</TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                                <span className="shrink-0 text-[10px] font-medium tabular-nums text-muted-foreground">
                                    {asset.formatted_size || '—'}
                                </span>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <div className="flex flex-col gap-3">
                {selectedAssets.length === 0 ? renderEmptyState() : renderSelectedGrid()}

                <MediaLibraryModal
                    isOpen={isModalOpen}
                    onClose={handleCloseModal}
                    project={project}
                    onSelect={handleSelectAssets}
                    currentlySelected={selectedAssets}
                    allowMultiple={isMultiple}
                />
            </div>
        </FieldBase>
    );
}

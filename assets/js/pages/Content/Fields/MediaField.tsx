import { useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { PageProps as InertiaPageProps } from '@inertiajs/core';
import { useTranslation } from '@/lib/i18n';

import { Asset, Project } from '@/types';

import FieldBase, { FieldProps } from './FieldBase';

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { FileImage, FileText, FileVideo, FileAudio, File, X, FolderOpen } from 'lucide-react';

import { MediaLibraryModal } from '@/pages/Assets/MediaFieldSelectModal';
import { Badge } from '@/components/ui/badge';

interface PageProps extends InertiaPageProps {
    project: Project;
}

export default function MediaField({ field, value, onChange, processing, errors }: FieldProps) {
    const t = useTranslation();
    const { project } = usePage<PageProps>().props;

    // Add transformation of media.type to multiple property
    const isMultiple = field.options?.multiple || (field.options?.media?.type === 2);

    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedAssets, setSelectedAssets] = useState<Asset[]>([]);

    // Update selectedAssets whenever value changes
    useEffect(() => {
        // Reset selected assets if value is empty
        if (!value) {
            setSelectedAssets([]);
            return;
        }

        // Handle single media field
        if (!isMultiple) {
            const assetId = Array.isArray(value) ? value[0] : value;
            if (!assetId) {
                setSelectedAssets([]);
                return;
            }

            // Fetch the asset details
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

        // Handle multiple media field
        if (!Array.isArray(value) || value.length === 0) {
            setSelectedAssets([]);
            return;
        }

        // Fetch all asset details in parallel
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

    const handleOpenModal = () => {
        setIsModalOpen(true);
    };

    const handleCloseModal = () => {
        setIsModalOpen(false);
    };

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

    const getFileIcon = (asset: Asset) => {
        if (!asset || !asset.extension) {
            return <File className="h-8 w-8 text-muted-foreground" />;
        }

        const extension = asset.extension.toLowerCase();

        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(extension)) {
            return <FileImage className="h-8 w-8 text-blue-500" />;
        }

        if (['mp4', 'webm', 'ogg', 'mov', 'avi', 'wmv', 'flv'].includes(extension)) {
            return <FileVideo className="h-8 w-8 text-purple-500" />;
        }

        if (['mp3', 'wav', 'ogg', 'aac', 'flac'].includes(extension)) {
            return <FileAudio className="h-8 w-8 text-green-500" />;
        }

        if (['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'].includes(extension)) {
            return <FileText className="h-8 w-8 text-yellow-500" />;
        }

        return <File className="h-8 w-8 text-muted-foreground" />;
    };

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <div className="flex flex-col gap-4">
                <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={processing}
                        onClick={handleOpenModal}
                        className="w-full"
                    >
                        <FolderOpen className="h-4 w-4 mr-2" />
                        {selectedAssets.length === 0
                            ? (isMultiple ? t('fields.media.select_multi') : t('fields.media.select_single'))
                            : (isMultiple ? t('fields.media.change_multi') : t('fields.media.change_single'))
                        }
                    </Button>
                </div>

                {/* Display selected assets */}
                {selectedAssets.length > 0 && (
                    <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                        {selectedAssets.map((asset, index) => (
                            <Card key={asset.uuid ?? `${asset.id}-${index}`} className="overflow-hidden p-0">
                                <div className="relative h-40 bg-muted flex items-center justify-center group">
                                    {asset.thumbnail_url ? (
                                        <img
                                            src={asset.thumbnail_url}
                                            alt={asset.metadata?.alt_text || asset.original_filename}
                                            className="h-full w-full object-cover"
                                        />
                                    ) : (
                                        getFileIcon(asset)
                                    )}

                                    <div className="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors" />

                                    <div className="absolute top-2 right-2">
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            size="icon"
                                            className="h-7 w-7 rounded-full opacity-0 group-hover:opacity-100 transition-opacity"
                                            onClick={() => handleRemoveAsset(asset)}
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>

                                <CardContent>
                                    <TooltipProvider>
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <div className="truncate font-medium text-sm" title={asset.original_filename}>
                                                    {asset.original_filename}
                                                </div>
                                            </TooltipTrigger>
                                            <TooltipContent>{asset.original_filename}</TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                </CardContent>

                                <CardFooter className="px-3 py-2 border-t flex justify-between text-xs text-muted-foreground">
                                    <Badge variant="outline" className="h-5 text-xs flex items-center gap-1">
                                        {asset.extension?.toUpperCase() || 'FILE'}
                                    </Badge>
                                    <span>{asset.formatted_size || 'Unknown size'}</span>
                                </CardFooter>
                            </Card>
                        ))}
                    </div>
                )}

                {/* Media Library Modal */}
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
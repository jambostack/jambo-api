import React, { useState, useRef, useEffect } from 'react';
import { toast } from 'sonner';
import { useForm } from '@inertiajs/react';
import axios from 'axios';
import { useTranslation } from '@/lib/i18n';

import { Project, Asset } from '@/types';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {  Dialog,  DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { FileImage, FileText, FileVideo, FileAudio, File, Download, Crop, Lock, Unlock, Copy, Check, ChevronDown, ChevronUp, FileSpreadsheet, FileArchive, FileCode, MonitorPlay, Image } from 'lucide-react';
import ReactCrop, { Crop as CropType, centerCrop, makeAspectCrop } from 'react-image-crop';
import 'react-image-crop/dist/ReactCrop.css';
import InputError from '@/components/input-error';

interface AssetDetailsModalProps {
    isOpen: boolean;
    onClose: () => void;
    project: Project;
    asset: Asset;
    onUpdate?: (updatedAsset: Asset) => void;
}

export default function AssetDetailsModal({
    isOpen,
    onClose,
    project,
    asset: initialAsset,
    onUpdate
}: AssetDetailsModalProps) {
    const t = useTranslation();
    const [isCropping, setIsCropping] = useState(false);
    const [crop, setCrop] = useState<CropType>();
    const [completedCrop, setCompletedCrop] = useState<CropType>();
    const [maintainAspectRatio, setMaintainAspectRatio] = useState(true);
    const [asset, setAsset] = useState(initialAsset);
    const [copied, setCopied] = useState(false);
    const [copiedUuid, setCopiedUuid] = useState(false);
    const [showTransformations, setShowTransformations] = useState(false);
    const imgRef = useRef<HTMLImageElement>(null);
    const [submitting, setSubmitting] = useState(false);

    const { data, setData, errors, reset } = useForm({
        alt_text: initialAsset.metadata?.alt_text || '',
        title: initialAsset.metadata?.title || '',
        caption: initialAsset.metadata?.caption || '',
        description: initialAsset.metadata?.description || '',
        author: initialAsset.metadata?.author || '',
        copyright: initialAsset.metadata?.copyright || '',
    });

    // Update form data when initialAsset changes
    useEffect(() => {
        setData({
            alt_text: initialAsset.metadata?.alt_text || '',
            title: initialAsset.metadata?.title || '',
            caption: initialAsset.metadata?.caption || '',
            description: initialAsset.metadata?.description || '',
            author: initialAsset.metadata?.author || '',
            copyright: initialAsset.metadata?.copyright || '',
        });
    }, [initialAsset]);

    // Update local asset state when prop changes
    useEffect(() => {
        setAsset(initialAsset);
    }, [initialAsset]);

    useEffect(() => {
        if (!isOpen) {
            setIsCropping(false);
            setCrop(undefined);
            setCompletedCrop(undefined);
        }
    }, [isOpen]);

    const getFileIcon = (asset: Asset) => {
        const ext = asset.extension.toLowerCase();

        // Images
        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'tiff', 'tif', 'heic'].includes(ext)) {
            return <FileImage className="h-10 w-10 text-sky-500" />;
        }
        // Vecteurs
        if (['svg', 'eps', 'ai'].includes(ext)) {
            return <Image className="h-10 w-10 text-fuchsia-500" />;
        }
        // Vidéos
        if (['mp4', 'webm', 'mov', 'avi', 'wmv', 'flv', 'mkv'].includes(ext)) {
            return <FileVideo className="h-10 w-10 text-violet-500" />;
        }
        // Audio
        if (['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a'].includes(ext)) {
            return <FileAudio className="h-10 w-10 text-emerald-500" />;
        }
        // PDF
        if (ext === 'pdf') {
            return <FileText className="h-10 w-10 text-red-500" />;
        }
        // Tableurs
        if (['xls', 'xlsx', 'csv', 'ods'].includes(ext)) {
            return <FileSpreadsheet className="h-10 w-10 text-green-500" />;
        }
        // Présentations
        if (['ppt', 'pptx', 'odp', 'key'].includes(ext)) {
            return <MonitorPlay className="h-10 w-10 text-orange-500" />;
        }
        // Archives
        if (['zip', 'rar', '7z', 'gz', 'tar', 'bz2'].includes(ext)) {
            return <FileArchive className="h-10 w-10 text-stone-500" />;
        }
        // Code / JSON
        if (['json', 'xml', 'yaml', 'yml', 'html', 'css', 'js', 'ts', 'php', 'py', 'sql'].includes(ext)) {
            return <FileCode className="h-10 w-10 text-cyan-500" />;
        }
        // Texte / Documents
        if (['txt', 'md', 'doc', 'docx', 'rtf', 'odt'].includes(ext)) {
            return <FileText className="h-10 w-10 text-blue-500" />;
        }

        return <File className="h-10 w-10 text-muted-foreground" />;
    };

    const formatDate = (dateString: string) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);

        try {
            const response = await axios.put(route('assets.api.update', [project.uuid, asset.uuid]), data);
            
            if (response.data) {
                const updatedAsset = response.data;
                setAsset(updatedAsset);
                
                // Notify parent component if callback provided
                if (onUpdate) {
                    onUpdate(updatedAsset);
                }
                
                toast.success(t('assets.modal_update_success'));
            }
        } catch (error) {
            console.error('Error updating asset:', error);
            toast.error(t('assets.modal_update_error'));
        } finally {
            setSubmitting(false);
        }
    };

    const onImageLoad = (e: React.SyntheticEvent<HTMLImageElement>) => {
        const { width, height } = e.currentTarget;
        const crop = centerCrop(
            makeAspectCrop(
                {
                    unit: '%',
                    width: 90,
                },
                maintainAspectRatio ? 16 / 9 : 1,
                width,
                height
            ),
            width,
            height
        );
        setCrop(crop);
    };

    const handleCropComplete = async () => {
        if (!completedCrop || !imgRef.current) return;

        try {
            const image = imgRef.current;
            const canvas = document.createElement('canvas');

            // Get the actual displayed dimensions of the image
            const displayWidth = image.width;
            const displayHeight = image.height;

            // Get the natural (original) dimensions of the image
            const naturalWidth = image.naturalWidth;
            const naturalHeight = image.naturalHeight;

            // Calculate scaling factors
            const scaleX = naturalWidth / displayWidth;
            const scaleY = naturalHeight / displayHeight;

            // Calculate the actual crop dimensions in pixels
            const cropX = Math.round(completedCrop.x * scaleX);
            const cropY = Math.round(completedCrop.y * scaleY);
            const cropWidth = Math.round(completedCrop.width * scaleX);
            const cropHeight = Math.round(completedCrop.height * scaleY);

            // Set canvas dimensions to match the crop size
            canvas.width = cropWidth;
            canvas.height = cropHeight;

            const ctx = canvas.getContext('2d');
            if (!ctx) return;

            // Set high quality rendering
            ctx.imageSmoothingQuality = 'high';

            // Draw the cropped portion of the image
            ctx.drawImage(
                image,
                cropX,
                cropY,
                cropWidth,
                cropHeight,
                0,
                0,
                cropWidth,
                cropHeight
            );

            // Convert canvas to blob
            const blob = await new Promise<Blob>((resolve) => {
                canvas.toBlob((blob) => {
                    if (blob) resolve(blob);
                }, 'image/jpeg', 0.95);
            });

            // Create form data
            const formData = new FormData();
            formData.append('file', blob, asset.original_filename ?? '');
            formData.append('_method', 'PUT');

            // Update the existing asset
            const response = await axios.post(route('assets.crop', [project.uuid, asset.uuid]), formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });

            if (response.data) {
                const updatedAsset = response.data;
                setAsset(updatedAsset);
                
                // Notify parent component if callback provided
                if (onUpdate) {
                    onUpdate(updatedAsset);
                }

                toast.success(t('assets.modal_crop_success'));
                setIsCropping(false);
            }
        } catch (error) {
            toast.error(t('assets.modal_crop_error'));
        }
    };

    const handleCopyUuid = async () => {

        try {
            await navigator.clipboard.writeText(asset.uuid);
            setCopiedUuid(true);
            toast.success(t('assets.modal_uuid_copied'));
            setTimeout(() => setCopiedUuid(false), 2000);
        } catch (err) {
            toast.error(t('assets.modal_uuid_copy_error'));
        }
    };

    const handleCopyUrl = async () => {
        try {
            const urlToCopy = asset.full_url ?? asset.url ?? '';
            await navigator.clipboard.writeText(urlToCopy);
            setCopied(true);
            toast.success(t('assets.modal_url_copied'));
            setTimeout(() => setCopied(false), 2000);
        } catch (err) {
            toast.error(t('assets.modal_url_copy_error'));
        }
    };

    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(asset.extension.toLowerCase());

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className='sm:max-w-4xl max-h-[85vh] overflow-y-auto flex flex-col'>
                <DialogHeader className="flex flex-row items-center justify-between space-y-0 p-0">
                    <DialogTitle className="text-lg font-medium">{asset.original_filename}</DialogTitle>
                    <DialogDescription className="sr-only">
                        {asset.metadata?.alt_text || asset.original_filename}
                    </DialogDescription>
                    <div className="flex items-center gap-2">
                        {isImage && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="gap-1.5 h-8"
                                onClick={() => setIsCropping(!isCropping)}
                            >
                                <Crop className="h-3.5 w-3.5" />
                                {isCropping ? t('assets.modal_crop_cancel') : t('assets.modal_crop')}
                            </Button>
                        )}
                        <a href={asset.url ?? ''} download>
                            <Button variant="outline" size="sm" className="gap-1.5 h-8">
                                <Download className="h-3.5 w-3.5" />
                                {t('assets.modal_download')}
                            </Button>
                        </a>
                    </div>
                </DialogHeader>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-3 sm:gap-4">
                    {/* Asset Preview */}
                    <div className={`${isCropping ? 'lg:col-span-3' : 'lg:col-span-2'} space-y-4`}>
                        <div className={`bg-muted rounded-md flex items-center justify-center overflow-hidden ${isCropping ? 'max-h-[350px]' : 'h-[180px] sm:h-[250px]'}`}>
                            {isImage ? (
                                isCropping ? (
                                    <div className="relative w-full h-full flex items-center justify-center">
                                        <div className="absolute top-4 right-4 z-10 flex gap-2">
                                            <Button
                                                onClick={() => setMaintainAspectRatio(!maintainAspectRatio)}
                                                className="bg-primary text-primary-foreground hover:bg-primary/90 gap-1.5 h-8"
                                            >
                                                {maintainAspectRatio ? (
                                                    <>
                                                        <Lock className="h-3.5 w-3.5" />
                                                        {t('assets.modal_free_crop')}
                                                    </>
                                                ) : (
                                                    <>
                                                        <Unlock className="h-3.5 w-3.5" />
                                                        {t('assets.modal_fixed_ratio')}
                                                    </>
                                                )}
                                            </Button>
                                            <Button
                                                onClick={handleCropComplete}
                                                className="bg-primary text-primary-foreground hover:bg-primary/90 gap-1.5 h-8"
                                            >
                                                <Crop className="h-3.5 w-3.5" />
                                                {t('assets.modal_apply')}
                                            </Button>
                                        </div>
                                        <ReactCrop
                                            crop={crop}
                                            onChange={(c) => setCrop(c)}
                                            onComplete={(c) => setCompletedCrop(c)}
                                            aspect={maintainAspectRatio ? 16 / 9 : undefined}
                                            className="max-h-[500px] max-w-[500px]"
                                            minWidth={50}
                                            minHeight={50}
                                        >
                                            <img
                                                ref={imgRef}
                                                src={asset.url ?? ''}
                                                alt={asset.metadata?.alt_text || asset.original_filename || ''}
                                                onLoad={onImageLoad}
                                                className="max-h-[500px]"
                                            />
                                        </ReactCrop>
                                    </div>
                                ) : (
                                    <a href={asset.url ?? ''} target="_blank" rel="noopener noreferrer" className="text-sm font-medium truncate">
                                        <img
                                            src={asset.url ?? ''}
                                            alt={asset.metadata?.alt_text || asset.original_filename || ''}
                                            className="max-w-full object-contain rounded-md"
                                        />
                                    </a>
                                )
                            ) : (
                                <div className="flex flex-col items-center justify-center">
                                    {getFileIcon(asset)}
                                    <span className="mt-2 text-sm font-medium">{t('assets.modal_file_ext', { ext: asset.extension.toUpperCase() })}</span>
                                </div>
                            )}
                        </div>

                        {/* Asset Information */}
                        {!isCropping && (
                            <div className="bg-muted/50 rounded-md p-2 sm:p-3 max-h-[250px] overflow-y-auto">
                                <div className="flex items-center justify-between mb-1">
                                    <h3 className="text-sm font-medium">{t('assets.modal_file_info')}</h3>
                                </div>
                                <div className="grid grid-cols-2 gap-1 sm:gap-2">
                                    <div>
                                        <Label className="text-xs text-muted-foreground">{t('assets.modal_filename')}</Label>
                                        <p className="text-sm font-medium truncate">{asset.original_filename}</p>
                                    </div>
                                    <div>
                                        <Label className="text-xs text-muted-foreground">{t('assets.modal_file_id')}</Label>
                                        <div className="flex items-center gap-1.5 mt-0.5">
                                            <p className="text-sm font-medium truncate">{asset.uuid}</p>
                                        <div
                                                className="cursor-pointer hover:text-primary"
                                                onClick={handleCopyUuid}
                                            >
                                                {copiedUuid ? (
                                                    <Check className="h-3.5 w-3.5 text-green-500" />
                                                ) : (
                                                    <Copy className="h-3.5 w-3.5" />
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <Label className="text-xs text-muted-foreground">{t('assets.modal_file_url')}</Label>
                                        <div className="flex items-center gap-1.5 mt-0.5">
                                            <a href={asset.url ?? ''} target="_blank" rel="noopener noreferrer" className="text-sm font-medium truncate">{asset.full_url ?? asset.url ?? ''}</a>
                                            <div
                                                className="cursor-pointer hover:text-primary"
                                                onClick={handleCopyUrl}
                                            >
                                                {copied ? (
                                                    <Check className="h-3.5 w-3.5 text-green-500" />
                                                ) : (
                                                    <Copy className="h-3.5 w-3.5" />
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <Label className="text-xs text-muted-foreground">{t('assets.modal_file_type')}</Label>
                                        <p className="text-sm font-medium">{asset.mime_type}</p>
                                    </div>
                                    <div>
                                        <Label className="text-xs text-muted-foreground">{t('assets.modal_file_size')}</Label>
                                        <p className="text-sm font-medium">{asset.formatted_size}</p>
                                    </div>
                                    {isImage && asset.metadata?.width && asset.metadata?.height && (
                                        <div>
                                            <Label className="text-xs text-muted-foreground">{t('assets.modal_dimensions')}</Label>
                                            <p className="text-sm font-medium">{asset.metadata.width} × {asset.metadata.height} px</p>
                                        </div>
                                    )}

                                </div>
                                {isImage && (
                                    <div className="bg-muted/50 rounded-lg p-2 mt-1">
                                        <button
                                            type="button"
                                            className="flex items-center justify-between w-full text-xs font-semibold"
                                            onClick={() => setShowTransformations(!showTransformations)}
                                        >
                                            {t('assets.modal_transforms')}
                                            {showTransformations ? <ChevronUp className="h-3 w-3 flex-shrink-0 ml-1" /> : <ChevronDown className="h-3 w-3 flex-shrink-0 ml-1" />}
                                        </button>
                                        {showTransformations && (
                                            <div>
                                                <div className="space-y-1.5 mt-1">
                                                    {[
                                                        { labelKey: 'assets.modal_thumb_label', params: 'w=200&h=200&fit=crop&fmt=webp&q=80' },
                                                        { labelKey: 'assets.modal_medium_label', params: 'w=800&h=600&fit=scale-down' },
                                                        { labelKey: 'assets.modal_webp_label', params: 'fmt=webp&q=80' },
                                                        { labelKey: 'assets.modal_avif_label', params: 'fmt=avif&q=70' },
                                                    ].map(transform => {
                                                        const url = `/cdn/media/${asset.uuid}?${transform.params}`;
                                                        return (
                                                            <div key={transform.params} className="flex items-center justify-between text-xs">
                                                                <span className="text-muted-foreground truncate flex-1 mr-2">{t(transform.labelKey)}</span>
                                                                <button
                                                                    className="text-primary hover:underline shrink-0"
                                                                    onClick={() => { navigator.clipboard.writeText(url); toast.success(t('assets.modal_url_copied_toast')); }}
                                                                >
                                                                    {t('assets.modal_copy')}
                                                                </button>
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                                <p className="text-xs text-muted-foreground mt-2">
                                                    {t('assets.modal_transform_usage')} <code className="text-xs bg-muted px-1 rounded">{t('assets.modal_transform_code_hint')}</code>
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                )}
                                <div className="grid grid-cols-2 gap-1 mt-1">
                                    <div>
                                        <Label className="text-xs text-muted-foreground">{t('assets.modal_uploaded')}</Label>
                                        <p className="text-sm font-medium">{formatDate(asset.created_at)}</p>
                                    </div>
                                    <div>
                                        <Label className="text-xs text-muted-foreground">{t('assets.modal_modified')}</Label>
                                        <p className="text-sm font-medium">{formatDate(asset.updated_at)}</p>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Edit Form */}
                    {!isCropping && (
                        <div>
                            <div className="bg-muted/50 rounded-md p-2 sm:p-3 max-h-[300px] sm:max-h-none overflow-y-auto">
                                <form onSubmit={handleSubmit} className="space-y-1.5">
                                    <div>
                                        <Label htmlFor="alt_text" className="text-xs">{t('assets.modal_alt_text')}</Label>
                                        <Input
                                            id="alt_text"
                                            value={data.alt_text}
                                            onChange={(e) => setData('alt_text', e.target.value)}
                                            placeholder={t('assets.modal_alt_text_ph')}
                                            className="h-8 text-sm"
                                        />
                                        <InputError message={errors.alt_text} />
                                    </div>

                                    <div>
                                        <Label htmlFor="title" className="text-xs">{t('assets.modal_title')}</Label>
                                        <Input
                                            id="title"
                                            value={data.title}
                                            onChange={(e) => setData('title', e.target.value)}
                                            placeholder={t('assets.modal_title_ph')}
                                            className="h-8 text-sm"
                                        />
                                        <InputError message={errors.title} />
                                    </div>

                                    <div>
                                        <Label htmlFor="caption" className="text-xs">{t('assets.modal_caption')}</Label>
                                        <Textarea
                                            id="caption"
                                            value={data.caption}
                                            onChange={(e) => setData('caption', e.target.value)}
                                            placeholder={t('assets.modal_caption_ph')}
                                            rows={1}
                                            className="text-sm"
                                        />
                                        <InputError message={errors.caption} />
                                    </div>

                                    <div>
                                        <Label htmlFor="description" className="text-xs">{t('assets.modal_description')}</Label>
                                        <Textarea
                                            id="description"
                                            value={data.description}
                                            onChange={(e) => setData('description', e.target.value)}
                                            placeholder={t('assets.modal_description_ph')}
                                            rows={2}
                                            className="text-sm"
                                        />
                                        <InputError message={errors.description} />
                                    </div>

                                    <div className="">
                                        <Label htmlFor="author" className="text-xs">{t('assets.modal_author')}</Label>
                                        <Input
                                            id="author"
                                            value={data.author}
                                            onChange={(e) => setData('author', e.target.value)}
                                            placeholder={t('assets.modal_author_ph')}
                                            className="h-8 text-sm"
                                        />
                                        <InputError message={errors.author} />
                                    </div>

                                    <div className="">
                                        <Label htmlFor="copyright" className="text-xs">{t('assets.modal_copyright')}</Label>
                                        <Input
                                            id="copyright"
                                            value={data.copyright}
                                            onChange={(e) => setData('copyright', e.target.value)}
                                            placeholder={t('assets.modal_copyright_ph')}
                                            className="h-8 text-sm"
                                        />
                                        <InputError message={errors.copyright} />
                                    </div>

                                    <Button type="submit" disabled={submitting} size="sm" className="w-full mt-1">
                                        {submitting ? t('assets.modal_saving') : t('assets.modal_save')}
                                    </Button>
                                </form>
                            </div>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
} 
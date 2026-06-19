import { format } from 'date-fns';

import { Asset, UserCan } from '@/types';

import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FileImage, FileText, FileVideo, FileAudio, File, FileSpreadsheet, FileArchive, FileCode, MonitorPlay, Image } from 'lucide-react';

import ActionMenu from './AssetActionMenu';
import { usePage } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';

interface AssetTableProps {
    assets: Asset[];
    selectedAssets: number[];
    onAssetSelect: (assetId: number) => void;
    onViewDetails: (asset: Asset) => void;
    onDelete: (asset: Asset) => void;
}

const getFileIcon = (asset: Asset) => {
    const ext = asset.extension.toLowerCase();
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'tiff', 'tif', 'heic'].includes(ext)) {
        return <FileImage className="h-6 w-6 text-sky-500" />;
    }
    if (['svg', 'eps', 'ai'].includes(ext)) {
        return <Image className="h-6 w-6 text-fuchsia-500" />;
    }
    if (['mp4', 'webm', 'mov', 'avi', 'wmv', 'flv', 'mkv'].includes(ext)) {
        return <FileVideo className="h-6 w-6 text-violet-500" />;
    }
    if (['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a'].includes(ext)) {
        return <FileAudio className="h-6 w-6 text-emerald-500" />;
    }
    if (ext === 'pdf') {
        return <FileText className="h-6 w-6 text-red-500" />;
    }
    if (['xls', 'xlsx', 'csv', 'ods'].includes(ext)) {
        return <FileSpreadsheet className="h-6 w-6 text-green-500" />;
    }
    if (['ppt', 'pptx', 'odp', 'key'].includes(ext)) {
        return <MonitorPlay className="h-6 w-6 text-orange-500" />;
    }
    if (['zip', 'rar', '7z', 'gz', 'tar', 'bz2'].includes(ext)) {
        return <FileArchive className="h-6 w-6 text-stone-500" />;
    }
    if (['json', 'xml', 'yaml', 'yml', 'html', 'css', 'js', 'ts', 'php', 'py', 'sql'].includes(ext)) {
        return <FileCode className="h-6 w-6 text-cyan-500" />;
    }
    if (['txt', 'md', 'doc', 'docx', 'rtf', 'odt'].includes(ext)) {
        return <FileText className="h-6 w-6 text-blue-500" />;
    }
    return <File className="h-6 w-6 text-slate-400" />;
};

const getFileTypeClass = (extension: string) => {
    const ext = extension.toLowerCase();
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) {
        return 'hover:bg-blue-50 dark:hover:bg-blue-950/20';
    } else if (['mp4', 'webm', 'mov'].includes(ext)) {
        return 'hover:bg-red-50 dark:hover:bg-red-950/20';
    } else if (['mp3', 'wav', 'ogg'].includes(ext)) {
        return 'hover:bg-green-50 dark:hover:bg-green-950/20';
    } else if (['pdf', 'doc', 'docx', 'txt', 'rtf'].includes(ext)) {
        return 'hover:bg-yellow-50 dark:hover:bg-yellow-950/20';
    }
    return '';
};

export default function AssetTable({
    assets,
    selectedAssets,
    onAssetSelect,
    onViewDetails,
    onDelete
}: AssetTableProps) {
    const t = useTranslation();
    const can = usePage().props.userCan as UserCan;

    if (assets.length === 0) {
        return (
            <div className="text-center py-12 text-muted-foreground">
                {t('assets.no_assets')}
            </div>
        );
    }

    return (
        <div className="rounded-md overflow-hidden">
            <Table>
                <TableHeader className="bg-muted/50">
                    <TableRow className="hover:bg-muted/50">
                        {can.delete_asset && <TableHead className="w-10 align-middle text-center"></TableHead>}
                        <TableHead className="w-12 align-middle text-center"></TableHead>
                        <TableHead className="align-middle">{t('assets.col_name')}</TableHead>
                        <TableHead className="align-middle">{t('assets.col_type')}</TableHead>
                        <TableHead className="align-middle">{t('assets.col_size')}</TableHead>
                        <TableHead className="align-middle">{t('assets.col_date')}</TableHead>
                        {(can.update_asset || can.delete_asset) && <TableHead className="w-10 align-middle text-center">{t('assets.col_actions')}</TableHead>}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {assets.map((asset) => (
                        <TableRow key={asset.id} className={getFileTypeClass(asset.extension)}>
                            {can.delete_asset && (
                                <TableCell className="py-2 align-middle">
                                    <Checkbox
                                        checked={selectedAssets.includes(asset.id)}
                                        onCheckedChange={() => onAssetSelect(asset.id)}
                                    />
                                </TableCell>
                            )}
                            <TableCell className="py-2 align-middle text-center">
                                {asset.mime_type?.startsWith('image/') && asset.thumbnail_url ? (
                                    <div className="h-10 w-10 overflow-hidden">
                                        <img
                                            src={asset.thumbnail_url}
                                            alt={asset.metadata?.alt_text || asset.original_filename}
                                            className="h-full w-full object-cover"
                                        />
                                    </div>
                                ) : (
                                    <div className="h-10 w-10 flex items-center justify-center">
                                        {getFileIcon(asset)}
                                    </div>
                                )}
                            </TableCell>
                            <TableCell className="py-2 align-middle">
                                <span
                                    className="cursor-pointer hover:underline"
                                    onClick={() => onViewDetails(asset)}
                                >
                                    {asset.original_filename}
                                </span>
                            </TableCell>
                            <TableCell className="py-2 align-middle">
                                <Badge variant="outline" className="flex items-center gap-1 px-2 py-1">
                                    {getFileIcon(asset)}
                                    <span>{asset.extension.toUpperCase()}</span>
                                </Badge>
                            </TableCell>
                            <TableCell className="py-2 text-muted-foreground text-sm">
                                {asset.formatted_size}
                            </TableCell>
                            <TableCell className="py-2 align-middle text-sm text-muted-foreground">
                                {format(new Date(asset.created_at), 'PP')}
                            </TableCell>
                            {(can.update_asset || can.delete_asset) && (
                            <TableCell className="py-2 text-center align-middle">
                                <ActionMenu
                                    asset={asset}
                                    onViewDetails={onViewDetails}
                                    onDelete={onDelete}
                                    canUpdate={can.update_asset}
                                    canDelete={can.delete_asset}
                                />
                            </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
} 
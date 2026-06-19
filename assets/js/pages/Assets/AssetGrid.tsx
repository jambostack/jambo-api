import { useState } from 'react';
import { usePage } from '@inertiajs/react';

import { Project, Asset, UserCan } from '@/types';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

import { Checkbox } from '@/components/ui/checkbox';
import { FileImage, FileText, FileVideo, FileAudio, File, FileSpreadsheet, FileArchive, FileCode, MonitorPlay, Image } from 'lucide-react';

import AssetDetailsModal from './AssetDetailsModal';
import AssetActionMenu from './AssetActionMenu';

interface AssetGridProps {
	assets: Asset[];
	selectedAssets: number[];
	onAssetSelect: (assetId: number) => void;
	project: Project;
	onDelete: (asset: Asset) => void;
	selectOnClick?: boolean;
}

type FileCategory = 'image' | 'vector' | 'video' | 'audio' | 'pdf' | 'spreadsheet' | 'presentation' | 'archive' | 'code' | 'text' | 'other';

interface FileTypeStyle {
	chipClass: string;
	thumbBg: string;
	iconClass: string;
	Icon: React.FC<{ className?: string }>;
}

function getFileStyle(extension: string): FileTypeStyle {
	const ext = extension.toLowerCase();

	// Images
	if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'tiff', 'tif', 'heic', 'heif'].includes(ext)) {
		return {
			chipClass: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
			thumbBg: 'bg-sky-50 dark:bg-sky-950/20',
			iconClass: 'text-sky-400',
			Icon: FileImage,
		};
	}

	// Vecteurs
	if (['svg', 'eps', 'ai'].includes(ext)) {
		return {
			chipClass: 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-900/40 dark:text-fuchsia-300',
			thumbBg: 'bg-fuchsia-50 dark:bg-fuchsia-950/20',
			iconClass: 'text-fuchsia-400',
			Icon: Image,
		};
	}

	// Vidéos
	if (['mp4', 'webm', 'mov', 'avi', 'wmv', 'flv', 'mkv', 'm4v'].includes(ext)) {
		return {
			chipClass: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
			thumbBg: 'bg-violet-50 dark:bg-violet-950/20',
			iconClass: 'text-violet-400',
			Icon: FileVideo,
		};
	}

	// Audio
	if (['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a', 'wma', 'opus'].includes(ext)) {
		return {
			chipClass: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
			thumbBg: 'bg-emerald-50 dark:bg-emerald-950/20',
			iconClass: 'text-emerald-400',
			Icon: FileAudio,
		};
	}

	// PDF
	if (['pdf'].includes(ext)) {
		return {
			chipClass: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
			thumbBg: 'bg-red-50 dark:bg-red-950/20',
			iconClass: 'text-red-400',
			Icon: FileText,
		};
	}

	// Tableurs
	if (['xls', 'xlsx', 'csv', 'ods', 'numbers'].includes(ext)) {
		return {
			chipClass: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
			thumbBg: 'bg-green-50 dark:bg-green-950/20',
			iconClass: 'text-green-400',
			Icon: FileSpreadsheet,
		};
	}

	// Présentations
	if (['ppt', 'pptx', 'odp', 'key'].includes(ext)) {
		return {
			chipClass: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
			thumbBg: 'bg-orange-50 dark:bg-orange-950/20',
			iconClass: 'text-orange-400',
			Icon: MonitorPlay,
		};
	}

	// Archives
	if (['zip', 'rar', '7z', 'gz', 'tar', 'bz2', 'xz'].includes(ext)) {
		return {
			chipClass: 'bg-stone-100 text-stone-700 dark:bg-stone-800/60 dark:text-stone-300',
			thumbBg: 'bg-stone-50 dark:bg-stone-900/20',
			iconClass: 'text-stone-400',
			Icon: FileArchive,
		};
	}

	// Code / données
	if (['json', 'xml', 'yaml', 'yml', 'html', 'css', 'js', 'ts', 'jsx', 'tsx', 'php', 'py', 'sql', 'graphql'].includes(ext)) {
		return {
			chipClass: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300',
			thumbBg: 'bg-cyan-50 dark:bg-cyan-950/20',
			iconClass: 'text-cyan-400',
			Icon: FileCode,
		};
	}

	// Texte
	if (['txt', 'md', 'rtf', 'log'].includes(ext)) {
		return {
			chipClass: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
			thumbBg: 'bg-blue-50 dark:bg-blue-950/20',
			iconClass: 'text-blue-400',
			Icon: FileText,
		};
	}

	// Doc (Word)
	if (['doc', 'docx', 'odt', 'pages'].includes(ext)) {
		return {
			chipClass: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
			thumbBg: 'bg-blue-50 dark:bg-blue-950/20',
			iconClass: 'text-blue-400',
			Icon: FileText,
		};
	}

	// Fallback
	return {
		chipClass: 'bg-slate-100 text-slate-600 dark:bg-slate-800/60 dark:text-slate-300',
		thumbBg: 'bg-slate-50 dark:bg-slate-900/20',
		iconClass: 'text-slate-400',
		Icon: File,
	};
}

export default function AssetGrid({
	assets,
	selectedAssets,
	onAssetSelect,
	project,
	onDelete,
	selectOnClick = false,
}: AssetGridProps) {
	const t = useTranslation();
	const [selectedAsset, setSelectedAsset] = useState<Asset | null>(null);
	const [showDetailsModal, setShowDetailsModal] = useState(false);
	const can = usePage().props.userCan as UserCan;

	const handleViewAssetDetails = (asset: Asset) => {
		setSelectedAsset(asset);
		setShowDetailsModal(true);
	};

	const handleThumbnailClick = (asset: Asset, e: React.MouseEvent) => {
		if (selectOnClick || e.shiftKey || selectedAssets.length > 0) {
			onAssetSelect(asset.id);
		} else {
			handleViewAssetDetails(asset);
		}
	};

	if (assets.length === 0) {
		return (
			<div className="flex flex-col items-center justify-center py-20 text-center">
				<div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-muted">
					<File className="h-8 w-8 text-muted-foreground/30" />
				</div>
				<p className="text-sm font-medium text-muted-foreground">{t('assets.no_assets')}</p>
			</div>
		);
	}

	return (
		<div className="space-y-4">
			<div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
				{assets.map((asset) => {
					const styles = getFileStyle(asset.extension);
					const { Icon } = styles;
					const isSelected = selectedAssets.includes(asset.id);

					return (
						<div
							key={asset.id}
							className={cn(
								'group relative overflow-hidden rounded-xl border bg-card transition-all duration-200 cursor-pointer',
								'hover:shadow-[0_4px_20px_-4px_rgba(0,0,0,0.12)] dark:hover:shadow-[0_4px_20px_-4px_rgba(0,0,0,0.45)]',
								isSelected
									? 'ring-2 ring-primary border-primary/40 shadow-sm'
									: 'border-border/60 hover:border-border'
							)}
						>
							{/* Thumbnail */}
							<div
								className={cn('relative aspect-[4/3] overflow-hidden', styles.thumbBg)}
								onClick={(e) => handleThumbnailClick(asset, e)}
							>
								{asset.thumbnail_url ? (
									<img
										src={asset.thumbnail_url}
										alt={asset.metadata?.alt_text || asset.original_filename}
										className="h-full w-full object-cover transition-transform duration-300 ease-out group-hover:scale-[1.05]"
									/>
								) : (
									<div className="flex h-full items-center justify-center">
										<Icon className={cn('h-10 w-10 opacity-50', styles.iconClass)} />
									</div>
								)}

								{/* Hover overlay */}
								<div className="absolute inset-0 bg-black/0 transition-colors duration-200 group-hover:bg-black/8" />

								{/* Selected tint */}
								{isSelected && (
									<div className="absolute inset-0 bg-primary/10" />
								)}
							</div>

							{/* Checkbox — top-start */}
							<div
								className={cn(
									'absolute start-2 top-2 transition-all duration-150',
									isSelected || selectedAssets.length > 0
										? 'opacity-100 scale-100'
										: 'opacity-0 scale-90 group-hover:opacity-100 group-hover:scale-100'
								)}
								onClick={(e) => { e.stopPropagation(); onAssetSelect(asset.id); }}
							>
								<Checkbox
									checked={isSelected}
									className="h-5 w-5 rounded-md bg-background/90 border-border shadow backdrop-blur-sm data-[state=checked]:bg-primary data-[state=checked]:border-primary"
								/>
							</div>

							{/* Action menu — top-end */}
							<div
								className={cn(
									'absolute end-2 top-2 transition-all duration-150',
									isSelected
										? 'opacity-100'
										: 'opacity-0 group-hover:opacity-100'
								)}
								onClick={(e) => e.stopPropagation()}
							>
								<AssetActionMenu
									asset={asset}
									onViewDetails={handleViewAssetDetails}
									onDelete={onDelete}
									canUpdate={can.update_asset}
									canDelete={can.delete_asset}
								/>
							</div>

							{/* Footer */}
							<div
								className="p-2.5 space-y-1.5"
								onClick={(e) => handleThumbnailClick(asset, e)}
							>
								<p
									className="truncate text-[13px] font-medium leading-tight text-foreground"
									title={asset.original_filename}
								>
									{asset.original_filename}
								</p>
								<div className="flex items-center justify-between gap-1.5">
									<span className={cn(
										'inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold tracking-wider uppercase',
										styles.chipClass
									)}>
										{asset.extension.toUpperCase()}
									</span>
									<span className="font-mono text-[10px] text-muted-foreground tabular-nums">
										{asset.formatted_size}
									</span>
								</div>
							</div>
						</div>
					);
				})}
			</div>

			{selectedAsset && (
				<AssetDetailsModal
					isOpen={showDetailsModal}
					onClose={() => setShowDetailsModal(false)}
					project={project}
					asset={selectedAsset}
				/>
			)}
		</div>
	);
}

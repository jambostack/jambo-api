import { useState } from 'react';
import { usePage } from '@inertiajs/react';

import { Project, Asset, UserCan } from '@/types';
import { useTranslation } from '@/lib/i18n';

import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { FileImage, FileText, FileVideo, FileAudio, File } from 'lucide-react';

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
			<Card className="py-12">
				<CardContent className="flex flex-col items-center justify-center text-center">
					<File className="h-12 w-12 text-muted-foreground/50 mb-4" />
					<p className="text-muted-foreground font-medium">{t('assets.no_assets')}</p>
				</CardContent>
			</Card>
		);
	}

	const getFileIcon = (asset: Asset) => {
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
		<div className="space-y-4">
			<div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
				{assets.map((asset) => (
					<Card key={asset.id} className="overflow-hidden p-0">
						<div
							className="relative h-40 bg-muted flex items-center justify-center group cursor-pointer rounded-t-lg"
							onClick={(e) => handleThumbnailClick(asset, e)}
						>
							{asset.thumbnail_url ? (
								<img
									src={asset.thumbnail_url}
									alt={asset.metadata?.alt_text || asset.original_filename}
									className="h-full w-full object-cover rounded-t-lg"
								/>
							) : (
								getFileIcon(asset)
							)}

							<div className="absolute top-2 left-2" onClick={(e) => e.stopPropagation()}>
								<Checkbox
									checked={selectedAssets.includes(asset.id)}
									onCheckedChange={() => onAssetSelect(asset.id)}
									className="bg-background border-input shadow-sm h-8 w-8 rounded-md"
								/>
							</div>

							<div className="absolute top-2 right-2" onClick={(e) => e.stopPropagation()}>
								<TooltipProvider>
									<Tooltip>
										<TooltipTrigger asChild>
											<AssetActionMenu
												asset={asset}
												onViewDetails={handleViewAssetDetails}
												onDelete={onDelete}
												canUpdate={can.update_asset}
												canDelete={can.delete_asset}
											/>
										</TooltipTrigger>
										<TooltipContent>{t('assets.grid_options')}</TooltipContent>
									</Tooltip>
								</TooltipProvider>
							</div>
						</div>

						<CardContent>
							<TooltipProvider>
								<Tooltip>
									<TooltipTrigger asChild>
										<div
											className="truncate font-medium text-sm cursor-pointer"
											title={asset.original_filename}
											onClick={(e) => handleThumbnailClick(asset, e)}
										>
											{asset.original_filename}
										</div>
									</TooltipTrigger>
									<TooltipContent>{asset.original_filename}</TooltipContent>
								</Tooltip>
							</TooltipProvider>
						</CardContent>
						<CardFooter className="px-3 py-2 border-t flex justify-between text-xs text-muted-foreground">
							<Badge variant="outline" className="h-5 text-xs">
								{getFileIcon(asset)}
								{asset.extension.toUpperCase()}
							</Badge>
							<span>{asset.formatted_size}</span>
						</CardFooter>
					</Card>
				))}
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
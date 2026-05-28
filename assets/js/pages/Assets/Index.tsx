import { useState, useEffect } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

import { Project, BreadcrumbItem, Asset, UserCan } from '@/types';

import AppLayout from '@/layouts/app-layout';
import AssetGrid from '@/pages/Assets/AssetGrid';
import AssetUploader from '@/pages/Assets/AssetUploader';
import AssetTable from '@/pages/Assets/AssetTable';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Plus, Trash, Calendar, LayoutGrid, List, ArrowUpDown, X, Download } from 'lucide-react';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuSeparator, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuLabel } from '@/components/ui/dropdown-menu';
import { DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Pagination, PaginationContent, PaginationEllipsis, PaginationItem, PaginationLink, PaginationNext, PaginationPrevious } from "@/components/ui/pagination";
import { SearchBar } from '@/components/ui/search-bar';

import ProjectSidebar from '../Projects/ProjectSidebar';
import AssetDetailsModal from './AssetDetailsModal';

interface Props {
	project: Project;
	assets: {
		data: Asset[];
		current_page: number;
		last_page: number;
		total: number;
		per_page: number;
		from: number;
		to: number;
	};
	filters: {
		search?: string;
		type?: string;
		date_filter?: string;
		date_from?: string;
		date_to?: string;
		sort?: string;
		per_page?: string;
	};
}

const DEFAULT_SORT = 'newest';

const TYPE_OPTIONS = [
	{ value: 'all',      labelKey: 'assets.type_all'       },
	{ value: 'image',    labelKey: 'assets.type_images'    },
	{ value: 'video',    labelKey: 'assets.type_videos'    },
	{ value: 'audio',    labelKey: 'assets.type_audio'     },
	{ value: 'document', labelKey: 'assets.type_documents' },
] as const;

export default function Index({ project, assets, filters }: Props) {
	const t = useTranslation();
	const [showUploader, setShowUploader] = useState(false);
	const [selectedAssets, setSelectedAssets] = useState<number[]>([]);
	const [search, setSearch] = useState('');
	const [assetType, setAssetType] = useState('all');
	const [dateFilter, setDateFilter] = useState('');
	const [showBulkDeleteDialog, setShowBulkDeleteDialog] = useState(false);
	const [assetToDelete, setAssetToDelete] = useState<Asset | null>(null);
	const [viewMode, setViewMode] = useState('grid');
	const [sortOption, setSortOption] = useState(DEFAULT_SORT);
	const [selectedAssetForModal, setSelectedAssetForModal] = useState<Asset | null>(null);
	const [showDetailsModal, setShowDetailsModal] = useState(false);
	const [assetsState, setAssets] = useState(assets);
	const page = usePage<{ can?: Record<string, boolean> }>();
	const can = page.props.userCan as UserCan;

	useEffect(() => {
		if (filters) {
			if (typeof filters.search === 'string') setSearch(filters.search);
			if (typeof filters.type === 'string') setAssetType(filters.type || 'all');
			if (typeof filters.date_filter === 'string') setDateFilter(filters.date_filter);
			if (typeof filters.sort === 'string') setSortOption(filters.sort);
		}

		try {
			const savedViewMode = localStorage.getItem('assetViewMode');
			if (savedViewMode === 'grid' || savedViewMode === 'list') {
				setViewMode(savedViewMode);
			}
		} catch (e) {
			console.error('Error loading view mode from localStorage:', e);
		}

		setAssets(assets);
	}, [filters, assets]);

	const handleViewModeChange = (value: string) => {
		setViewMode(value);
		localStorage.setItem('assetViewMode', value);
	};

	const applyFilters = (updates: {
		search?: string;
		type?: string;
		dateFilter?: string;
		sort?: string;
		perPage?: string;
	}) => {
		if (updates.search !== undefined) setSearch(updates.search);
		if (updates.type !== undefined) setAssetType(updates.type);
		if (updates.dateFilter !== undefined) setDateFilter(updates.dateFilter);
		if (updates.sort !== undefined) setSortOption(updates.sort);

		const queryParams = {
			search: updates.search ?? search,
			type: (updates.type ?? assetType) === 'all' ? '' : (updates.type ?? assetType),
			date_filter: updates.dateFilter ?? dateFilter,
			sort: updates.sort ?? sortOption,
			per_page: updates.perPage ?? filters.per_page,
		};

		setSelectedAssets([]);

		router.get(route('assets.index', project.id), queryParams, {
			preserveState: true,
			replace: true,
		});
	};

	const handleSearchChange = (value: string) => {
		setSearch(value);
		const timeoutId = setTimeout(() => {
			applyFilters({ search: value });
		}, 500);
		return () => clearTimeout(timeoutId);
	};

	const handleTypeChange = (value: string) => {
		applyFilters({ type: value });
	};

	const handleDateFilterChange = (value: string) => {
		applyFilters({ dateFilter: value });
	};

	const handleSortChange = (value: string) => {
		applyFilters({ sort: value });
	};

	const handlePageChange = (page: number) => {
		setSelectedAssets([]);

		router.get(route('assets.index', project.id), {
			page,
			search,
			type: assetType === 'all' ? '' : assetType,
		}, {
			preserveState: true,
		});
	};

	const handleAssetSelect = (assetId: number) => {
		if (!assetId) return;

		if (selectedAssets.includes(assetId)) {
			setSelectedAssets(selectedAssets.filter(id => id !== assetId));
		} else {
			setSelectedAssets([...selectedAssets, assetId]);
		}
	};

	const handleSelectAll = () => {
		if (!assets?.data) return;

		if (selectedAssets.length === assets.data.length) {
			setSelectedAssets([]);
		} else {
			setSelectedAssets(assets.data.map(asset => asset.id));
		}
	};

	const handleBulkDownload = () => {
		const selected = assets.data.filter(a => selectedAssets.includes(a.id));
		selected.forEach((asset, index) => {
			setTimeout(() => {
				const link = document.createElement('a');
				link.href = asset.url ?? '';
				link.download = asset.original_filename ?? '';
				document.body.appendChild(link);
				link.click();
				document.body.removeChild(link);
			}, index * 300);
		});
	};

	const handleBulkDelete = async () => {
		if (selectedAssets.length === 0) return;
		try {
			await axios.delete(`/api/projects/${project.uuid}/media/bulk-destroy`, {
				data: { asset_ids: selectedAssets },
			});
			const count = selectedAssets.length;
			setSelectedAssets([]);
			setShowBulkDeleteDialog(false);
			toast.success(t('assets.deleted_success', { count: String(count) }));
			applyFilters({ search, type: assetType, dateFilter });
		} catch {
			toast.error(t('assets.delete_error'));
		}
	};

	const handleDeleteAsset = (asset: Asset) => {
		setAssetToDelete(asset);
	};

	const confirmDeleteAsset = async () => {
		if (!assetToDelete) return;
		try {
			await axios.delete(`/api/projects/${project.uuid}/media/${assetToDelete.uuid}`);
			toast.success(t('assets.delete_asset_success'));
			setAssetToDelete(null);
			applyFilters({ search, type: assetType, dateFilter });
		} catch {
			toast.error(t('assets.delete_asset_error'));
			setAssetToDelete(null);
		}
	};

	const handleViewAssetDetails = (asset: Asset) => {
		setSelectedAssetForModal(asset);
		setShowDetailsModal(true);
	};

	const handleClearSelection = () => {
		setSelectedAssets([]);
	};

	const breadcrumbs: BreadcrumbItem[] = [
		{
			title: project.name,
			href: route('projects.show', project.id),
		},
		{
			title: t('assets.breadcrumb'),
			href: route('assets.index', project.id),
		}
	];

	const getDateFilterLabel = () => {
		if (dateFilter === 'today') return t('assets.date_today');
		if (dateFilter === 'week') return t('assets.date_week');
		if (dateFilter === 'month') return t('assets.date_month');
		if (dateFilter === 'quarter') return t('assets.date_quarter');
		return t('assets.date_filter_btn');
	};

	const getSortLabel = () => {
		if (sortOption === 'newest') return t('assets.sort_newest');
		if (sortOption === 'oldest') return t('assets.sort_oldest');
		if (sortOption === 'name_asc') return t('assets.sort_name_asc');
		if (sortOption === 'name_desc') return t('assets.sort_name_desc');
		if (sortOption === 'size_asc') return t('assets.sort_size_asc');
		if (sortOption === 'size_desc') return t('assets.sort_size_desc');
		return t('assets.sort_by');
	};

	return (
		<AppLayout breadcrumbs={breadcrumbs}>
			<Head title={t('assets.title')} />

			<div className="flex gap-6 rtl:space-x-reverse">
				<ProjectSidebar project={project} />

				<Separator className="my-6 md:hidden" />

				<div className="flex-1 min-w-0 space-y-5">

					{/* ── Header ───────────────────────────────────── */}
					<div className="flex items-center justify-between gap-4">
						<div className="flex items-center gap-2.5">
							<h2 className="text-xl font-semibold tracking-tight">{t('assets.title')}</h2>
							{assetsState.total > 0 && (
								<span className="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground tabular-nums">
									{assetsState.total}
								</span>
							)}
						</div>
						{can.upload_asset && (
							<Button onClick={() => setShowUploader(true)} size="sm" className="gap-2 shrink-0">
								<Plus className="h-3.5 w-3.5" />
								{t('assets.upload_btn')}
							</Button>
						)}
					</div>

					{/* ── Controls toolbar ─────────────────────────── */}
					<div className="flex flex-wrap items-center gap-2">
						{/* Select-all checkbox */}
						{can.delete_asset && (
							<div className="flex h-9 items-center rounded-lg border border-border/70 px-2.5">
								<Checkbox
									checked={selectedAssets.length > 0 && selectedAssets.length === assets.data.length}
									onCheckedChange={handleSelectAll}
									id="select-all"
									className="h-4 w-4"
								/>
							</div>
						)}

						{/* Search */}
						<div className="relative min-w-[180px] flex-1">
							<SearchBar
								value={search}
								onChange={handleSearchChange}
								placeholder={t('assets.search_ph')}
								className="w-full"
							/>
						</div>

						{/* Type filter pills */}
						<div className="inline-flex items-center gap-0.5 rounded-lg bg-muted p-1 flex-shrink-0">
							{TYPE_OPTIONS.map((opt) => (
								<button
									key={opt.value}
									type="button"
									onClick={() => handleTypeChange(opt.value)}
									className={cn(
										'inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium transition-all whitespace-nowrap',
										assetType === opt.value
											? 'bg-background text-foreground shadow-sm ring-1 ring-border/40'
											: 'text-muted-foreground hover:text-foreground hover:bg-background/50'
									)}
								>
									{t(opt.labelKey)}
								</button>
							))}
						</div>

						{/* Sort + Date grouped control */}
						<div className="flex items-center divide-x divide-border/60 rounded-lg border border-border/70 overflow-hidden flex-shrink-0">
							<DropdownMenu>
								<DropdownMenuTrigger asChild>
									<Button
										variant="ghost"
										size="sm"
										className="rounded-none h-9 gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground border-0 px-3"
									>
										<ArrowUpDown className="h-3 w-3" />
										{getSortLabel()}
									</Button>
								</DropdownMenuTrigger>
								<DropdownMenuContent className="w-40">
									<DropdownMenuLabel>{t('assets.sort_by')}</DropdownMenuLabel>
									<DropdownMenuSeparator />
									<DropdownMenuRadioGroup value={sortOption} onValueChange={handleSortChange}>
										<DropdownMenuRadioItem value="newest">{t('assets.sort_newest')}</DropdownMenuRadioItem>
										<DropdownMenuRadioItem value="oldest">{t('assets.sort_oldest')}</DropdownMenuRadioItem>
										<DropdownMenuRadioItem value="name_asc">{t('assets.sort_name_asc')}</DropdownMenuRadioItem>
										<DropdownMenuRadioItem value="name_desc">{t('assets.sort_name_desc')}</DropdownMenuRadioItem>
										<DropdownMenuRadioItem value="size_asc">{t('assets.sort_size_asc')}</DropdownMenuRadioItem>
										<DropdownMenuRadioItem value="size_desc">{t('assets.sort_size_desc')}</DropdownMenuRadioItem>
									</DropdownMenuRadioGroup>
								</DropdownMenuContent>
							</DropdownMenu>

							<DropdownMenu>
								<DropdownMenuTrigger asChild>
									<Button
										variant="ghost"
										size="sm"
										className="rounded-none h-9 gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground border-0 px-3"
									>
										<Calendar className="h-3 w-3" />
										{getDateFilterLabel()}
									</Button>
								</DropdownMenuTrigger>
								<DropdownMenuContent className="w-40">
									<DropdownMenuLabel>{t('assets.filter_date')}</DropdownMenuLabel>
									<DropdownMenuSeparator />
									<DropdownMenuRadioGroup value={dateFilter} onValueChange={handleDateFilterChange}>
										<DropdownMenuRadioItem value="">{t('assets.date_all')}</DropdownMenuRadioItem>
										<DropdownMenuRadioItem value="today">{t('assets.date_today')}</DropdownMenuRadioItem>
										<DropdownMenuRadioItem value="week">{t('assets.date_week')}</DropdownMenuRadioItem>
										<DropdownMenuRadioItem value="month">{t('assets.date_month')}</DropdownMenuRadioItem>
										<DropdownMenuRadioItem value="quarter">{t('assets.date_quarter')}</DropdownMenuRadioItem>
									</DropdownMenuRadioGroup>
								</DropdownMenuContent>
							</DropdownMenu>
						</div>

						{/* View toggle */}
						<div className="flex items-center divide-x divide-border/60 rounded-lg border border-border/70 overflow-hidden flex-shrink-0">
							<button
								type="button"
								onClick={() => handleViewModeChange('grid')}
								aria-label="Grid View"
								className={cn(
									'flex h-9 w-9 items-center justify-center transition-colors',
									viewMode === 'grid'
										? 'bg-muted text-foreground'
										: 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
								)}
							>
								<LayoutGrid className="h-3.5 w-3.5" />
							</button>
							<button
								type="button"
								onClick={() => handleViewModeChange('list')}
								aria-label="List View"
								className={cn(
									'flex h-9 w-9 items-center justify-center transition-colors',
									viewMode === 'list'
										? 'bg-muted text-foreground'
										: 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
								)}
							>
								<List className="h-3.5 w-3.5" />
							</button>
						</div>
					</div>

					{/* ── Selection action bar ─────────────────────── */}
					{selectedAssets.length > 0 && (
						<div className="flex items-center justify-between gap-4 rounded-lg border border-primary/20 bg-primary/5 px-4 py-2.5">
							<div className="flex items-center gap-3">
								<span className="text-sm font-medium">
									{t('assets.selected', { count: String(selectedAssets.length) })}
								</span>
								<Button
									variant="ghost"
									size="sm"
									className="h-7 gap-1 text-xs text-muted-foreground hover:text-foreground"
									onClick={handleClearSelection}
								>
									<X className="h-3 w-3" />
									{t('assets.clear_selection')}
								</Button>
							</div>
							<div className="flex items-center gap-2">
								<Button
									variant="outline"
									size="sm"
									className="h-7 gap-1.5 text-xs"
									onClick={handleBulkDownload}
								>
									<Download className="h-3.5 w-3.5" />
									{t('assets.download_selected')}
								</Button>
								{can.delete_asset && (
									<Button
										variant="outline"
										size="sm"
										className="h-7 gap-1.5 text-xs text-destructive hover:text-destructive border-destructive/30 hover:bg-destructive/5"
										onClick={() => setShowBulkDeleteDialog(true)}
									>
										<Trash className="h-3.5 w-3.5" />
										{t('assets.delete_selected')}
									</Button>
								)}
							</div>
						</div>
					)}

					{/* ── Content ──────────────────────────────────── */}
					{viewMode === 'grid' ? (
						assetsState.data.length > 0 ? (
							<AssetGrid
								assets={assetsState.data}
								selectedAssets={selectedAssets}
								onAssetSelect={handleAssetSelect}
								project={project}
								onDelete={handleDeleteAsset}
							/>
						) : (
							<div className="flex flex-col items-center justify-center py-24 text-center">
								<div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-muted">
									<LayoutGrid className="h-8 w-8 text-muted-foreground/30" />
								</div>
								<p className="text-sm font-medium text-muted-foreground">{t('assets.no_assets')}</p>
								{can.upload_asset && (
									<Button size="sm" variant="outline" className="mt-4 gap-2" onClick={() => setShowUploader(true)}>
										<Plus className="h-3.5 w-3.5" />
										{t('assets.upload_btn')}
									</Button>
								)}
							</div>
						)
					) : (
						assetsState.data.length > 0 ? (
							<AssetTable
								assets={assetsState.data}
								selectedAssets={selectedAssets}
								onAssetSelect={handleAssetSelect}
								onViewDetails={handleViewAssetDetails}
								onDelete={handleDeleteAsset}
							/>
						) : (
							<div className="flex flex-col items-center justify-center py-24 text-center">
								<div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-muted">
									<List className="h-8 w-8 text-muted-foreground/30" />
								</div>
								<p className="text-sm font-medium text-muted-foreground">{t('assets.no_assets')}</p>
							</div>
						)
					)}

					{/* ── Pagination ───────────────────────────────── */}
					{(assetsState.last_page > 1 || assetsState.total > 0) && (
						<div className="flex items-center justify-between gap-4 pt-2">
							{assetsState.last_page > 1 && (
								<Pagination className="justify-start">
									<PaginationContent>
										<PaginationItem>
											<PaginationPrevious
												href="#"
												onClick={(e) => { e.preventDefault(); handlePageChange(assetsState.current_page - 1); }}
												aria-disabled={assetsState.current_page === 1}
												className={assetsState.current_page === 1 ? 'pointer-events-none opacity-50' : ''}
											/>
										</PaginationItem>

										<PaginationItem>
											<PaginationLink href="#" onClick={(e) => { e.preventDefault(); handlePageChange(1); }} isActive={assetsState.current_page === 1}>
												1
											</PaginationLink>
										</PaginationItem>

										{assetsState.current_page > 3 && (
											<PaginationItem><PaginationEllipsis /></PaginationItem>
										)}

										{assetsState.current_page > 2 && (
											<PaginationItem>
												<PaginationLink href="#" onClick={(e) => { e.preventDefault(); handlePageChange(assetsState.current_page - 1); }}>
													{assetsState.current_page - 1}
												</PaginationLink>
											</PaginationItem>
										)}

										{assetsState.current_page !== 1 && assetsState.current_page !== assetsState.last_page && (
											<PaginationItem>
												<PaginationLink href="#" onClick={(e) => { e.preventDefault(); handlePageChange(assetsState.current_page); }} isActive>
													{assetsState.current_page}
												</PaginationLink>
											</PaginationItem>
										)}

										{assetsState.current_page < assetsState.last_page - 1 && (
											<PaginationItem>
												<PaginationLink href="#" onClick={(e) => { e.preventDefault(); handlePageChange(assetsState.current_page + 1); }}>
													{assetsState.current_page + 1}
												</PaginationLink>
											</PaginationItem>
										)}

										{assetsState.current_page < assetsState.last_page - 2 && (
											<PaginationItem><PaginationEllipsis /></PaginationItem>
										)}

										{assetsState.last_page > 1 && (
											<PaginationItem>
												<PaginationLink href="#" onClick={(e) => { e.preventDefault(); handlePageChange(assetsState.last_page); }} isActive={assetsState.current_page === assetsState.last_page}>
													{assetsState.last_page}
												</PaginationLink>
											</PaginationItem>
										)}

										<PaginationItem>
											<PaginationNext
												href="#"
												onClick={(e) => { e.preventDefault(); handlePageChange(assetsState.current_page + 1); }}
												aria-disabled={assetsState.current_page === assetsState.last_page}
												className={assetsState.current_page === assetsState.last_page ? 'pointer-events-none opacity-50' : ''}
											/>
										</PaginationItem>
									</PaginationContent>
								</Pagination>
							)}

							{assetsState.total > 0 && (
								<div className="flex items-center gap-3 ms-auto text-sm text-muted-foreground">
									<span>
										{t('assets.showing', {
											from: String(assetsState.from),
											to: String(assetsState.to),
											total: String(assetsState.total),
										})}
									</span>
									<div className="flex items-center gap-2">
										<span className="text-xs">{t('assets.per_page')}</span>
										<Select
											value={(filters.per_page || '10').toString()}
											onValueChange={(value) => {
												setSelectedAssets([]);
												router.get(route('assets.index', project.id), {
													search,
													type: assetType === 'all' ? '' : assetType,
													date_filter: dateFilter,
													sort: sortOption,
													per_page: value,
												}, { preserveState: true, replace: true });
											}}
										>
											<SelectTrigger className="h-7 w-[72px] text-xs">
												<SelectValue />
											</SelectTrigger>
											<SelectContent>
												<SelectItem value="10">10</SelectItem>
												<SelectItem value="25">25</SelectItem>
												<SelectItem value="50">50</SelectItem>
												<SelectItem value="100">100</SelectItem>
											</SelectContent>
										</Select>
									</div>
								</div>
							)}
						</div>
					)}

				</div>
			</div>

			{/* ── Uploader dialog ──────────────────────────────── */}
			<AssetUploader
				isOpen={showUploader}
				onClose={() => setShowUploader(false)}
				projectId={project.id}
				projectUuid={project.uuid}
				onUploadComplete={() => {
					applyFilters({ search, type: assetType, dateFilter });
				}}
			/>

			{/* ── Bulk delete confirmation ─────────────────────── */}
			<AlertDialog open={showBulkDeleteDialog} onOpenChange={setShowBulkDeleteDialog}>
				<AlertDialogContent>
					<AlertDialogHeader>
						<AlertDialogTitle>{t('assets.delete_bulk_title')}</AlertDialogTitle>
						<AlertDialogDescription>
							{t('assets.delete_bulk_desc', { count: String(selectedAssets.length) })}
						</AlertDialogDescription>
					</AlertDialogHeader>
					<AlertDialogFooter>
						<AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
						<AlertDialogAction onClick={handleBulkDelete} className="bg-destructive text-destructive-foreground hover:bg-destructive/90 text-white">
							{t('common.delete')}
						</AlertDialogAction>
					</AlertDialogFooter>
				</AlertDialogContent>
			</AlertDialog>

			{/* ── Single asset delete confirmation ─────────────── */}
			<AlertDialog open={!!assetToDelete} onOpenChange={(open) => !open && setAssetToDelete(null)}>
				<AlertDialogContent>
					<AlertDialogHeader>
						<AlertDialogTitle>{t('assets.delete_single_title')}</AlertDialogTitle>
						<AlertDialogDescription>
							{t('assets.delete_single_desc', { filename: assetToDelete?.original_filename ?? '' })}
						</AlertDialogDescription>
					</AlertDialogHeader>
					<AlertDialogFooter>
						<AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
						<AlertDialogAction onClick={confirmDeleteAsset} className="bg-destructive text-destructive-foreground hover:bg-destructive/90 text-white">
							{t('common.delete')}
						</AlertDialogAction>
					</AlertDialogFooter>
				</AlertDialogContent>
			</AlertDialog>

			{/* ── Asset details modal (list view) ──────────────── */}
			{selectedAssetForModal && (
				<AssetDetailsModal
					isOpen={showDetailsModal}
					onClose={() => setShowDetailsModal(false)}
					project={project}
					asset={selectedAssetForModal}
				/>
			)}
		</AppLayout>
	);
}

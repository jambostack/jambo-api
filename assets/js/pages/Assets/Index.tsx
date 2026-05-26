import { useState, useEffect } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import { useTranslation } from '@/lib/i18n';

import { Project, BreadcrumbItem, Asset, UserCan } from '@/types';

import AppLayout from '@/layouts/app-layout';
import AssetGrid from '@/pages/Assets/AssetGrid';
import AssetUploader from '@/pages/Assets/AssetUploader';
import AssetTable from '@/pages/Assets/AssetTable';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Plus, Trash, Filter, Calendar, LayoutGrid, List, ArrowUpDown, X, Download, ChevronDown } from 'lucide-react';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuLabel } from '@/components/ui/dropdown-menu';
import { Pagination, PaginationContent, PaginationEllipsis, PaginationItem, PaginationLink, PaginationNext, PaginationPrevious } from "@/components/ui/pagination";
import { SearchBar } from '@/components/ui/search-bar';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

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

	// Set all state values after component is mounted
	useEffect(() => {
		if (filters) {
			// Set values only if they exist in filters
			if (typeof filters.search === 'string') setSearch(filters.search);
			if (typeof filters.type === 'string') setAssetType(filters.type);
			if (typeof filters.date_filter === 'string') setDateFilter(filters.date_filter);
			if (typeof filters.sort === 'string') setSortOption(filters.sort);
		}

		// Load view mode from localStorage
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

	// Save view mode to localStorage when it changes
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

		// Prepare query parameters
		const queryParams = {
			search: updates.search ?? search,
			type: (updates.type ?? assetType) === 'all' ? '' : (updates.type ?? assetType),
			date_filter: updates.dateFilter ?? dateFilter,
			sort: updates.sort ?? sortOption,
			per_page: updates.perPage ?? filters.per_page,
		};

		// Clear selected assets when filters change
		setSelectedAssets([]);

		// Apply filters
		router.get(route('assets.index', project.id), queryParams, {
			preserveState: true,
			replace: true,
		});
	};

	const handleSearchChange = (value: string) => {
		setSearch(value);
		// Submit search after a short delay
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
		// Clear selected assets when changing pages
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

	// Display formatted date filter for the button
	const getDateFilterDisplay = () => {
		if (dateFilter === 'today') return t('assets.date_today');
		if (dateFilter === 'week') return t('assets.date_week');
		if (dateFilter === 'month') return t('assets.date_month');
		if (dateFilter === 'quarter') return t('assets.date_quarter');
		return t('assets.date_filter_btn');
	};

	const handleViewAssetDetails = (asset: Asset) => {
		setSelectedAssetForModal(asset);
		setShowDetailsModal(true);
	};

	const handleClearSelection = () => {
		setSelectedAssets([]);
	};

	return (
		<AppLayout breadcrumbs={breadcrumbs}>
			<Head title={t('assets.title')} />

			<div className="flex space-x-6 rtl:space-x-reverse">
				<ProjectSidebar project={project} />

				<Separator className="my-6 md:hidden" />

				<div className="flex-1 w-full">
					<section className="space-y-6">
						<div>
							<Tabs defaultValue="grid" value={viewMode} onValueChange={handleViewModeChange}>
								<div className="flex justify-between items-center space-x-4 mb-2">
									<h3 className="text-lg font-semibold">{t('assets.title')}</h3>
									{can.upload_asset && (
										<Button onClick={() => setShowUploader(true)}>
											<Plus className="h-4 w-4 mr-2 rtl:ml-2 rtl:mr-0" />
											{t('assets.upload_btn')}
										</Button>
									)}
								</div>

								<div className="flex items-center gap-4 mb-2">
									{can.delete_asset && (
										<div className="flex items-center space-x-2 border p-2 rounded-md">
											<Checkbox
												checked={selectedAssets.length > 0 && selectedAssets.length === assets.data.length}
												onCheckedChange={handleSelectAll}
												id="select-all"
												className="h-5 w-5"
											/>
										</div>
									)}
									<div className="relative flex-1">
										<SearchBar
											value={search}
											onChange={handleSearchChange}
											placeholder={t('assets.search_ph')}
											className="w-full"
										/>
									</div>

									<Select value={assetType} onValueChange={handleTypeChange}>
										<SelectTrigger className="w-[180px]">
											<div className="flex items-center gap-2">
												<Filter className="h-4 w-4" />
												<SelectValue placeholder={t('assets.filter_type')} />
											</div>
										</SelectTrigger>
										<SelectContent>
											<SelectItem value="all">{t('assets.type_all')}</SelectItem>
											<SelectItem value="image">{t('assets.type_images')}</SelectItem>
											<SelectItem value="video">{t('assets.type_videos')}</SelectItem>
											<SelectItem value="audio">{t('assets.type_audio')}</SelectItem>
											<SelectItem value="document">{t('assets.type_documents')}</SelectItem>
										</SelectContent>
									</Select>

									<DropdownMenu>
										<DropdownMenuTrigger asChild>
											<Button variant="outline" className="flex items-center gap-2">
												<Calendar className="h-4 w-4" />
												{getDateFilterDisplay()}
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

									<DropdownMenu>
										<DropdownMenuTrigger asChild>
											<Button variant="outline" className="flex items-center gap-2">
												<ArrowUpDown className="h-4 w-4" />
												{sortOption === 'newest' && t('assets.sort_newest')}
												{sortOption === 'oldest' && t('assets.sort_oldest')}
												{sortOption === 'name_asc' && t('assets.sort_name_asc')}
												{sortOption === 'name_desc' && t('assets.sort_name_desc')}
												{sortOption === 'size_asc' && t('assets.sort_size_asc')}
												{sortOption === 'size_desc' && t('assets.sort_size_desc')}
												{!sortOption && t('assets.sort_by')}
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

									<div className="flex items-center space-x-4">
										<TabsList>
											<TabsTrigger value="grid" aria-label="Grid View">
												<LayoutGrid className="h-4 w-4" />
											</TabsTrigger>
											<TabsTrigger value="list" aria-label="List View">
												<List className="h-4 w-4" />
											</TabsTrigger>
										</TabsList>

									</div>

									{selectedAssets.length > 0 && (
										<div className="flex items-center space-x-2">
											<span className="text-sm font-medium">
												{t('assets.selected', { count: String(selectedAssets.length) })}
											</span>
											<TooltipProvider>
												<Tooltip>
													<TooltipTrigger asChild>
														<Button
															variant="ghost"
															size="icon"
															onClick={handleClearSelection}
														>
															<X className="h-4 w-4" />
														</Button>
													</TooltipTrigger>
													<TooltipContent>{t('assets.clear_selection')}</TooltipContent>
												</Tooltip>
											</TooltipProvider>
											<DropdownMenu>
												<DropdownMenuTrigger asChild>
													<Button variant="outline" size="sm">
														{t('assets.bulk_actions')}
														<ChevronDown className="h-4 w-4 ml-1 rtl:mr-1 rtl:ml-0" />
													</Button>
												</DropdownMenuTrigger>
												<DropdownMenuContent align="end">
													<DropdownMenuLabel>
														{t('assets.asset_selected', { count: String(selectedAssets.length) })}
													</DropdownMenuLabel>
													<DropdownMenuSeparator />
													<DropdownMenuItem onClick={handleBulkDownload}>
														<Download className="h-4 w-4 mr-2 rtl:ml-2 rtl:mr-0" />
														{t('assets.download_selected')}
													</DropdownMenuItem>
													{can.delete_asset && (
														<>
															<DropdownMenuSeparator />
															<DropdownMenuItem
																className="text-destructive focus:text-destructive"
																onClick={() => setShowBulkDeleteDialog(true)}
															>
																<Trash className="h-4 w-4 mr-2 rtl:ml-2 rtl:mr-0" />
																{t('assets.delete_selected')}
															</DropdownMenuItem>
														</>
													)}
												</DropdownMenuContent>
											</DropdownMenu>
										</div>
									)}
								</div>

								<TabsContent value="grid">
									{assetsState.data.length > 0 ? (
										<AssetGrid
											assets={assetsState.data}
											selectedAssets={selectedAssets}
											onAssetSelect={handleAssetSelect}
											project={project}
											onDelete={handleDeleteAsset}
										/>
									) : (
										<div className="text-center py-12 text-muted-foreground">
											{t('assets.no_assets')}
										</div>
									)}
								</TabsContent>

								<TabsContent value="list">
									{assetsState.data.length > 0 ? (
										<AssetTable
											assets={assetsState.data}
											selectedAssets={selectedAssets}
											onAssetSelect={handleAssetSelect}
											onViewDetails={handleViewAssetDetails}
											onDelete={handleDeleteAsset}
										/>
									) : (
										<div className="text-center py-12 text-muted-foreground">
											{t('assets.no_assets')}
										</div>
									)}
								</TabsContent>
							</Tabs>

							{/* Pagination section */}
							<div className="flex justify-between mt-4">
								<div className="pb-1 w-1/2">
									{assetsState && assetsState.last_page > 1 && (
										<Pagination className="justify-start">
											<PaginationContent>
												<PaginationItem>
													<PaginationPrevious
														href="#"
														onClick={(e) => {
															e.preventDefault();
															handlePageChange(assetsState.current_page - 1);
														}}
														aria-disabled={assetsState.current_page === 1}
														className={assetsState.current_page === 1 ? "pointer-events-none opacity-50" : ""}
													/>
												</PaginationItem>

												{/* First page */}
												<PaginationItem>
													<PaginationLink
														href="#"
														onClick={(e) => {
															e.preventDefault();
															handlePageChange(1);
														}}
														isActive={assetsState.current_page === 1}
													>
														1
													</PaginationLink>
												</PaginationItem>

												{/* If there are many pages, show ellipsis after first page */}
												{assetsState.current_page > 3 && (
													<PaginationItem>
														<PaginationEllipsis />
													</PaginationItem>
												)}

												{/* Page before current if not first page or adjacent to first */}
												{assetsState.current_page > 2 && (
													<PaginationItem>
														<PaginationLink
															href="#"
															onClick={(e) => {
																e.preventDefault();
																handlePageChange(assetsState.current_page - 1);
															}}
														>
															{assetsState.current_page - 1}
														</PaginationLink>
													</PaginationItem>
												)}

												{/* Current page if not first or last */}
												{assetsState.current_page !== 1 && assetsState.current_page !== assetsState.last_page && (
													<PaginationItem>
														<PaginationLink
															href="#"
															onClick={(e) => {
																e.preventDefault();
																handlePageChange(assetsState.current_page);
															}}
															isActive
														>
															{assetsState.current_page}
														</PaginationLink>
													</PaginationItem>
												)}

												{/* Page after current if not last page or adjacent to last */}
												{assetsState.current_page < assetsState.last_page - 1 && (
													<PaginationItem>
														<PaginationLink
															href="#"
															onClick={(e) => {
																e.preventDefault();
																handlePageChange(assetsState.current_page + 1);
															}}
														>
															{assetsState.current_page + 1}
														</PaginationLink>
													</PaginationItem>
												)}

												{/* If there are many pages, show ellipsis before last page */}
												{assetsState.current_page < assetsState.last_page - 2 && (
													<PaginationItem>
														<PaginationEllipsis />
													</PaginationItem>
												)}

												{/* Last page if not the same as first page */}
												{assetsState.last_page > 1 && (
													<PaginationItem>
														<PaginationLink
															href="#"
															onClick={(e) => {
																e.preventDefault();
																handlePageChange(assetsState.last_page);
															}}
															isActive={assetsState.current_page === assetsState.last_page}
														>
															{assetsState.last_page}
														</PaginationLink>
													</PaginationItem>
												)}

												<PaginationItem>
													<PaginationNext
														href="#"
														onClick={(e) => {
															e.preventDefault();
															handlePageChange(assetsState.current_page + 1);
														}}
														aria-disabled={assetsState.current_page === assetsState.last_page}
														className={assetsState.current_page === assetsState.last_page ? "pointer-events-none opacity-50" : ""}
													/>
												</PaginationItem>
											</PaginationContent>
										</Pagination>
									)}
								</div>
								{assetsState.total > 0 && (
									<div className="flex justify-end items-center mb-4 px-2 w-1/2">
										<div className="text-sm text-muted-foreground mr-4 rtl:ml-4 rtl:mr-0">
											{t('assets.showing', {
												from: String(assetsState.from),
												to: String(assetsState.to),
												total: String(assetsState.total),
											})}
										</div>

										<div className="flex items-center">
											<span className="text-sm text-muted-foreground mr-2 rtl:ml-2 rtl:mr-0">{t('assets.per_page')}</span>
											<Select
												value={(filters.per_page || "10").toString()}
												onValueChange={(value) => {
													// Clear selected assets when changing page size
													setSelectedAssets([]);

													router.get(route('assets.index', project.id), {
														search,
														type: assetType === 'all' ? '' : assetType,
														date_filter: dateFilter,
														sort: sortOption,
														per_page: value,
													}, {
														preserveState: true,
														replace: true,
													});
												}}
											>
												<SelectTrigger className="w-[80px] h-8">
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

						</div>
					</section>
				</div>
			</div>

			<AssetUploader
				isOpen={showUploader}
				onClose={() => setShowUploader(false)}
				projectId={project.id}
				projectUuid={project.uuid}
				onUploadComplete={() => {
					applyFilters({
						search: search,
						type: assetType,
						dateFilter: dateFilter,
					});
				}}
			/>

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
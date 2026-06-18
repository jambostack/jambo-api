import { useState, useEffect } from 'react';
import { toast } from 'sonner';
import axios from 'axios';
import { useTranslation } from '@/lib/i18n';

import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger, DropdownMenuRadioGroup, DropdownMenuRadioItem } from '@/components/ui/dropdown-menu';
import { LayoutGrid, List, ArrowUpDown, Calendar, Plus, X, Folder } from 'lucide-react';
import { SearchBar } from '@/components/ui/search-bar';
import { Pagination, PaginationContent, PaginationEllipsis, PaginationItem, PaginationLink, PaginationNext, PaginationPrevious } from "@/components/ui/pagination";
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog";

import AssetGrid from '@/pages/Assets/AssetGrid';
import AssetTable from '@/pages/Assets/AssetTable';
import AssetUploader from '@/pages/Assets/AssetUploader';
import AssetDetailsModal from '@/pages/Assets/AssetDetailsModal';
import MediaFolderTree from '@/pages/Assets/MediaFolderTree';

import type { Project, Asset } from '@/types';
import { DialogDescription } from '@radix-ui/react-dialog';
import MultiSelect from '@/components/ui/select/Select';

interface MediaLibraryModalProps {
    isOpen: boolean;
    onClose: () => void;
    project: Project;
    onSelect: (assets: Asset[]) => void;
    currentlySelected?: Asset[];
    allowMultiple?: boolean;
}

// Référence stable : évite qu'un nouveau [] à chaque render ne relance le useEffect
// de reset (cause de la boucle infinie / React error #185).
const EMPTY_SELECTION: Asset[] = [];

export function MediaLibraryModal({
    isOpen,
    onClose,
    project,
    onSelect,
    currentlySelected = EMPTY_SELECTION,
    allowMultiple = false
}: MediaLibraryModalProps) {
    const t = useTranslation();
    const [assets, setAssets] = useState<{
        data: Asset[];
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
        from: number;
        to: number;
    }>({
        data: [],
        current_page: 1,
        last_page: 1,
        total: 0,
        per_page: 10,
        from: 0,
        to: 0
    });

    const [loading, setLoading] = useState(true);
    const [selectedAssets, setSelectedAssets] = useState<number[]>([]);
    const [selectedAssetObjects, setSelectedAssetObjects] = useState<Asset[]>([]);
    const [search, setSearch] = useState('');
    const [assetType, setAssetType] = useState('all');
    const [dateFilter, setDateFilter] = useState('');
    const [viewMode, setViewMode] = useState('grid');
    const [sortOption, setSortOption] = useState('newest');
    const [showUploader, setShowUploader] = useState(false);
    const [currentPage, setCurrentPage] = useState(1);
    const [itemsPerPage, setItemsPerPage] = useState('10');
    const [selectedAssetForModal, setSelectedAssetForModal] = useState<Asset | null>(null);
    const [showDetailsModal, setShowDetailsModal] = useState(false);
    const [assetToDelete, setAssetToDelete] = useState<Asset | null>(null);
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);
    const [selectedFolderId, setSelectedFolderId] = useState<number | null>(null);

    // Reset the selected assets when the modal is opened.
    // Dépend de `isOpen` SEULEMENT : éviter de relancer cet effet à chaque
    // changement de référence de `currentlySelected` (un [] inline passé par un
    // parent recrée une nouvelle référence à chaque render → boucle infinie / React #185).
    useEffect(() => {
        if (!isOpen) return;
        if (currentlySelected && currentlySelected.length > 0) {
            setSelectedAssets(currentlySelected.map(asset => asset.id));
            setSelectedAssetObjects(currentlySelected);
        } else {
            setSelectedAssets([]);
            setSelectedAssetObjects([]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen]);

    // Load assets when modal opens or filters change
    useEffect(() => {
        if (isOpen) {
            loadAssets();
        }
    }, [isOpen, search, assetType, dateFilter, sortOption, currentPage, itemsPerPage, selectedFolderId]);

    // Update selectedAssetObjects when assets or selectedAssets change.
    // Functional update form avoids adding selectedAssetObjects to deps (which would loop).
    useEffect(() => {
        const visibleSelectedAssets = assets.data.filter(asset => selectedAssets.includes(asset.id));
        setSelectedAssetObjects(prev => {
            const nonVisibleAssets = prev.filter(asset =>
                !assets.data.find(a => a.id === asset.id) && selectedAssets.includes(asset.id)
            );
            const next = [...visibleSelectedAssets, ...nonVisibleAssets];
            // Bail out if IDs unchanged — prevents React error #185
            const sameIds = next.length === prev.length &&
                next.every((a, i) => a.id === prev[i]?.id);
            return sameIds ? prev : next;
        });
    }, [assets.data, selectedAssets]);

    const loadAssets = async () => {
        setLoading(true);
        try {
            let url = route('assets.api.index', project.uuid) +
                `?search=${search}&type=${assetType !== 'all' ? assetType : ''}&date_filter=${dateFilter}&sort=${sortOption}&page=${currentPage}&per_page=${itemsPerPage}`;
            // Filtrer par dossier : null = racine, absent = tous
            if (selectedFolderId !== null) {
                url += `&folder_id=${selectedFolderId}`;
            }
            const response = await fetch(url);

            if (!response.ok) {
                throw new Error('Failed to load assets');
            }

            const data = await response.json();
            setAssets(data);
        } catch (error) {
            toast.error(t('assets.modal_load_error'));
        } finally {
            setLoading(false);
        }
    };

    const handleAssetSelect = (assetId: number) => {
        if (!allowMultiple) {
            setSelectedAssets([assetId]);
            return;
        }

        // For multiple selection, toggle the selection
        setSelectedAssets(prev =>
            prev.includes(assetId)
                ? prev.filter(id => id !== assetId)
                : [...prev, assetId]
        );
    };

    const handleViewModeChange = (value: string) => {
        setViewMode(value);
    };

    const handleSearchChange = (value: string) => {
        setSearch(value);
        setCurrentPage(1); // Reset to first page when search changes
    };

    const handleTypeChange = (value: string) => {
        setAssetType(value);
        setCurrentPage(1); // Reset to first page when filter changes
    };

    const handleDateFilterChange = (value: string) => {
        setDateFilter(value);
        setCurrentPage(1); // Reset to first page when filter changes
    };

    const handleSortChange = (value: string) => {
        setSortOption(value);
        setCurrentPage(1); // Reset to first page when sort changes
    };

    const handleItemsPerPageChange = (value: string) => {
        setItemsPerPage(value);
        setCurrentPage(1); // Reset to first page when items per page changes
    };

    const handleClearSelection = () => {
        setSelectedAssets([]);
        setSelectedAssetObjects([]);
    };

    const handleConfirm = () => {
        // Pass the complete asset objects to the caller
        onSelect(selectedAssetObjects);
        onClose();
    };

    const handleModalClose = () => {
        onClose();
    };

    // Handle successful asset upload
    const handleAssetUploaded = () => {
        loadAssets();
        setShowUploader(false);
    };

    const confirmDeleteAsset = (asset: Asset) => {
        setAssetToDelete(asset);
        setShowDeleteDialog(true);
    };

    const handleDeleteAsset = async (asset: Asset) => {
        // Remove from selected assets if it was selected
        if (selectedAssets.includes(asset.id)) {
            setSelectedAssets(selectedAssets.filter(id => id !== asset.id));
            setSelectedAssetObjects(selectedAssetObjects.filter(a => a.id !== asset.id));
        }

        try {
            await axios.delete(route('assets.api.destroy', [project.uuid, asset.uuid]));
            toast.success(t('assets.modal_delete_success', { filename: asset.original_filename }));
            loadAssets();
            setShowDeleteDialog(false);
        } catch (error) {
            console.error('Error deleting asset:', error);
            toast.error(t('assets.modal_delete_error'));
            setShowDeleteDialog(false);
        }
    };

    const handlePageChange = (page: number) => {
        setCurrentPage(page);
    };

    const handleViewAssetDetails = (asset: Asset) => {
        setSelectedAssetForModal(asset);
        setShowDetailsModal(true);
    };

    const handleAssetDetailsUpdated = (updatedAsset: Asset) => {
        // Update the asset in the assets.data array
        const updatedAssets = {
            ...assets,
            data: assets.data.map(a => a.id === updatedAsset.id ? updatedAsset : a)
        };
        setAssets(updatedAssets);

        // Make sure the asset remains selected
        if (!selectedAssets.includes(updatedAsset.id)) {
            if (!allowMultiple) {
                setSelectedAssets([updatedAsset.id]);
                setSelectedAssetObjects([updatedAsset]);
            } else {
                setSelectedAssets([...selectedAssets, updatedAsset.id]);
                setSelectedAssetObjects([...selectedAssetObjects, updatedAsset]);
            }
        } else {
            // Update the asset in the selectedAssetObjects array
            setSelectedAssetObjects(selectedAssetObjects.map(a =>
                a.id === updatedAsset.id ? updatedAsset : a
            ));
        }

        // Close the details modal
        setShowDetailsModal(false);
    };

    // Generate array of pages to show in pagination
    const getPageNumbers = () => {
        const pageNumbers = [];
        const maxPagesToShow = 5;

        // Always include page 1
        pageNumbers.push(1);

        // Build the page numbers array
        const startPage = Math.max(2, currentPage - Math.floor(maxPagesToShow / 2));
        const endPage = Math.min(assets.last_page - 1, startPage + maxPagesToShow - 2);

        // Add ellipsis if needed after page 1
        if (startPage > 2) {
            pageNumbers.push('ellipsis-start');
        }

        // Add the visible page numbers
        for (let i = startPage; i <= endPage; i++) {
            pageNumbers.push(i);
        }

        // Add ellipsis if needed before last page
        if (endPage < assets.last_page - 1) {
            pageNumbers.push('ellipsis-end');
        }

        // Always include the last page if there is more than one page
        if (assets.last_page > 1) {
            pageNumbers.push(assets.last_page);
        }

        return pageNumbers;
    };

    // Display formatted date filter for the button
    const getDateFilterDisplayLocal = (filter: string) => {
        switch (filter) {
            case 'today': return t('assets.date_today');
            case 'week': return t('assets.date_week');
            case 'month': return t('assets.date_month');
            case 'quarter': return t('assets.date_quarter');
            default: return t('assets.date_filter_btn');
        }
    };

    // Display formatted sort option for the button
    const getSortOptionDisplayLocal = (option: string) => {
        switch (option) {
            case 'newest': return t('assets.modal_sort_newest');
            case 'oldest': return t('assets.modal_sort_oldest');
            case 'name': return t('assets.modal_sort_name');
            case 'size': return t('assets.modal_sort_size');
            default: return t('assets.modal_sort_default');
        }
    };

    // Options for React-Select dropdowns
    const assetTypeOptions = [
        { value: 'all', label: t('assets.modal_type_all') },
        { value: 'image', label: t('assets.type_images') },
        { value: 'video', label: t('assets.type_videos') },
        { value: 'audio', label: t('assets.type_audio') },
        { value: 'document', label: t('assets.type_documents') },
        { value: 'other', label: t('assets.modal_type_others') },
    ];

    const itemsPerPageOptions = [
        { value: '10', label: '10' },
        { value: '25', label: '25' },
        { value: '50', label: '50' },
        { value: '100', label: '100' },
    ];

    return (
        <>
            <Dialog open={isOpen} onOpenChange={(open) => !open && handleModalClose()} modal>
                <DialogContent className="sm:max-w-6xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <div className="flex justify-between items-center">
                            <DialogTitle>{t('assets.modal_select_title')}</DialogTitle>
                            <DialogDescription className='sr-only'>{t('assets.modal_select_desc')}</DialogDescription>
                            <div className="flex items-center gap-2">
                                <div className="text-sm text-muted-foreground">
                                    {t('assets.modal_assets_selected', { count: String(selectedAssets.length) })}
                                </div>
                                {!showUploader && (
                                    <>
                                        {selectedAssets.length > 0 && (
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                onClick={handleClearSelection}
                                                aria-label={t('assets.clear_selection')}
                                            >
                                                <X className="h-4 w-4" />
                                            </Button>
                                        )}
                                        <Button
                                            variant="outline"
                                            onClick={handleModalClose}
                                        >
                                            {t('assets.modal_cancel')}
                                        </Button>
                                        <Button
                                            onClick={handleConfirm}
                                            disabled={selectedAssets.length === 0}
                                        >
                                            {selectedAssets.length > 0
                                                ? t('assets.modal_select_btn', { count: String(selectedAssets.length) })
                                                : t('assets.modal_select_btn_empty')}
                                        </Button>
                                    </>
                                )}
                            </div>
                        </div>
                    </DialogHeader>

                    {showUploader ? (
                        <AssetUploader
                            isOpen={true}
                            onClose={() => setShowUploader(false)}
                            projectId={project.id}
                            projectUuid={project.uuid}
                            onUploadComplete={handleAssetUploaded}
                            folderId={selectedFolderId}
                        />
                    ) : (
                        <div className="flex gap-4 min-h-[400px]">
                            {/* Sidebar — arbre des dossiers */}
                            <aside className="w-56 flex-shrink-0 border-r border-border pr-3 overflow-y-auto max-h-[60vh]">
                                <div className="flex items-center gap-2 mb-2 px-1">
                                    <Folder className="h-4 w-4 text-muted-foreground" />
                                    <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Dossiers</span>
                                </div>
                                <MediaFolderTree
                                    projectUuid={project.uuid}
                                    selectedFolderId={selectedFolderId}
                                    onSelectFolder={(folderId) => { setSelectedFolderId(folderId); setCurrentPage(1); }}
                                />
                            </aside>

                            {/* Zone principale — filtres + grille */}
                            <div className="flex-1 min-w-0 space-y-4">
                                <div className="flex flex-col md:flex-row gap-2 justify-between">
                                    <div className="flex flex-wrap gap-2">
                                        <SearchBar
                                            placeholder={t('assets.search_ph')}
                                            className="w-full md:w-auto"
                                            value={search}
                                            onChange={handleSearchChange}
                                        />
                                        <MultiSelect
                                            instanceId="asset-type-select"
                                            options={assetTypeOptions}
                                            className="min-w-[140px]"
                                            isSearchable={false}
                                            value={assetTypeOptions.find(o => o.value === assetType)}
                                            onChange={(newValue: any) => handleTypeChange(newValue?.value ?? 'all')}
                                        />

                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="outline" className="w-[140px]">
                                                    <Calendar className="h-4 w-4 mr-2" />
                                                    {getDateFilterDisplayLocal(dateFilter)}
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="start">
                                                <DropdownMenuRadioGroup value={dateFilter} onValueChange={handleDateFilterChange}>
                                                    <DropdownMenuRadioItem value="">{t('assets.modal_all_time')}</DropdownMenuRadioItem>
                                                    <DropdownMenuRadioItem value="today">{t('assets.date_today')}</DropdownMenuRadioItem>
                                                    <DropdownMenuRadioItem value="week">{t('assets.date_week')}</DropdownMenuRadioItem>
                                                    <DropdownMenuRadioItem value="month">{t('assets.date_month')}</DropdownMenuRadioItem>
                                                    <DropdownMenuRadioItem value="quarter">{t('assets.date_quarter')}</DropdownMenuRadioItem>
                                                </DropdownMenuRadioGroup>
                                            </DropdownMenuContent>
                                        </DropdownMenu>

                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="outline" className="w-[140px]">
                                                    <ArrowUpDown className="h-4 w-4 mr-2" />
                                                    {getSortOptionDisplayLocal(sortOption)}
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuRadioGroup value={sortOption} onValueChange={handleSortChange}>
                                                    <DropdownMenuRadioItem value="newest">{t('assets.modal_sort_newest')}</DropdownMenuRadioItem>
                                                    <DropdownMenuRadioItem value="oldest">{t('assets.modal_sort_oldest')}</DropdownMenuRadioItem>
                                                    <DropdownMenuRadioItem value="name">{t('assets.modal_sort_name')}</DropdownMenuRadioItem>
                                                    <DropdownMenuRadioItem value="size">{t('assets.modal_sort_size')}</DropdownMenuRadioItem>
                                                </DropdownMenuRadioGroup>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>

                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            className="px-3"
                                            onClick={() => setShowUploader(!showUploader)}
                                        >
                                            <Plus className="h-4 w-4 mr-2" />
                                            {t('assets.modal_upload')}
                                        </Button>

                                        <Tabs value={viewMode} onValueChange={handleViewModeChange} className="hidden md:flex">
                                            <TabsList>
                                                <TabsTrigger value="grid" className="px-3">
                                                    <LayoutGrid className="h-4 w-4" />
                                                </TabsTrigger>
                                                <TabsTrigger value="list" className="px-3">
                                                    <List className="h-4 w-4" />
                                                </TabsTrigger>
                                            </TabsList>
                                        </Tabs>
                                    </div>
                                </div>

                                {loading ? (
                                    <div className="flex justify-center items-center h-64">
                                        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
                                    </div>
                                ) : (
                                    <>
                                        {viewMode === 'grid' && (
                                            <AssetGrid
                                                assets={assets.data}
                                                selectedAssets={selectedAssets}
                                                onAssetSelect={handleAssetSelect}
                                                project={project}
                                                onDelete={confirmDeleteAsset}
                                                selectOnClick
                                            />
                                        )}

                                        {viewMode === 'list' && (
                                            <AssetTable
                                                assets={assets.data}
                                                selectedAssets={selectedAssets}
                                                onAssetSelect={handleAssetSelect}
                                                onViewDetails={handleViewAssetDetails}
                                                onDelete={confirmDeleteAsset}
                                            />
                                        )}

                                        {/* Pagination */}
                                        {assets.last_page > 1 && (
                                            <div className="flex justify-between mt-4">
                                                <div className="pb-1 w-1/2">
                                                    <Pagination className="justify-start">
                                                        <PaginationContent>
                                                            <PaginationItem>
                                                                <PaginationPrevious
                                                                    href="#"
                                                                    onClick={(e) => {
                                                                        e.preventDefault();
                                                                        if (currentPage > 1) handlePageChange(currentPage - 1);
                                                                    }}
                                                                    className={currentPage === 1 ? 'pointer-events-none opacity-50' : ''}
                                                                />
                                                            </PaginationItem>

                                                            {getPageNumbers().map((page, i) =>
                                                                typeof page === 'string' ? (
                                                                    <PaginationItem key={page}>
                                                                        <PaginationEllipsis />
                                                                    </PaginationItem>
                                                                ) : (
                                                                    <PaginationItem key={page}>
                                                                        <PaginationLink
                                                                            href="#"
                                                                            onClick={(e) => {
                                                                                e.preventDefault();
                                                                                handlePageChange(page as number);
                                                                            }}
                                                                            isActive={currentPage === page}
                                                                        >
                                                                            {page}
                                                                        </PaginationLink>
                                                                    </PaginationItem>
                                                                )
                                                            )}

                                                            <PaginationItem>
                                                                <PaginationNext
                                                                    href="#"
                                                                    onClick={(e) => {
                                                                        e.preventDefault();
                                                                        if (currentPage < assets.last_page) handlePageChange(currentPage + 1);
                                                                    }}
                                                                    className={currentPage === assets.last_page ? 'pointer-events-none opacity-50' : ''}
                                                                />
                                                            </PaginationItem>
                                                        </PaginationContent>
                                                    </Pagination>
                                                </div>
                                                <div className="flex justify-end items-center mb-4 px-2 w-1/2">
                                                    <span className="text-sm text-muted-foreground mr-2">{t('assets.modal_items_per_page')}</span>
                                                    <MultiSelect
                                                        instanceId="items-per-page-select"
                                                        options={itemsPerPageOptions}
                                                        className="w-[70px] h-8"
                                                        isSearchable={false}
                                                        value={itemsPerPageOptions.find(o => o.value === itemsPerPage)}
                                                        onChange={(newValue: any) => handleItemsPerPageChange(newValue.value)}
                                                    />
                                                </div>
                                            </div>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {/* Asset Details Modal */}
            {selectedAssetForModal && (
                <AssetDetailsModal
                    isOpen={showDetailsModal}
                    onClose={() => setShowDetailsModal(false)}
                    project={project}
                    asset={selectedAssetForModal}
                    onUpdate={handleAssetDetailsUpdated}
                />
            )}

            {/* Confirm Delete Dialog */}
            <AlertDialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('assets.modal_delete_confirm_title')}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('assets.modal_delete_confirm_desc', { filename: assetToDelete?.original_filename ?? '' })}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => assetToDelete && handleDeleteAsset(assetToDelete)}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            {t('common.delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

 
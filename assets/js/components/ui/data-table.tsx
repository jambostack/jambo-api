import { useState, useEffect, forwardRef, useImperativeHandle, useRef } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { 
    Table, 
    TableBody, 
    TableCell, 
    TableHead, 
    TableHeader, 
    TableRow 
} from '@/components/ui/table';
import { Pagination, PaginationContent, PaginationEllipsis, PaginationItem, PaginationLink, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { 
    Select, 
    SelectContent, 
    SelectItem, 
    SelectTrigger, 
    SelectValue 
} from '@/components/ui/select';
import { SearchBar } from '@/components/ui/search-bar';
import { Checkbox } from '@/components/ui/checkbox';
import { X, ArrowUpDown, ArrowUp, ArrowDown, Filter, Plus, Minus, Settings2, RotateCcw, Loader2 } from 'lucide-react';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { TooltipProvider } from '@radix-ui/react-tooltip';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/components/ui/popover";
import { Label } from "@/components/ui/label";
import { ScrollArea } from "@/components/ui/scroll-area";
import { DatePicker } from "@/components/ui/date-picker";
import { DateRange } from "react-day-picker";
import { Switch } from "@/components/ui/switch";
import axios from 'axios';
import { toast } from 'sonner';

import { ColumnDef } from '@/types/content';

interface ActionButton {
    label: string;
    onClick: () => void;
    variant?: 'default' | 'destructive' | 'outline' | 'secondary' | 'ghost' | 'link';
    size?: 'default' | 'sm' | 'lg' | 'icon';
    icon?: React.ReactNode;
    show?: boolean;
}

export interface FilterOption {
    label: string;
    value: string;
}

export interface ColumnFilter {
    type: 'select' | 'text' | 'date';
    options?: FilterOption[];
    placeholder?: string;
}

interface DataTableProps<T> {
    columns: {
        header: string;
        accessorKey: string;
        cell?: (item: T) => React.ReactNode;
        sortable?: boolean;
        filter?: ColumnFilter;
        visible?: boolean;
    }[];
    searchPlaceholder?: string;
    searchRoute: string;
    actions?: ActionButton[];
    onRowClick?: (item: T) => void;
    selectable?: boolean;
    onSelectionChange?: (selectedItems: T[]) => void;
    selectedItems?: T[];
    /**
     * Defines how the table should uniquely identify each item when performing
     * selection checks. If a string is provided the value of that key on the
     * item object will be used. If a function is provided the return value of
     * that function will be used. Defaults to "id".
     */
    itemKey?: string | ((item: T) => any);
    pageName: string;
}

interface TableSettings {
    columnVisibility: Record<string, boolean>;
    filters: Record<string, string>;
    dateRanges: Record<string, DateRange>;
    sortColumn: string | null;
    sortDirection: 'asc' | 'desc';
    itemsPerPage: string;
    search: string;
    currentPage: number;
}

export interface DataTableRef {
    fetchData: () => void;
}

export const DataTable = forwardRef<DataTableRef, DataTableProps<any>>(({
    columns: initialColumns,
    searchPlaceholder = "Search...",
    searchRoute,
    actions = [],
    onRowClick,
    selectable = false,
    onSelectionChange,
    selectedItems: externalSelectedItems,
    itemKey = 'id',
    pageName,
}, ref) => {
    const [search, setSearch] = useState('');
    const [itemsPerPage, setItemsPerPage] = useState('10');
    const [internalSelectedItems, setInternalSelectedItems] = useState<any[]>([]);
    const [sortColumn, setSortColumn] = useState<string | null>(null);
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');
    const [filters, setFilters] = useState<Record<string, string>>({});
    const [dateRanges, setDateRanges] = useState<Record<string, DateRange>>({});
    const [activeFilters, setActiveFilters] = useState<string[]>([]);
    const [columns, setColumns] = useState(initialColumns.map(col => ({
        ...col,
        visible: col.visible !== false
    })));
    const [data, setData] = useState({
        data: [],
        current_page: 1,
        last_page: 1,
        from: 0,
        to: 0,
        total: 0
    });
    const [loading, setLoading] = useState(false);
    const [isInitialLoad, setIsInitialLoad] = useState(true);
    const [currentPage, setCurrentPage] = useState(1);
    const searchTimeoutRef = useRef<ReturnType<typeof setTimeout>>(null);
    const fetchTimeoutRef = useRef<ReturnType<typeof setTimeout>>(null);
    const hasInitialRequestRef = useRef(false);

    useImperativeHandle(ref, () => ({
        fetchData: () => {
            if (fetchTimeoutRef.current) {
                clearTimeout(fetchTimeoutRef.current);
            }
            const params = {
                page: currentPage,
                search,
                per_page: itemsPerPage,
                sort: sortColumn,
                direction: sortDirection,
                ...filters,
                ...Object.fromEntries(
                    Object.entries(dateRanges).flatMap(([key, range]) => {
                        const filters: [string, string][] = [];
                        if (range?.from) {
                            filters.push([`filter_${key}_from`, range.from.toISOString().split('T')[0]]);
                            if (range.to) {
                                filters.push([`filter_${key}_to`, range.to.toISOString().split('T')[0]]);
                            }
                        }
                        return filters;
                    })
                )
            };
            fetchData(params);
        }
    }));

    // Load all table settings from localStorage
    useEffect(() => {
        const savedSettings = localStorage.getItem(`table_settings:${pageName}`);
        if (savedSettings) {
            try {
                // Try to parse as plain JSON first (backward compatibility)
                let parsed: TableSettings;
                try {
                    parsed = JSON.parse(savedSettings);
                } catch (_err) {
                    // If plain JSON parse fails, assume the string is base64 encoded
                    try {
                        parsed = JSON.parse(decodeURIComponent(atob(savedSettings)));
                    } catch (__err) {
                        console.error('Failed to decode table settings:', __err);
                        return; // Abort loading settings if decoding fails
                    }
                }

                const settings: TableSettings = parsed;
                
                // Load column visibility
                if (settings.columnVisibility) {
                    setColumns(prev => prev.map(col => ({
                        ...col,
                        visible: settings.columnVisibility[col.accessorKey] ?? col.visible
                    })));
                }

                // Load filters
                if (settings.filters) {
                    setFilters(settings.filters);
                    setActiveFilters(Object.keys(settings.filters).map(key => key.replace('filter_', '')));
                }

                // Load date ranges
                if (settings.dateRanges) {
                    setDateRanges(settings.dateRanges);
                    setActiveFilters(prev => [...prev, ...Object.keys(settings.dateRanges)]);
                }

                // Load sort settings
                if (settings.sortColumn) {
                    setSortColumn(settings.sortColumn);
                    setSortDirection(settings.sortDirection);
                }

                // Load items per page
                if (settings.itemsPerPage) {
                    setItemsPerPage(settings.itemsPerPage);
                }

                // Load search
                if (settings.search) {
                    setSearch(settings.search);
                }

                // Load current page
                if (settings.currentPage) {
                    setCurrentPage(settings.currentPage);
                }
            } catch (error) {
                console.error('Failed to load table settings:', error);
            }
        }
        setIsInitialLoad(false);
    }, [pageName]);

    // Single effect to handle all data fetching with debounce
    useEffect(() => {
        if (!isInitialLoad) {
            if (fetchTimeoutRef.current) {
                clearTimeout(fetchTimeoutRef.current);
            }

            // Skip the first effect run after settings load
            if (!hasInitialRequestRef.current) {
                return;
            }

            fetchTimeoutRef.current = setTimeout(() => {
                const params = {
                    page: currentPage,
                    search,
                    per_page: itemsPerPage,
                    sort: sortColumn,
                    direction: sortDirection,
                    ...filters,
                    ...Object.fromEntries(
                        Object.entries(dateRanges).flatMap(([key, range]) => {
                            const filters: [string, string][] = [];
                            if (range?.from) {
                                filters.push([`filter_${key}_from`, range.from.toISOString().split('T')[0]]);
                                if (range.to) {
                                    filters.push([`filter_${key}_to`, range.to.toISOString().split('T')[0]]);
                                }
                            }
                            return filters;
                        })
                    )
                };
                fetchData(params);
            }, 100); // 100ms debounce
        }
    }, [isInitialLoad, currentPage, search, itemsPerPage, sortColumn, sortDirection, filters, dateRanges]);

    // Make initial request after settings are loaded
    useEffect(() => {
        if (!isInitialLoad && !hasInitialRequestRef.current) {
            const params = {
                page: currentPage,
                search,
                per_page: itemsPerPage,
                sort: sortColumn,
                direction: sortDirection,
                ...filters,
                ...Object.fromEntries(
                    Object.entries(dateRanges).flatMap(([key, range]) => {
                        const filters: [string, string][] = [];
                        if (range?.from) {
                            filters.push([`filter_${key}_from`, range.from.toISOString().split('T')[0]]);
                            if (range.to) {
                                filters.push([`filter_${key}_to`, range.to.toISOString().split('T')[0]]);
                            }
                        }
                        return filters;
                    })
                )
            };
            fetchData(params);
            hasInitialRequestRef.current = true;
        }
    }, [isInitialLoad]);

    // Effect to save settings when they change
    useEffect(() => {
        if (!isInitialLoad) {
            const settings: TableSettings = {
                columnVisibility: Object.fromEntries(
                    columns.map(col => [col.accessorKey, col.visible])
                ),
                filters,
                dateRanges,
                sortColumn,
                sortDirection,
                itemsPerPage,
                search,
                currentPage
            };

            try {
                // Store encoded to obscure raw JSON and avoid special-character issues
                const encoded = btoa(encodeURIComponent(JSON.stringify(settings)));
                localStorage.setItem(`table_settings:${pageName}`, encoded);
            } catch (error) {
                console.error('Failed to save table settings:', error);
            }
        }
    }, [columns, filters, dateRanges, sortColumn, sortDirection, itemsPerPage, search, currentPage, isInitialLoad, pageName]);

    const fetchData = async (params: any = {}) => {
        try {
            setLoading(true);
            
            // Create request parameters
            const requestParams: Record<string, any> = {
                page: params.page || currentPage,
                per_page: params.per_page || itemsPerPage,
                sort: params.sort || sortColumn,
                direction: params.direction || sortDirection,
                search: params.search !== undefined ? params.search : search,
                ...params,
            };

            // Remove any undefined or empty values except for search
            Object.keys(requestParams).forEach(key => {
                if (requestParams[key] === undefined || (key !== 'search' && requestParams[key] === '')) {
                    delete requestParams[key];
                }
            });

            const response = await axios.get(searchRoute, { params: requestParams });
            setData(response.data);
            setIsInitialLoad(false);
        } catch (error) {
            toast.error('Failed to fetch data');
        } finally {
            setLoading(false);
        }
    };

    const getItemKey = (item: any) => {
        if (item === null || item === undefined) return item;
        if (typeof item !== 'object') return item; // primitive already represents key
        return typeof itemKey === 'function' ? itemKey(item) : item[itemKey];
    };

    const selectedItems = externalSelectedItems ?? internalSelectedItems;
    const setSelectedItems = (items: any[]) => {
        if (externalSelectedItems === undefined) {
            setInternalSelectedItems(items);
        }
        onSelectionChange?.(items);
    };

    const handleSearchChange = (value: string) => {
        setSearch(value);
        setCurrentPage(1); // Reset to first page on search
    };

    const handlePageChange = (page: number) => {
        setCurrentPage(page);
    };

    const handleItemsPerPageChange = (value: string) => {
        setItemsPerPage(value);
        setCurrentPage(1); // Reset to first page when changing items per page
    };

    const handleSort = (column: string) => {
        const newDirection = sortColumn === column && sortDirection === 'asc' ? 'desc' : 'asc';
        setSortColumn(column);
        setSortDirection(newDirection);
    };

    const handleFilterChange = (column: string, value: string) => {
        const newFilters = { ...filters };
        if (value) {
            newFilters[`filter_${column}`] = value;
            if (!activeFilters.includes(column)) {
                setActiveFilters([...activeFilters, column]);
            }
        } else {
            delete newFilters[`filter_${column}`];
            setActiveFilters(activeFilters.filter(f => f !== column));
        }
        setFilters(newFilters);
        setCurrentPage(1); // Reset to first page when filter changes
    };

    const handleDateRangeChange = (column: string, range: DateRange | undefined) => {
        const newFilters = { ...filters };
        const newDateRanges = { ...dateRanges };

        if (range?.from) {
            newFilters[`filter_${column}_from`] = range.from.toISOString().split('T')[0];
            if (range.to) {
                newFilters[`filter_${column}_to`] = range.to.toISOString().split('T')[0];
            }
            newDateRanges[column] = range;
            if (!activeFilters.includes(column)) {
                setActiveFilters([...activeFilters, column]);
            }
        } else {
            delete newFilters[`filter_${column}_from`];
            delete newFilters[`filter_${column}_to`];
            delete newDateRanges[column];
            setActiveFilters(activeFilters.filter(f => f !== column));
        }

        setFilters(newFilters);
        setDateRanges(newDateRanges);
        setCurrentPage(1); // Reset to first page when date range changes
    };

    const clearFilter = (column: string) => {
        const newFilters = { ...filters };
        const newDateRanges = { ...dateRanges };
        
        delete newFilters[`filter_${column}`];
        delete newFilters[`filter_${column}_from`];
        delete newFilters[`filter_${column}_to`];
        delete newDateRanges[column];
        
        setFilters(newFilters);
        setDateRanges(newDateRanges);
        setActiveFilters(activeFilters.filter(f => f !== column));
        
        setCurrentPage(1); // Reset to first page; unified effect will refetch
    };

    const clearAllFilters = () => {
        setFilters({});
        setDateRanges({});
        setActiveFilters([]);
        
        setCurrentPage(1); // Unified effect will handle refetch
    };

    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            setSelectedItems(data.data);
        } else {
            setSelectedItems([]);
        }
    };

    const handleSelectItem = (item: any, checked: boolean) => {
        let newSelectedItems: any[];
        if (checked) {
            // Avoid duplicates by key
            const exists = selectedItems.some(selectedItem => getItemKey(selectedItem) === getItemKey(item));
            newSelectedItems = exists ? selectedItems : [...selectedItems, item];
        } else {
            newSelectedItems = selectedItems.filter(selectedItem => getItemKey(selectedItem) !== getItemKey(item));
        }
        setSelectedItems(newSelectedItems);
    };

    const isAllSelected =
        data?.data?.length > 0 &&
        data.data.every((item: any) => selectedItems.some(selectedItem => getItemKey(selectedItem) === getItemKey(item)));

    const getSortIcon = (column: string) => {
        if (sortColumn !== column) return <ArrowUpDown className="h-4 w-4" />;
        return sortDirection === 'asc' ? <ArrowUp className="h-4 w-4" /> : <ArrowDown className="h-4 w-4" />;
    };

    const toggleColumn = (accessorKey: string) => {
        const newColumns = columns.map(col => 
            col.accessorKey === accessorKey 
                ? { ...col, visible: !col.visible }
                : col
        );
        setColumns(newColumns);
    };

    const visibleColumns = columns.filter(col => col.visible);
    const filterableColumns = visibleColumns.filter(column => column.filter);

    const formatDateRange = (range: DateRange | undefined) => {
        if (!range?.from) return "";
        const from = range.from.toLocaleDateString();
        const to = range.to ? range.to.toLocaleDateString() : "";
        return to ? `${from} - ${to}` : from;
    };

    const resetTable = () => {
        // Reset all states to initial values
        setSearch('');
        setCurrentPage(1);
        setItemsPerPage('10');
        setSortColumn(null);
        setSortDirection('asc');
        setFilters({});
        setDateRanges({});
        setActiveFilters([]);
    };

    // Cleanup pending timeouts on component unmount
    useEffect(() => {
        return () => {
            if (fetchTimeoutRef.current) {
                clearTimeout(fetchTimeoutRef.current);
            }
        };
    }, []);

    return (
        <div className="space-y-5">
            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div className="w-full sm:max-w-md flex flex-col sm:flex-row items-start sm:items-center gap-2">
                    <SearchBar
                        value={search}
                        onChange={handleSearchChange}
                        placeholder={searchPlaceholder}
                        className="w-full"
                    />
                    <div className="flex items-center gap-2 w-full sm:w-auto">
                        {filterableColumns.length > 0 && (
                            <div className="flex items-center gap-2 whitespace-nowrap">
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            className={`${activeFilters.length > 0 ? 'text-primary' : ''}`}
                                        >
                                            <Filter className="h-4 w-4" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent className="w-[calc(100vw-2rem)] sm:w-80">
                                        <div className="space-y-4">
                                            <div className="flex items-center justify-between">
                                                <h4 className="font-medium px-2">Filters</h4>
                                                {activeFilters.length > 0 && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={clearAllFilters}
                                                        className="h-8"
                                                    >
                                                        Clear filters
                                                    </Button>
                                                )}
                                            </div>
                                            <ScrollArea className="h-[300px] pr-4">
                                                <div className="space-y-4 px-2">
                                                    {filterableColumns.map((column) => (
                                                        <div key={column.accessorKey} className="space-y-2">
                                                            <Label>{column.header}</Label>
                                                            {column.filter?.type === 'select' && column.filter.options && (
                                                                <Select
                                                                    value={filters[`filter_${column.accessorKey}`] || 'all'}
                                                                    onValueChange={(value) => handleFilterChange(column.accessorKey, value === 'all' ? '' : value)}
                                                                >
                                                                    <SelectTrigger>
                                                                        <SelectValue placeholder={column.filter.placeholder || 'Select...'} />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        <SelectItem value="all">All</SelectItem>
                                                                        {column.filter.options.map(option => (
                                                                            <SelectItem key={option.value} value={option.value}>
                                                                                {option.label}
                                                                            </SelectItem>
                                                                        ))}
                                                                    </SelectContent>
                                                                </Select>
                                                            )}
                                                            {column.filter?.type === 'text' && (
                                                                <Input
                                                                    value={filters[`filter_${column.accessorKey}`] || ''}
                                                                    onChange={(e) => handleFilterChange(column.accessorKey, e.target.value)}
                                                                    placeholder={column.filter.placeholder || 'Filter...'}
                                                                />
                                                            )}
                                                            {column.filter?.type === 'date' && (
                                                                <DatePicker
                                                                    date={dateRanges[column.accessorKey]}
                                                                    onSelect={(range) => handleDateRangeChange(column.accessorKey, range as DateRange)}
                                                                    mode="range"
                                                                    placeholder={column.filter.placeholder || 'Select date range...'}
                                                                    numberOfMonths={2}
                                                                />
                                                            )}
                                                        </div>
                                                    ))}
                                                </div>
                                            </ScrollArea>
                                        </div>
                                    </PopoverContent>
                                </Popover>
                            </div>
                        )}
                        <div className="flex items-center gap-2 whitespace-nowrap">
                            <Popover>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        className="relative"
                                    >
                                        <Settings2 className="h-4 w-4" />
                                        {columns.some(col => !col.visible) && (
                                            <span className="absolute -top-1 -right-1 h-2 w-2 rounded-full bg-primary" />
                                        )}
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-[calc(100vw-2rem)] sm:w-72">
                                    <div className="space-y-4">
                                        <div className="flex items-center justify-between">
                                            <h4 className="font-medium">Column Settings</h4>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => setColumns(prev => prev.map(col => ({ ...col, visible: true })))}
                                                className="h-8 text-xs"
                                            >
                                                Show all
                                            </Button>
                                        </div>
                                        <ScrollArea className="h-[300px] pr-4">
                                            <div className="space-y-1">
                                                {columns.map((column) => (
                                                    <div 
                                                        key={column.accessorKey} 
                                                        className="flex items-center justify-between py-2 px-1 rounded-md hover:bg-muted/50"
                                                    >
                                                        <div className="flex items-center gap-2">
                                                            {column.sortable && (
                                                                <ArrowUpDown className="h-3 w-3 text-muted-foreground" />
                                                            )}
                                                            <Label className="text-sm font-normal cursor-pointer">
                                                                {column.header}
                                                            </Label>
                                                        </div>
                                                        <Switch
                                                            checked={column.visible}
                                                            onCheckedChange={() => toggleColumn(column.accessorKey)}
                                                        />
                                                    </div>
                                                ))}
                                            </div>
                                        </ScrollArea>
                                    </div>
                                </PopoverContent>
                            </Popover>
                        </div>
                        {(activeFilters.length > 0 || search || sortColumn) && (
                            <div className="flex items-center gap-2 whitespace-nowrap">
                                <TooltipProvider>
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                onClick={resetTable}
                                                className="relative"
                                            >
                                                <RotateCcw className="h-4 w-4" />
                                                <span className="sr-only">Reset table</span>
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            Reset table to initial state
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </div>
                        )}
                        {selectable && selectedItems.length > 0 && (
                            <div className="flex items-center gap-2 whitespace-nowrap">
                                <span className="text-sm text-muted-foreground">
                                    {selectedItems.length} selected
                                </span>
                                <TooltipProvider>
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <Button 
                                                variant="outline" 
                                                size="icon" 
                                                onClick={() => {
                                                    setSelectedItems([]);
                                                }}
                                            >
                                                <X className="h-4 w-4" />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>Clear selection</TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </div>
                        )}
                    </div>
                </div>
                
                {actions.length > 0 && (
                    <div className="flex gap-2 w-full sm:w-auto justify-end">
                        {actions
                            .filter(action => action.show !== false)
                            .map((action, index) => (
                                <Button
                                    key={index}
                                    onClick={action.onClick}
                                    variant={action.variant || 'default'}
                                    size={action.size || 'default'}
                                    className="w-full sm:w-auto"
                                >
                                    {action.icon && <span>{action.icon}</span>}
                                    <span className="hidden sm:inline">{action.label}</span>
                                    {!action.icon && <span className="sm:hidden">{action.label.charAt(0)}</span>}
                                </Button>
                            ))}
                    </div>
                )}
            </div>

            {activeFilters.length > 0 && (
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-sm text-muted-foreground">Active filters:</span>
                    {activeFilters.map(column => {
                        const columnConfig = columns.find(c => c.accessorKey === column);
                        const isDateFilter = columnConfig?.filter?.type === 'date';
                        return (
                            <div key={column} className="flex items-center gap-1 bg-muted px-2 py-1 rounded-md">
                                <span className="text-sm">
                                    {columnConfig?.header}: {
                                        isDateFilter 
                                            ? formatDateRange(dateRanges[column])
                                            : filters[`filter_${column}`]
                                    }
                                </span>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-4 w-4"
                                    onClick={() => clearFilter(column)}
                                >
                                    <X className="h-3 w-3" />
                                </Button>
                            </div>
                        );
                    })}
                </div>
            )}
            
            <div className="rounded-md overflow-x-auto">
                <Table>
                    <TableHeader className="bg-muted/50">
                        <TableRow className="hover:bg-muted/50">
                            {selectable && (
                                <TableHead className="w-px">
                                    <Checkbox
                                        checked={isAllSelected}
                                        onCheckedChange={handleSelectAll}
                                        aria-label="Select all"
                                    />
                                </TableHead>
                            )}
                            {visibleColumns.map((column: ColumnDef) => (
                                <TableHead 
                                    key={column.accessorKey} 
                                    className={`align-middle ${column.sortable ? 'cursor-pointer hover:bg-muted' : ''} ${column.padding ? column.padding : ''} ${column.width ? column.width : ''}`}
                                >
                                    <div 
                                        className={`flex items-center gap-2 ${column.align ? `justify-${column.align}` : ''}`}
                                        onClick={() => column.sortable && handleSort(column.accessorKey)}
                                    >
                                        {column.header}
                                        {column.sortable && (
                                            <span className="text-muted-foreground">
                                                {getSortIcon(column.accessorKey)}
                                            </span>
                                        )}
                                    </div>
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {loading ? (
                            <TableRow>
                                <TableCell colSpan={visibleColumns.length + (selectable ? 1 : 0)} className="text-center h-24">
                                    <Loader2 className="h-5 w-5 mx-auto animate-spin text-muted-foreground" />
                                </TableCell>
                            </TableRow>
                        ) : (!data?.data || data.data.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={visibleColumns.length + (selectable ? 1 : 0)} className="text-center h-24 text-muted-foreground">
                                    No items found
                                </TableCell>
                            </TableRow>
                        ) : (
                            data.data.map((item, index) => (
                                <TableRow
                                    key={index}
                                    className={onRowClick ? "cursor-pointer hover:bg-muted/50" : ""}
                                    onClick={(e) => {
                                        const t = e.target as HTMLElement;
                                        if (t.closest('button') || t.closest('a') || t.closest('[data-no-row-click]')) return;
                                        onRowClick?.(item);
                                    }}
                                >
                                    {selectable && (
                                        <TableCell className="w-px">
                                            <Checkbox
                                                checked={selectedItems.some(selectedItem => getItemKey(selectedItem) === getItemKey(item))}
                                                onCheckedChange={(checked) => handleSelectItem(item, checked as boolean)}
                                                onClick={(e) => e.stopPropagation()}
                                                aria-label={`Select ${(item as any).name || `item ${index + 1}`}`}
                                            />
                                        </TableCell>
                                    )}
                                    {visibleColumns.map((column: ColumnDef) => (
                                        <TableCell key={column.accessorKey} className={`py-2 align-middle ${column.align ? `text-${column.align}` : ''}`}>
                                            {column.cell ? column.cell(item) : (item as any)[column.accessorKey]}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))
                        ))}
                    </TableBody>
                </Table>
            </div>
            
            {data?.data && data.data.length > 0 && (
                <div className="flex flex-col sm:flex-row justify-between gap-4 mt-4">
                    <div className="w-full sm:w-1/2">
                        <Pagination className="justify-start">
                            <PaginationContent>
                                <PaginationItem>
                                    <PaginationPrevious
                                        href="#"
                                        onClick={(e) => {
                                            e.preventDefault();
                                            if (data.current_page > 1) handlePageChange(data.current_page - 1);
                                        }}
                                        className={data.current_page === 1 ? 'pointer-events-none opacity-50' : ''}
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
                                        isActive={data.current_page === 1}
                                    >
                                        1
                                    </PaginationLink>
                                </PaginationItem>

                                {/* If there are many pages, show ellipsis after first page */}
                                {data.current_page > 3 && (
                                    <PaginationItem>
                                        <PaginationEllipsis />
                                    </PaginationItem>
                                )}

                                {/* Page before current if not first page or adjacent to first */}
                                {data.current_page > 2 && (
                                    <PaginationItem>
                                        <PaginationLink
                                            href="#"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                handlePageChange(data.current_page - 1);
                                            }}
                                        >
                                            {data.current_page - 1}
                                        </PaginationLink>
                                    </PaginationItem>
                                )}

                                {/* Current page if not first or last */}
                                {data.current_page !== 1 && data.current_page !== data.last_page && (
                                    <PaginationItem>
                                        <PaginationLink
                                            href="#"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                handlePageChange(data.current_page);
                                            }}
                                            isActive
                                        >
                                            {data.current_page}
                                        </PaginationLink>
                                    </PaginationItem>
                                )}

                                {/* Page after current if not last page or adjacent to last */}
                                {data.current_page < data.last_page - 1 && (
                                    <PaginationItem>
                                        <PaginationLink
                                            href="#"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                handlePageChange(data.current_page + 1);
                                            }}
                                        >
                                            {data.current_page + 1}
                                        </PaginationLink>
                                    </PaginationItem>
                                )}

                                {/* If there are many pages, show ellipsis before last page */}
                                {data.current_page < data.last_page - 2 && (
                                    <PaginationItem>
                                        <PaginationEllipsis />
                                    </PaginationItem>
                                )}

                                {/* Last page if not the same as first page */}
                                {data.last_page > 1 && (
                                    <PaginationItem>
                                        <PaginationLink
                                            href="#"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                handlePageChange(data.last_page);
                                            }}
                                            isActive={data.current_page === data.last_page}
                                        >
                                            {data.last_page}
                                        </PaginationLink>
                                    </PaginationItem>
                                )}

                                <PaginationItem>
                                    <PaginationNext
                                        href="#"
                                        onClick={(e) => {
                                            e.preventDefault();
                                            if (data.current_page < data.last_page) handlePageChange(data.current_page + 1);
                                        }}
                                        className={data.current_page === data.last_page ? 'pointer-events-none opacity-50' : ''}
                                    />
                                </PaginationItem>
                            </PaginationContent>
                        </Pagination>
                    </div>
                    <div className="flex flex-col sm:flex-row justify-end items-start sm:items-center gap-4 w-full sm:w-1/2">
                        <div className="text-sm text-muted-foreground">
                            Showing <span className="font-semibold">{data.from}</span> to{' '}
                            <span className="font-semibold">{data.to}</span> of{' '}
                            <span className="font-semibold">{data.total}</span> items
                        </div>

                        <div className="flex items-center">
                            <span className="text-sm text-muted-foreground mr-2">Per page:</span>
                            <Select
                                value={itemsPerPage}
                                onValueChange={handleItemsPerPageChange}
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
                </div>
            )}
        </div>
    );
}); 
import { ColumnFilter } from "@/components/ui/data-table";

export interface ContentEntry {
    id: number;
    uuid: string;
    status: string;
    created_at: string;
    updated_at: string;
    published_at: string | null;
    creator: { name: string };
    updater: { name: string };
    locale: string;
    [key: string]: any;
}

export interface ColumnDef {
    header: string;
    accessorKey: string;
    cell?: (item: ContentEntry) => React.ReactNode;
    sortable?: boolean;
    align?: "left" | "center" | "right";
    width?: string;
    padding?: string;
    filter?: ColumnFilter;
    visible?: boolean;
}
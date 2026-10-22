import {
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  useReactTable,
  type ColumnDef,
  type SortingState,
} from "@tanstack/react-table";
import { ArrowDown, ArrowUp } from "lucide-react";
import { useState, type ReactNode } from "react";
import { cn } from "@/lib/utils";
import { EmptyState, Skeleton } from "./feedback";

interface DataTableProps<T> {
  columns: ColumnDef<T, any>[]; // eslint-disable-line @typescript-eslint/no-explicit-any
  data: T[];
  loading?: boolean;
  empty?: ReactNode;
  onRowClick?: (row: T) => void;
  rowClassName?: (row: T) => string | undefined;
  selectedId?: string | null;
  getRowId?: (row: T) => string;
  className?: string;
  dense?: boolean;
}

/** Dense, sortable data table (client-side sorting on the loaded page). */
export function DataTable<T>({ columns, data, loading, empty, onRowClick, rowClassName, selectedId, getRowId, className, dense = true }: DataTableProps<T>) {
  const [sorting, setSorting] = useState<SortingState>([]);
  const table = useReactTable({
    data, columns, state: { sorting }, onSortingChange: setSorting,
    getCoreRowModel: getCoreRowModel(), getSortedRowModel: getSortedRowModel(),
    getRowId: getRowId ? (row) => getRowId(row) : undefined,
  });
  const cell = dense ? "px-3 py-1.5" : "px-3 py-2.5";
  return (
    <div className={cn("overflow-x-auto scrollbar-thin", className)}>
      <table className="w-full border-collapse text-xs">
        <thead className="sticky top-0 z-10 bg-surface-2">
          {table.getHeaderGroups().map((hg) => (
            <tr key={hg.id} className="border-b border-border">
              {hg.headers.map((h) => {
                const sortable = h.column.getCanSort() && h.column.columnDef.enableSorting !== false;
                const dir = h.column.getIsSorted();
                return (
                  <th key={h.id} scope="col" style={{ width: h.column.columnDef.size }}
                    className={cn(cell, "text-left font-medium text-muted whitespace-nowrap select-none",
                      (h.column.columnDef.meta as { align?: string } | undefined)?.align === "right" && "text-right")}>
                    {h.isPlaceholder ? null : sortable ? (
                      <button type="button" onClick={h.column.getToggleSortingHandler()} className="inline-flex items-center gap-1 hover:text-fg">
                        {flexRender(h.column.columnDef.header, h.getContext())}
                        {dir === "asc" && <ArrowUp className="size-3" />}
                        {dir === "desc" && <ArrowDown className="size-3" />}
                      </button>
                    ) : flexRender(h.column.columnDef.header, h.getContext())}
                  </th>
                );
              })}
            </tr>
          ))}
        </thead>
        <tbody>
          {loading && data.length === 0
            ? Array.from({ length: 6 }).map((_, i) => (
                <tr key={i} className="border-b border-border">
                  {columns.map((_, j) => <td key={j} className={cell}><Skeleton className="h-3.5 w-3/4" /></td>)}
                </tr>
              ))
            : table.getRowModel().rows.map((row) => (
                <tr key={row.id}
                  onClick={onRowClick ? () => onRowClick(row.original) : undefined}
                  onKeyDown={onRowClick ? (e) => { if (e.key === "Enter") onRowClick(row.original); } : undefined}
                  tabIndex={onRowClick ? 0 : undefined}
                  aria-selected={selectedId ? row.id === selectedId : undefined}
                  className={cn("border-b border-border last:border-0", onRowClick && "cursor-pointer hover:bg-surface-2 focus:bg-surface-2",
                    selectedId && row.id === selectedId && "bg-primary-soft/60 hover:bg-primary-soft/60", rowClassName?.(row.original))}>
                  {row.getVisibleCells().map((c) => (
                    <td key={c.id} className={cn(cell, "align-middle",
                      // Long free text (titles) wraps instead of widening the table past its card.
                      (c.column.columnDef.meta as { wrap?: boolean } | undefined)?.wrap ? "min-w-48 whitespace-normal break-words" : "whitespace-nowrap",
                      (c.column.columnDef.meta as { align?: string } | undefined)?.align === "right" && "text-right tabular")}>
                      {flexRender(c.column.columnDef.cell, c.getContext())}
                    </td>
                  ))}
                </tr>
              ))}
        </tbody>
      </table>
      {!loading && data.length === 0 && (empty ?? <EmptyState title="—" />)}
    </div>
  );
}

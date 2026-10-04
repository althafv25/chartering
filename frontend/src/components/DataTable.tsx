import type { ReactNode } from 'react';
import { Box, Skeleton, Table, TableBody, TableCell, TableContainer, TableHead, TablePagination, TableRow } from '@mui/material';
import type { PaginationMeta } from '../types/api';
import { EmptyState } from './Feedback';

export interface Column<T> {
  key: string;
  header: ReactNode;
  render: (row: T) => ReactNode;
  align?: 'left' | 'right' | 'center';
  width?: number | string;
  hideBelow?: 'sm' | 'md' | 'lg';
}

export function DataTable<T>({ columns, rows, rowKey, loading, meta, onPageChange, onPerPageChange, emptyTitle = 'No records found', emptyDescription, onRowClick }: {
  columns: Column<T>[];
  rows: T[];
  rowKey: (row: T) => string | number;
  loading?: boolean;
  meta?: PaginationMeta;
  onPageChange?: (page: number) => void;
  onPerPageChange?: (perPage: number) => void;
  emptyTitle?: string;
  emptyDescription?: string;
  onRowClick?: (row: T) => void;
}) {
  const hide = (c: Column<T>) => (c.hideBelow ? { display: { xs: 'none', [c.hideBelow]: 'table-cell' } } : {});

  return (
    <Box>
      <TableContainer>
        <Table size="small">
          <TableHead>
            <TableRow>
              {columns.map((c) => (
                <TableCell key={c.key} align={c.align} sx={{ width: c.width, ...hide(c) }}>{c.header}</TableCell>
              ))}
            </TableRow>
          </TableHead>
          <TableBody>
            {loading && rows.length === 0
              ? Array.from({ length: 5 }).map((_, i) => (
                  <TableRow key={i}>
                    {columns.map((c) => <TableCell key={c.key} sx={hide(c)}><Skeleton /></TableCell>)}
                  </TableRow>
                ))
              : rows.map((row) => (
                  <TableRow
                    key={rowKey(row)}
                    hover
                    onClick={onRowClick ? () => onRowClick(row) : undefined}
                    sx={{ cursor: onRowClick ? 'pointer' : undefined, opacity: loading ? 0.6 : 1 }}
                  >
                    {columns.map((c) => (
                      <TableCell key={c.key} align={c.align} sx={{ py: 1.25, ...hide(c) }}>{c.render(row)}</TableCell>
                    ))}
                  </TableRow>
                ))}
          </TableBody>
        </Table>
      </TableContainer>

      {!loading && rows.length === 0 && <EmptyState title={emptyTitle} description={emptyDescription} />}

      {meta && onPageChange && meta.total > 0 && (
        <TablePagination
          component="div"
          count={meta.total}
          page={meta.current_page - 1}
          rowsPerPage={meta.per_page}
          rowsPerPageOptions={[10, 15, 25, 50, 100]}
          onPageChange={(_, p) => onPageChange(p + 1)}
          onRowsPerPageChange={(e) => onPerPageChange?.(Number(e.target.value))}
        />
      )}
    </Box>
  );
}

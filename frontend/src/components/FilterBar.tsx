import type { ReactNode } from 'react';
import { InputAdornment, Stack, TextField } from '@mui/material';
import SearchIcon from '@mui/icons-material/Search';

export function FilterBar({ search, onSearch, placeholder = 'Search…', children }: {
  search: string;
  onSearch: (value: string) => void;
  placeholder?: string;
  children?: ReactNode;
}) {
  return (
    <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ p: 2 }}>
      <TextField
        value={search}
        onChange={(e) => onSearch(e.target.value)}
        placeholder={placeholder}
        aria-label="Search"
        sx={{ maxWidth: { md: 360 } }}
        slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchIcon fontSize="small" /></InputAdornment> } }}
      />
      {children}
    </Stack>
  );
}

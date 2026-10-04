import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { SnackbarProvider } from 'notistack';
import { describe, expect, it, vi } from 'vitest';
import { theme } from '../../theme/theme';
import type { RegisterDocument } from '../../types/masters';
import DocumentsRegisterPage from './DocumentsRegisterPage';

const doc = (o: Partial<RegisterDocument> & Pick<RegisterDocument, 'id' | 'title' | 'parent'>): RegisterDocument => ({
  document_type: { id: 1, code: 'cert', name: 'Certificate' }, document_number: null, issue_date: null, expiry_date: null, original_filename: `${o.title}.pdf`, mime_type: 'application/pdf',
  size_bytes: 2048, remarks: null, uploaded_by: { id: 1, name: 'Sam' }, created_at: '2026-10-01T00:00:00Z', ...o,
});

const register = vi.fn(() => Promise.resolve({
  success: true as const, message: 'OK',
  data: [
    doc({ id: 1, title: 'Charter party', parent: { type: 'voyages', id: 9, label: 'GMS1-26-001' } }),
    doc({ id: 2, title: 'Passport copy', expiry_date: '2020-01-01', parent: { type: 'users', id: 4, label: 'Sam Smith' } }),
  ],
  meta: { current_page: 1, per_page: 15, total: 2, last_page: 1, from: 1, to: 2 },
}));
vi.mock('../../api/masters', () => ({ documentsApi: { register: (...a: unknown[]) => register(...(a as [])), types: vi.fn(() => Promise.resolve([{ id: 1, code: 'cert', name: 'Certificate', requires_expiry: false }])), download: vi.fn() } }));

describe('DocumentsRegisterPage', () => {
  it('links each document to its record where the SPA has a page, flags expired ones, and asks for the first page', async () => {
    render(
      <ThemeProvider theme={theme}><SnackbarProvider>
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><DocumentsRegisterPage /></MemoryRouter></QueryClientProvider>
      </SnackbarProvider></ThemeProvider>,
    );
    const voyage = await screen.findByRole('link', { name: 'Voyage · GMS1-26-001' });
    expect(voyage).toHaveAttribute('href', '/operations/voyages/9');
    expect(screen.getByText('User · Sam Smith').closest('a')).toBeNull();   // no SPA page for users: plain text
    expect(screen.getByText(/\(expired\)/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Download Charter party' })).toBeInTheDocument();
    expect(register).toHaveBeenCalledWith(expect.objectContaining({ page: 1 }));
  });
});

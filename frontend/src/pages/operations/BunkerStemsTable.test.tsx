import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from '@mui/material';
import { SnackbarProvider } from 'notistack';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { theme } from '../../theme/theme';
import { AuthContext, type AuthContextValue } from '../../auth/context';
import { P } from '../../constants/permissions';

vi.mock('../../api/bunkers', () => ({ bunkersApi: { list: vi.fn(), save: vi.fn(), deliver: vi.fn(), cancel: vi.fn() } }));
vi.mock('../../api/operations', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  voyagesApi: { ...((await importOriginal<{ voyagesApi: object }>()).voyagesApi), get: vi.fn(), list: vi.fn() },
}));
vi.mock('../../api/masters', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  documentsApi: { types: vi.fn(), upload: vi.fn() },
  referenceApi: { list: vi.fn() },
  companiesApi: { lookup: vi.fn() },
  currenciesApi: { list: vi.fn() },
  vesselsApi: { list: vi.fn() },
}));

import { bunkersApi } from '../../api/bunkers';
import { documentsApi, referenceApi } from '../../api/masters';
import { voyagesApi } from '../../api/operations';
import { BunkerStemsTable } from './BunkerStemsTable';

const stem = { id: 9, stem_number: 'BS-2026-00001', lock_version: 0, status: 'ordered' };

function renderTable(permissions: string[]) {
  vi.mocked(bunkersApi.list).mockResolvedValue({ success: true, message: 'OK', data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } } as never);
  vi.mocked(referenceApi.list).mockResolvedValue([{ id: 2, code: 'HFO', name: 'Heavy fuel oil', is_active: true }] as never);
  vi.mocked(documentsApi.types).mockResolvedValue([{ id: 3, code: 'BDN', name: 'Bunker delivery note', requires_expiry: false }] as never);
  vi.mocked(voyagesApi.get).mockResolvedValue({ id: 5, voyage_number: 'EXT01-26-001', vessel: { id: 1, name: 'MV Example Trader' }, port_calls: [] } as never);
  const auth = {
    user: { permissions, is_super_admin: false, timezone: 'Asia/Dubai' },
    can: (p: string | string[]) => (Array.isArray(p) ? p : [p]).some((x) => permissions.includes(x)),
    isAuthenticated: true,
    isLoading: false,
  } as unknown as AuthContextValue;
  return render(
    <ThemeProvider theme={theme}><SnackbarProvider><QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AuthContext.Provider value={auth}>
        <BunkerStemsTable filters={{ voyage_id: 5 }} preset={{ vessel_id: 1, voyage_id: 5 }} />
      </AuthContext.Provider>
    </QueryClientProvider></SnackbarProvider></ThemeProvider>,
  );
}

async function fillOrder(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: /new order/i }));
  const dialog = await screen.findByRole('dialog');
  await user.click(within(dialog).getByRole('combobox', { name: /fuel/i }));
  await user.click(await screen.findByRole('option', { name: /heavy fuel oil/i }));
  await user.type(within(dialog).getByRole('textbox', { name: /quantity/i }), '44');
  await user.type(within(dialog).getByRole('textbox', { name: /price per mt/i }), '600');
  return dialog;
}

describe('BunkerStemsTable order dialog documents', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('shows the documents section only to users who can upload', async () => {
    renderTable([P.BunkersView, P.BunkersManage]);
    await userEvent.setup().click(await screen.findByRole('button', { name: /new order/i }));
    expect(await screen.findByRole('dialog')).toBeInTheDocument();
    expect(screen.queryByText('Documents (optional)')).not.toBeInTheDocument();
  }, 20_000);

  it('orders the bunkers and then uploads the attached file to the new stem', async () => {
    const user = userEvent.setup();
    renderTable([P.BunkersView, P.BunkersManage, P.DocumentsUpload]);
    vi.mocked(bunkersApi.save).mockResolvedValue({ success: true, message: 'Order created.', data: stem } as never);
    vi.mocked(documentsApi.upload).mockResolvedValue({ success: true, message: 'Document uploaded.', data: { id: 1 } } as never);

    const dialog = await fillOrder(user);
    expect(screen.getByText('Documents (optional)')).toBeInTheDocument();
    const input = dialog.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['bdn'], 'BDN-0301.pdf', { type: 'application/pdf' })] } });
    expect(await within(dialog).findByText(/BDN-0301\.pdf/)).toBeInTheDocument();

    await user.click(within(dialog).getByRole('combobox', { name: /document type/i }));
    await user.click(await screen.findByRole('option', { name: /bunker delivery note/i }));
    await user.click(within(dialog).getByRole('button', { name: 'Order' }));

    await waitFor(() => expect(documentsApi.upload).toHaveBeenCalledTimes(1));
    const [parentType, parentId, form] = vi.mocked(documentsApi.upload).mock.calls[0];
    expect([parentType, parentId]).toEqual(['bunker-stems', 9]);
    expect((form as FormData).get('document_type_id')).toBe('3');
    expect((form as FormData).get('title')).toBe('BDN-0301');
  }, 20_000);

  it('refuses a file larger than 10 MB and keeps the smaller ones', async () => {
    const user = userEvent.setup();
    renderTable([P.BunkersView, P.BunkersManage, P.DocumentsUpload]);
    const dialog = await fillOrder(user);
    const input = dialog.querySelector('input[type="file"]') as HTMLInputElement;
    const big = new File(['x'], 'scan-huge.pdf', { type: 'application/pdf' });
    Object.defineProperty(big, 'size', { value: 10 * 1024 * 1024 + 1 });
    const small = new File(['x'], 'quote.pdf', { type: 'application/pdf' });
    fireEvent.change(input, { target: { files: [big, small] } });
    expect(await within(dialog).findByText(/scan-huge\.pdf is larger than 10 MB/)).toBeInTheDocument();
    expect(within(dialog).getByText(/quote\.pdf/)).toBeInTheDocument();
    expect(within(dialog).queryByText(/scan-huge\.pdf \(/)).not.toBeInTheDocument();
    expect(within(dialog).getByText('PDF, images, Office files, max 10 MB each')).toBeInTheDocument();
  }, 20_000);

  it('keeps the saved order when an upload fails, and retries only the failed upload', async () => {
    const user = userEvent.setup();
    renderTable([P.BunkersView, P.BunkersManage, P.DocumentsUpload]);
    vi.mocked(bunkersApi.save).mockResolvedValue({ success: true, message: 'Order created.', data: stem } as never);
    vi.mocked(documentsApi.upload)
      .mockRejectedValueOnce(Object.assign(new Error('The file type is not allowed.'), { status: 422, code: 'validation_failed', fieldErrors: {} }))
      .mockResolvedValue({ success: true, message: 'Document uploaded.', data: { id: 2 } } as never);

    const dialog = await fillOrder(user);
    const input = dialog.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['x'], 'quote.pdf', { type: 'application/pdf' })] } });
    await user.click(await within(dialog).findByRole('combobox', { name: /document type/i }));
    await user.click(await screen.findByRole('option', { name: /bunker delivery note/i }));
    await user.click(within(dialog).getByRole('button', { name: 'Order' }));

    expect(await within(dialog).findByText(/BS-2026-00001 is saved/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: 'Retry uploads' }));
    await waitFor(() => expect(documentsApi.upload).toHaveBeenCalledTimes(2));
    expect(bunkersApi.save).toHaveBeenCalledTimes(1);
  }, 20_000);
});

import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ThemeProvider } from '@mui/material';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SnackbarProvider } from 'notistack';
import { vi } from 'vitest';
import { theme } from '../theme/theme';
import { AuthContext, type AuthContextValue } from '../auth/context';
import type { ApiEnvelope } from '../types/api';
import type { DocumentItem } from '../types/masters';

vi.mock('../api/masters', () => ({ documentsApi: { file: vi.fn() } }));
import { documentsApi } from '../api/masters';
import { PdfDocumentButton } from './PdfDocumentButton';

const pdf: DocumentItem = {
  id: 17, title: 'Charter offer · OFF-2026-00001-R1', original_filename: 'OFF-2026-00001-R1.pdf',
  document_type: { id: 1, code: 'offer_pdf', name: 'Offer PDF' }, document_number: 'OFF-2026-00001-R1',
  issue_date: '2026-10-05', expiry_date: null, mime_type: 'application/pdf', size_bytes: 1234,
  remarks: null, uploaded_by: null, created_at: '2026-10-05T10:00:00Z',
};
const generate = vi.fn<() => Promise<ApiEnvelope<DocumentItem>>>();
const createUrl = vi.fn(() => 'blob:preview-pdf');
const revokeUrl = vi.fn();
const clients: QueryClient[] = [];
const all = ['chartering.offers.update', 'documents.upload', 'documents.view'];

beforeEach(() => {
  vi.stubGlobal('URL', class extends URL {
    static createObjectURL = createUrl;
    static revokeObjectURL = revokeUrl;
  });
  generate.mockResolvedValue({ success: true, message: 'PDF generated.', data: pdf });
  vi.mocked(documentsApi.file).mockResolvedValue(new Blob(['%PDF-test'], { type: 'application/pdf' }));
});
afterEach(() => {
  cleanup();
  clients.splice(0).forEach((client) => client.clear());
  vi.restoreAllMocks();
  vi.clearAllMocks();
  vi.unstubAllGlobals();
});

function renderButton(permissions = all, disabled = false) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  clients.push(client);
  const invalidate = vi.spyOn(client, 'invalidateQueries');
  const auth = { can: (p: string | string[]) => (Array.isArray(p) ? p : [p]).some((v) => permissions.includes(v)) } as AuthContextValue;
  const rendered = render(
    <ThemeProvider theme={theme}>
      <SnackbarProvider>
        <QueryClientProvider client={client}>
          <AuthContext.Provider value={auth}>
            <PdfDocumentButton parentType="offers" parentId={1} permission="chartering.offers.update" generate={generate} disabled={disabled} />
          </AuthContext.Provider>
        </QueryClientProvider>
      </SnackbarProvider>
    </ThemeProvider>,
  );
  return { ...rendered, invalidate };
}

describe('PdfDocumentButton', () => {
  it('previews authenticated bytes, downloads the same saved file, and releases its blob URL on close', async () => {
    const user = userEvent.setup();
    const { invalidate } = renderButton();
    await user.click(screen.getByRole('button', { name: 'Generate PDF' }));
    expect(await screen.findByRole('dialog', { name: 'PDF preview' }, { timeout: 5000 })).toBeInTheDocument();
    expect(documentsApi.file).toHaveBeenCalledWith(17);
    expect(screen.getByTitle(pdf.title)).toHaveAttribute('src', 'blob:preview-pdf#view=FitH');
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['documents', 'offers', 1] });
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      expect(this.download).toBe(pdf.original_filename);
      expect(this.getAttribute('href')).toBe('blob:preview-pdf');
    });
    await user.click(screen.getByRole('button', { name: 'Download PDF' }));
    expect(click).toHaveBeenCalledOnce();
    expect(generate).toHaveBeenCalledOnce();
    await user.click(screen.getByRole('button', { name: /^Close$/ }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(revokeUrl).toHaveBeenCalledWith('blob:preview-pdf');
  });

  it('keeps generation disabled for unsaved edits and hides it without the required document permission', () => {
    const { unmount } = renderButton(all, true);
    expect(screen.getByRole('button', { name: 'Generate PDF' })).toBeDisabled();
    unmount();
    renderButton(['chartering.offers.update', 'documents.view']);
    expect(screen.queryByRole('button', { name: 'Generate PDF' })).not.toBeInTheDocument();
    expect(generate).not.toHaveBeenCalled();
  });

  it('reports generation errors without opening a preview or downloading a file', async () => {
    generate.mockRejectedValue(new Error('PDF storage unavailable.'));
    const user = userEvent.setup();
    renderButton();
    await user.click(screen.getByRole('button', { name: 'Generate PDF' }));
    expect(await screen.findByText('PDF storage unavailable.', {}, { timeout: 5000 })).toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(documentsApi.file).not.toHaveBeenCalled();
    expect(createUrl).not.toHaveBeenCalled();
  });
});

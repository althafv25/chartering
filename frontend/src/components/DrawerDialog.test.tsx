import { useState } from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Button, DialogActions, DialogContent, TextField, ThemeProvider } from '@mui/material';
import { describe, expect, it, vi } from 'vitest';
import { theme } from '../theme/theme';
import { ConfirmDialog } from './ConfirmDialog';
import { DrawerDialog, DrawerDialogTitle } from './DrawerDialog';

function FormExample({ onSubmit }: { onSubmit: (name: string) => void }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <Button onClick={() => setOpen(true)}>Add record</Button>
      <DrawerDialog open={open} onClose={() => setOpen(false)}>
        <form onSubmit={(event) => { event.preventDefault(); onSubmit(String(new FormData(event.currentTarget).get('name'))); }} noValidate>
          <DrawerDialogTitle>Add record</DrawerDialogTitle>
          <DialogContent><TextField name="name" label="Name" autoFocus /></DialogContent>
          <DialogActions>
            <Button onClick={() => setOpen(false)}>Cancel</Button>
            <Button type="submit">Create record</Button>
          </DialogActions>
        </form>
      </DrawerDialog>
    </>
  );
}

describe('DrawerDialog', () => {
  it('preserves native form submission and restores focus without submitting when closed', async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    render(<ThemeProvider theme={theme}><FormExample onSubmit={onSubmit} /></ThemeProvider>);

    const trigger = screen.getByRole('button', { name: 'Add record' });
    await user.click(trigger);
    expect(screen.getByRole('dialog', { name: 'Add record' })).toHaveAttribute('aria-modal', 'true');
    await user.type(screen.getByRole('textbox', { name: 'Name' }), 'Example company{Enter}');
    expect(onSubmit).toHaveBeenCalledExactlyOnceWith('Example company');
    await user.click(screen.getByRole('button', { name: 'Create record' }));
    expect(onSubmit).toHaveBeenCalledTimes(2);

    await user.click(screen.getByRole('button', { name: 'Close panel' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(onSubmit).toHaveBeenCalledTimes(2);
    expect(trigger).toHaveFocus();
  });

  it('keeps confirmations open while saving, including Escape and backdrop dismissal', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    const onConfirm = vi.fn();
    const panel = (loading: boolean) => (
      <ThemeProvider theme={theme}>
        <ConfirmDialog open title="Delete record" message="Delete this record?" confirmLabel="Delete" danger
          loading={loading} onClose={onClose} onConfirm={onConfirm} />
      </ThemeProvider>
    );
    const { rerender } = render(panel(true));
    expect(screen.getByRole('button', { name: 'Close panel' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Delete' })).toBeDisabled();

    await user.keyboard('{Escape}');
    fireEvent.click(document.querySelector('.MuiBackdrop-root')!);
    expect(onClose).not.toHaveBeenCalled();
    expect(onConfirm).not.toHaveBeenCalled();
    expect(screen.getByRole('dialog', { name: 'Delete record' })).toBeInTheDocument();

    rerender(panel(false));
    await user.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalledTimes(1);
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it('requires the explicit confirmation action rather than the new close button', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    const onConfirm = vi.fn();
    render(
      <ThemeProvider theme={theme}>
        <ConfirmDialog open title="Delete record" message="Delete this record?" confirmLabel="Delete" danger
          onClose={onClose} onConfirm={onConfirm} />
      </ThemeProvider>,
    );
    await user.click(screen.getByRole('button', { name: 'Close panel' }));
    expect(onClose).toHaveBeenCalledTimes(1);
    expect(onConfirm).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Delete' }));
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('gives stacked panels distinct accessible title references, including conditional content', () => {
    render(
      <ThemeProvider theme={theme}>
        <DrawerDialog open onClose={() => {}}>
          <DrawerDialogTitle>Edit record</DrawerDialogTitle>
          <DialogContent>Record details</DialogContent>
          <DrawerDialog open onClose={() => {}}>
            <>
              <DrawerDialogTitle>Confirm changes</DrawerDialogTitle>
              <DialogContent>Review the changes</DialogContent>
            </>
          </DrawerDialog>
        </DrawerDialog>
      </ThemeProvider>,
    );
    const titles = screen.getAllByRole('dialog', { hidden: true }).map((panel) => panel.getAttribute('aria-labelledby'));
    expect(new Set(titles).size).toBe(2);
    expect(titles.map((id) => document.getElementById(id!)?.textContent)).toEqual(['Edit record', 'Confirm changes']);
  });
});

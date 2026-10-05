import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, DialogActions, DialogContent, MenuItem, Stack, TextField } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import { estimationsApi } from '../../api/chartering';
import { vesselsApi } from '../../api/masters';
import { errorMessage } from '../../api/client';
import { LoadingButton } from '../../components/LoadingButton';
import { CurrencySelect } from '../../components/MasterPickers';
import { ESTIMATION_TYPES } from '../../constants/chartering';
import { useNotify } from '../../hooks/useNotify';
import type { Estimation } from '../../types/chartering';

/** New estimation (scenario A is created from snapshot defaults by the API). */
export function NewEstimationDialog({ open, enquiryId, defaultVesselId, onClose, onCreated }: {
  open: boolean;
  enquiryId?: number | null;
  defaultVesselId?: number | null;
  onClose: () => void;
  onCreated: (e: Estimation) => void;
}) {
  const qc = useQueryClient();
  const notify = useNotify();
  const vessels = useQuery({ queryKey: ['vessels', 'active-all'], queryFn: () => vesselsApi.list({ per_page: 100, status: 'active' }), enabled: open });
  const [vesselId, setVesselId] = useState<number | ''>('');
  const [type, setType] = useState('');
  const [currency, setCurrency] = useState<string | null>(null);
  const [title, setTitle] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [lastOpen, setLastOpen] = useState(false);
  if (open !== lastOpen) {
    setLastOpen(open);
    if (open) { setVesselId(defaultVesselId ?? ''); setType(''); setCurrency(null); setTitle(''); setError(null); }
  }

  const create = useMutation({
    mutationFn: () => estimationsApi.create({ enquiry_id: enquiryId ?? null, vessel_id: vesselId, estimation_type: type || null, currency, title: title || null }),
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['estimations'] }); qc.invalidateQueries({ queryKey: ['enquiries'] }); onCreated(r.data); },
    onError: (e) => setError(errorMessage(e)),
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>New estimation</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Stack spacing={2}>
          <TextField select label="Vessel" required value={vesselId} onChange={(e) => setVesselId(Number(e.target.value))}>
            {(vessels.data?.data ?? []).map((v) => <MenuItem key={v.id} value={v.id}>{v.name} ({v.code})</MenuItem>)}
          </TextField>
          <TextField select label="Estimation type" value={type} onChange={(e) => setType(e.target.value)} required={!enquiryId}
            helperText={enquiryId ? 'Leave empty to derive from the enquiry business type' : undefined}>
            {enquiryId && <MenuItem value="">From enquiry</MenuItem>}
            {ESTIMATION_TYPES.map((t) => <MenuItem key={t.value} value={t.value}>{t.label}</MenuItem>)}
          </TextField>
          <CurrencySelect label="Currency" value={currency} onChange={setCurrency} />
          <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} helperText="Optional — defaults to vessel and enquiry" />
          <Alert severity="info">Scenario A is created with a snapshot of the vessel particulars, its current consumption profile and stored distances. Later master-data changes do not affect it unless you refresh defaults.</Alert>
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={create.isPending} disabled={!vesselId || (!enquiryId && !type)} onClick={() => create.mutate()}>Create estimation</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}

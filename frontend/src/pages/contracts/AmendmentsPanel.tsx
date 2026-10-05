import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, CardContent, DialogActions, DialogContent, FormControlLabel, Grid, Stack, Switch, TextField, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import AddIcon from '@mui/icons-material/Add';
import { contractsApi } from '../../api/contracts';
import { errorMessage } from '../../api/client';
import { EmptyState } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { StatusChip } from '../../components/StatusChip';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { formatDate, formatDateTime, humanize } from '../../utils/format';
import type { Amendment, Contract, ContractClause, ContractRate } from '../../types/contracts';
import { ClausesEditor, RatesEditor } from './TermsEditors';
import { clausesValid, currentClauses, currentRates, ratesValid } from './termsHelpers';

type Draft = {
  effective_date: string; summary: string; end_date: string; extension_options: string; payment_terms_days: string; terms: string;
  changeRates: boolean; rates: ContractRate[]; changeClauses: boolean; clauses: ContractClause[];
};

const show = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v));

export function AmendmentsPanel({ contract }: { contract: Contract }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [draft, setDraft] = useState<{ editing?: Amendment; d: Draft } | null>(null);
  const [decision, setDecision] = useState<{ a: Amendment; kind: 'approve' | 'reject' } | null>(null);
  const [text, setText] = useState('');
  const [error, setError] = useState<string | null>(null);
  const amendments = contract.amendments ?? [];
  const open = amendments.some((a) => ['draft', 'submitted'].includes(a.status));
  const refresh = () => qc.invalidateQueries({ queryKey: ['contracts'] });

  const newDraft = (): Draft => ({
    effective_date: new Date().toISOString().slice(0, 10), summary: '', end_date: contract.end_date ?? '', extension_options: contract.extension_options ?? '',
    payment_terms_days: contract.payment_terms_days?.toString() ?? '', terms: contract.terms ?? '',
    changeRates: false, rates: currentRates(contract), changeClauses: false, clauses: currentClauses(contract),
  });
  const fromAmendment = (a: Amendment): Draft => {
    const h = a.proposal.header ?? {};
    const base = newDraft();
    return { ...base, effective_date: a.effective_date, summary: a.summary,
      end_date: (h.end_date as string) ?? base.end_date, extension_options: (h.extension_options as string) ?? base.extension_options,
      payment_terms_days: h.payment_terms_days != null ? String(h.payment_terms_days) : base.payment_terms_days, terms: (h.terms as string) ?? base.terms,
      changeRates: !!a.proposal.rates, rates: a.proposal.rates ?? base.rates, changeClauses: !!a.proposal.clauses, clauses: a.proposal.clauses ?? base.clauses };
  };

  const save = useMutation({
    mutationFn: () => {
      const d = draft!.d;
      const header: Record<string, unknown> = {};
      if (d.end_date !== (contract.end_date ?? '')) header.end_date = d.end_date;
      if (d.extension_options !== (contract.extension_options ?? '')) header.extension_options = d.extension_options || null;
      if (d.payment_terms_days !== (contract.payment_terms_days?.toString() ?? '')) header.payment_terms_days = d.payment_terms_days === '' ? null : Number(d.payment_terms_days);
      if (d.terms !== (contract.terms ?? '')) header.terms = d.terms || null;
      const body = { effective_date: d.effective_date, summary: d.summary, header: Object.keys(header).length ? header : null,
        rates: d.changeRates ? d.rates : null, clauses: d.changeClauses ? d.clauses : null };
      return draft!.editing
        ? contractsApi.updateAmendment(contract.id, draft!.editing.id, { ...body, lock_version: draft!.editing.lock_version })
        : contractsApi.createAmendment(contract.id, body);
    },
    onSuccess: (r) => { notify.success(r.message); setDraft(null); setError(null); refresh(); },
    onError: (e) => setError(errorMessage(e)),
  });
  const act = useMutation({
    mutationFn: ({ a, kind }: { a: Amendment; kind: 'submit' | 'approve' | 'reject' | 'withdraw' }) =>
      contractsApi.amendmentAction(contract.id, a.id, kind, kind === 'reject' ? { reason: text } : kind === 'approve' && text ? { comment: text } : {}),
    onSuccess: (r) => { notify.success(r.message); setDecision(null); setText(''); refresh(); },
    onError: (e) => notify.error(e),
  });

  const d = draft?.d;
  const setD = (patch: Partial<Draft>) => setDraft((x) => x && { ...x, d: { ...x.d, ...patch } });
  const valid = d && d.summary.trim().length >= 5 && d.effective_date && (!d.changeRates || (d.rates.length > 0 && ratesValid(d.rates))) && (!d.changeClauses || clausesValid(d.clauses));

  return (
    <Box sx={{ p: 2 }}>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 2 }}>
        <Typography variant="body2" color="text.secondary">After approval, terms change only by amendment. Each approved amendment creates a new version effective from its date; earlier versions are kept unchanged.</Typography>
        {contract.is_amendable && can(P.ContractsAmend) && !open && <Button variant="outlined" startIcon={<AddIcon />} onClick={() => setDraft({ d: newDraft() })}>New amendment</Button>}
      </Stack>
      {!contract.is_amendable && <Alert severity="info" sx={{ mb: 2 }}>Amendments are possible only for approved or active contracts.</Alert>}
      {amendments.length === 0 ? <EmptyState title="No amendments" /> : (
        <Stack spacing={1.5}>
          {amendments.map((a) => (
            <Card key={a.id} variant="outlined">
              <CardContent>
                <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" spacing={1}>
                  <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                    <Typography variant="subtitle1" fontWeight={700}>Amendment {a.amendment_no}</Typography>
                    <StatusChip status={a.status} />
                    <Typography variant="body2" color="text.secondary">effective {formatDate(a.effective_date)}{a.resulting_version ? ` → version ${a.resulting_version}` : ''}</Typography>
                  </Stack>
                  <Stack direction="row" spacing={1}>
                    {a.status === 'draft' && can(P.ContractsAmend) && <Button size="small" onClick={() => setDraft({ editing: a, d: fromAmendment(a) })}>Edit</Button>}
                    {a.status === 'draft' && can(P.ContractsAmend) && <Button size="small" variant="contained" onClick={() => act.mutate({ a, kind: 'submit' })}>Submit</Button>}
                    {['draft', 'submitted'].includes(a.status) && can(P.ContractsAmend) && <Button size="small" onClick={() => act.mutate({ a, kind: 'withdraw' })}>Withdraw</Button>}
                    {a.status === 'submitted' && can(P.ContractsApprove) && <Button size="small" color="error" onClick={() => setDecision({ a, kind: 'reject' })}>Reject</Button>}
                    {a.status === 'submitted' && can(P.ContractsApprove) && <Button size="small" variant="contained" color="success" onClick={() => setDecision({ a, kind: 'approve' })}>Approve</Button>}
                  </Stack>
                </Stack>
                <Typography fontSize={14} sx={{ mt: 1 }}>{a.summary}</Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
                  Proposes: {[a.proposal.header && Object.keys(a.proposal.header).map(humanize).join(', '), a.proposal.rates && 'new rate set', a.proposal.clauses && 'new clause set'].filter(Boolean).join(' · ') || (a.changes?.rates === 'hidden' ? 'rates (hidden)' : '—')}
                </Typography>
                {a.status === 'approved' && a.changes && typeof a.changes.header === 'object' && a.changes.header && (
                  <Box sx={{ mt: 1 }}>
                    {Object.entries(a.changes.header as Record<string, { from: unknown; to: unknown }>).map(([k, v]) => (
                      <Typography key={k} variant="body2"><b>{humanize(k)}</b>: <Box component="span" color="error.main">{show(v.from)}</Box> → <Box component="span" color="success.main">{show(v.to)}</Box></Typography>
                    ))}
                  </Box>
                )}
                <Typography variant="caption" color="text.secondary" display="block" sx={{ mt: 1 }}>
                  Created {formatDateTime(a.created_at)}{a.created_by ? ` by ${a.created_by}` : ''}{a.decided_at ? ` · ${a.status} ${formatDateTime(a.decided_at)}${a.decided_by ? ` by ${a.decided_by}` : ''}` : ''}{a.decision_comment ? ` — ${a.decision_comment}` : ''}
                </Typography>
              </CardContent>
            </Card>
          ))}
        </Stack>
      )}

      <Dialog open={!!draft} onClose={() => setDraft(null)} maxWidth="lg" fullWidth>
        <DialogTitle>{draft?.editing ? `Edit amendment ${draft.editing.amendment_no}` : 'New amendment'}</DialogTitle>
        {d && (
          <DialogContent dividers>
            {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
            <Grid container spacing={2}>
              <Grid size={{ xs: 12, sm: 3 }}><TextField type="date" label="Effective date" required value={d.effective_date} onChange={(e) => setD({ effective_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
              <Grid size={{ xs: 12, sm: 9 }}><TextField label="Summary" required value={d.summary} onChange={(e) => setD({ summary: e.target.value })} helperText="e.g. Extension option 1 declared; day rate escalation" /></Grid>
              <Grid size={{ xs: 12, sm: 3 }}><TextField type="date" label="End date" value={d.end_date} onChange={(e) => setD({ end_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} helperText="Extensions = new end date" /></Grid>
              <Grid size={{ xs: 12, sm: 3 }}><TextField label="Payment terms (days)" value={d.payment_terms_days} onChange={(e) => setD({ payment_terms_days: e.target.value.replace(/\D/g, '') })} /></Grid>
              <Grid size={{ xs: 12, sm: 6 }}><TextField label="Extension options" value={d.extension_options} onChange={(e) => setD({ extension_options: e.target.value })} /></Grid>
              <Grid size={12}><TextField label="Terms" multiline minRows={2} value={d.terms} onChange={(e) => setD({ terms: e.target.value })} /></Grid>
            </Grid>
            {contract.rates_visible && (
              <Box sx={{ mt: 2 }}>
                <FormControlLabel control={<Switch checked={d.changeRates} onChange={(e) => setD({ changeRates: e.target.checked })} />} label="Change rates (replace the whole rate set for the new version)" />
                {d.changeRates && <RatesEditor rates={d.rates} currency={contract.currency} onChange={(rates) => setD({ rates })} />}
              </Box>
            )}
            <Box sx={{ mt: 2 }}>
              <FormControlLabel control={<Switch checked={d.changeClauses} onChange={(e) => setD({ changeClauses: e.target.checked })} />} label="Change clauses" />
              {d.changeClauses && <ClausesEditor clauses={d.clauses} onChange={(clauses) => setD({ clauses })} />}
            </Box>
          </DialogContent>
        )}
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setDraft(null)}>Cancel</Button>
          <LoadingButton variant="contained" loading={save.isPending} disabled={!valid} onClick={() => save.mutate()}>Save draft</LoadingButton>
        </DialogActions>
      </Dialog>

      <Dialog open={!!decision} onClose={() => setDecision(null)} maxWidth="xs" fullWidth>
        <DialogTitle>{decision?.kind === 'approve' ? `Approve amendment ${decision.a.amendment_no}` : `Reject amendment ${decision?.a.amendment_no}`}</DialogTitle>
        <DialogContent dividers>
          {decision?.kind === 'approve' && <Alert severity="info" sx={{ mb: 2 }}>Creates contract version {contract.current_version + 1} effective {formatDate(decision.a.effective_date)}.</Alert>}
          <TextField label={decision?.kind === 'reject' ? 'Reason' : 'Comment (optional)'} required={decision?.kind === 'reject'} multiline minRows={2} value={text} onChange={(e) => setText(e.target.value)} />
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setDecision(null)}>Cancel</Button>
          <LoadingButton variant="contained" color={decision?.kind === 'approve' ? 'success' : 'error'} loading={act.isPending}
            disabled={decision?.kind === 'reject' && text.trim().length < 3} onClick={() => decision && act.mutate({ a: decision.a, kind: decision.kind })}>Confirm</LoadingButton>
        </DialogActions>
      </Dialog>
    </Box>
  );
}

import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, CardContent, Chip, DialogActions, DialogContent, FormControlLabel, Grid, IconButton, MenuItem, Stack, Switch, Tab, Tabs, TextField, Tooltip, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import EditOutlined from '@mui/icons-material/EditOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import AddIcon from '@mui/icons-material/Add';
import StarIcon from '@mui/icons-material/Star';
import { companiesApi } from '../../api/masters';
import { errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { KeyValueGrid } from '../../components/KeyValueGrid';
import { DataTable, type Column } from '../../components/DataTable';
import { DocumentsPanel } from '../../components/DocumentsPanel';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { CurrencySelect } from '../../components/MasterPickers';
import { StatusChip } from '../../components/StatusChip';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { countryName } from '../../constants/countries';
import { useNotify } from '../../hooks/useNotify';
import { formatDate, humanize } from '../../utils/format';
import type { BankAccount, Contact } from '../../types/masters';
import { CompanyDrawer } from './CompanyDrawer';

type ContactForm = Omit<Contact, 'id' | 'company_id' | 'full_name'>;
const emptyContact: ContactForm = { first_name: '', last_name: '', job_title: '', department: '', email: '', phone: '', mobile: '', is_primary: false, remarks: '' };

export default function CompanyDetailPage() {
  const id = Number(useParams().id);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [tab, setTab] = useState('overview');
  const [editOpen, setEditOpen] = useState(false);
  const [contact, setContact] = useState<{ open: boolean; id?: number; form: ContactForm }>({ open: false, form: emptyContact });
  const [bankOpen, setBankOpen] = useState(false);
  const [confirm, setConfirm] = useState<{ title: string; message: string; run: () => Promise<unknown> } | null>(null);
  const [aliasText, setAliasText] = useState('');

  const company = useQuery({ queryKey: ['companies', id], queryFn: () => companiesApi.get(id) });
  const refresh = () => qc.invalidateQueries({ queryKey: ['companies'] });
  const canEdit = can(P.CompaniesUpdate);

  const confirmRun = useMutation({
    mutationFn: () => confirm!.run(),
    onSuccess: () => { setConfirm(null); refresh(); notify.success('Done.'); },
    onError: (e) => notify.error(e),
  });

  const saveContact = useMutation({
    mutationFn: () => {
      const body = Object.fromEntries(Object.entries(contact.form).map(([k, v]) => [k, v === '' ? null : v]));
      return contact.id ? companiesApi.updateContact(id, contact.id, body) : companiesApi.addContact(id, body);
    },
    onSuccess: (r) => { notify.success(r.message); setContact({ open: false, form: emptyContact }); refresh(); },
    onError: (e) => notify.error(e),
  });

  const addAlias = useMutation({
    mutationFn: () => companiesApi.addAlias(id, { alias: aliasText, reason: 'abbreviation' }),
    onSuccess: () => { setAliasText(''); refresh(); },
    onError: (e) => notify.error(e),
  });

  if (company.isLoading) return <SectionLoader />;
  if (company.isError || !company.data) return <ErrorState error={company.error} onRetry={() => company.refetch()} />;
  const c = company.data;

  const contactColumns: Column<Contact>[] = [
    { key: 'name', header: 'Name', render: (x) => (
      <Stack direction="row" spacing={0.75} alignItems="center">
        {x.is_primary && <Tooltip title="Primary contact"><StarIcon fontSize="small" color="warning" /></Tooltip>}
        <Box><Typography fontWeight={600} fontSize={14}>{x.full_name}</Typography><Typography variant="body2" color="text.secondary">{[x.job_title, x.department].filter(Boolean).join(' · ') || '—'}</Typography></Box>
      </Stack>
    ) },
    { key: 'email', header: 'Email', render: (x) => x.email || '—' },
    { key: 'phone', header: 'Phone / mobile', hideBelow: 'md', render: (x) => [x.phone, x.mobile].filter(Boolean).join(' · ') || '—' },
    { key: 'actions', header: '', align: 'right', render: (x) => canEdit && (
      <Stack direction="row" justifyContent="flex-end">
        <IconButton size="small" aria-label={`Edit ${x.full_name}`} onClick={() => setContact({ open: true, id: x.id, form: { ...emptyContact, ...Object.fromEntries(Object.entries(x).map(([k, v]) => [k, v ?? ''])) } as ContactForm })}><EditOutlined fontSize="small" /></IconButton>
        <IconButton size="small" color="error" aria-label={`Remove ${x.full_name}`} onClick={() => setConfirm({ title: 'Remove contact', message: `Remove ${x.full_name}?`, run: () => companiesApi.removeContact(id, x.id) })}><DeleteOutline fontSize="small" /></IconButton>
      </Stack>
    ) },
  ];

  const bankColumns: Column<BankAccount>[] = [
    { key: 'bank', header: 'Bank', render: (b) => <Stack direction="row" spacing={0.75} alignItems="center">{b.is_primary && <StarIcon fontSize="small" color="warning" />}<span>{b.bank_name}</span></Stack> },
    { key: 'acc', header: 'Account', render: (b) => <Box><div>{b.account_name || '—'}</div><Typography variant="body2" color="text.secondary" fontFamily="monospace">{b.account_number || '—'}</Typography></Box> },
    { key: 'iban', header: 'IBAN / SWIFT', render: (b) => <Typography variant="body2" fontFamily="monospace">{b.iban || '—'}<br />{b.swift_bic || ''}</Typography> },
    { key: 'ccy', header: 'Currency', render: (b) => b.currency || '—' },
    { key: 'actions', header: '', align: 'right', render: (b) => canEdit && can(P.CompaniesBankView) && (
      <IconButton size="small" color="error" aria-label="Remove bank account" onClick={() => setConfirm({ title: 'Remove bank account', message: `Remove ${b.bank_name} account?`, run: () => companiesApi.removeBank(id, b.id) })}><DeleteOutline fontSize="small" /></IconButton>
    ) },
  ];

  const setC = (k: keyof ContactForm) => (e: React.ChangeEvent<HTMLInputElement>) => setContact((s) => ({ ...s, form: { ...s.form, [k]: e.target.value } }));

  return (
    <>
      <PageHeader
        title={c.legal_name}
        subtitle={`${c.code}${c.trading_name ? ` · trading as ${c.trading_name}` : ''}`}
        breadcrumbs={[{ label: 'Masters' }, { label: 'Companies', to: '/masters/companies' }, { label: c.legal_name }]}
        actions={<>
          {can(P.CompaniesDelete) && <Button color="error" onClick={() => setConfirm({ title: 'Delete company', message: `Delete ${c.legal_name}? Existing references are kept.`, run: async () => { await companiesApi.remove(id); navigate('/masters/companies'); } })}>Delete</Button>}
          {canEdit && <Button variant="contained" startIcon={<EditOutlined />} onClick={() => setEditOpen(true)}>Edit</Button>}
        </>}
      />
      <Stack direction="row" spacing={1} sx={{ mb: 2 }} flexWrap="wrap">
        <StatusChip status={c.status} />
        {c.roles.map((r) => <Chip key={r} size="small" label={humanize(r)} color="secondary" />)}
      </Stack>

      <Card>
        <Tabs value={tab} onChange={(_, v) => setTab(v)} variant="scrollable" sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="overview" label="Overview" />
          <Tab value="contacts" label={`Contacts (${c.contacts?.length ?? 0})`} />
          <Tab value="bank" label="Bank details" />
          <Tab value="documents" label="Documents" />
        </Tabs>

        {tab === 'overview' && (
          <CardContent>
            <KeyValueGrid items={[
              ['Country', countryName(c.country)], ['City', c.city], ['Postal code', c.postal_code],
              ['Address', [c.address_line1, c.address_line2].filter(Boolean).join(', ')], ['Email', c.email], ['Phone', c.phone],
              ['Website', c.website], ['VAT / tax number', c.tax_number], ['VAT registered', c.vat_registered ? 'Yes' : 'No'],
              ['Default currency', c.default_currency], ['Payment terms', c.payment_terms_days != null ? `${c.payment_terms_days} days` : null],
              ['Credit limit', c.credit_limit ? `${c.credit_limit} ${c.default_currency ?? ''}` : null],
            ]} />
            {c.remarks && <Box sx={{ mt: 3 }}><Typography variant="subtitle2">Remarks</Typography><Typography whiteSpace="pre-wrap" fontSize={14}>{c.remarks}</Typography></Box>}
            <Box sx={{ mt: 3 }}>
              <Typography variant="subtitle2" gutterBottom>Also known as</Typography>
              <Stack direction="row" gap={1} flexWrap="wrap" alignItems="center">
                {c.aliases?.length ? c.aliases.map((a) => (
                  <Chip key={a.id} label={`${a.alias}${a.reason === 'former_name' ? ` (former name${a.valid_to ? ` until ${formatDate(a.valid_to)}` : ''})` : ''}`}
                    onDelete={canEdit ? () => setConfirm({ title: 'Remove alias', message: `Remove alias “${a.alias}”?`, run: () => companiesApi.removeAlias(id, a.id) }) : undefined} />
                )) : <Typography variant="body2" color="text.secondary">No aliases. Former names are added automatically when the legal name changes.</Typography>}
              </Stack>
              {canEdit && (
                <Stack direction="row" spacing={1} sx={{ mt: 1.5, maxWidth: 480 }}>
                  <TextField label="Add alias / abbreviation" value={aliasText} onChange={(e) => setAliasText(e.target.value)} />
                  <LoadingButton variant="outlined" loading={addAlias.isPending} disabled={!aliasText.trim()} onClick={() => addAlias.mutate()}>Add</LoadingButton>
                </Stack>
              )}
            </Box>
          </CardContent>
        )}

        {tab === 'contacts' && (
          <Box>
            {canEdit && <Stack direction="row" justifyContent="flex-end" sx={{ p: 2, pb: 0 }}><Button startIcon={<AddIcon />} variant="outlined" onClick={() => setContact({ open: true, form: emptyContact })}>Add contact</Button></Stack>}
            <DataTable columns={contactColumns} rows={c.contacts ?? []} rowKey={(x) => x.id} emptyTitle="No contact persons yet" />
          </Box>
        )}

        {tab === 'bank' && (
          <Box>
            {!can(P.CompaniesBankView) && <Alert severity="info" sx={{ m: 2 }}>Account numbers are masked. Finance or Accounts can see full details.</Alert>}
            {canEdit && can(P.CompaniesBankView) && <Stack direction="row" justifyContent="flex-end" sx={{ p: 2, pb: 0 }}><Button startIcon={<AddIcon />} variant="outlined" onClick={() => setBankOpen(true)}>Add bank account</Button></Stack>}
            <DataTable columns={bankColumns} rows={c.bank_accounts ?? []} rowKey={(b) => b.id} emptyTitle="No bank accounts" />
          </Box>
        )}

        {tab === 'documents' && <DocumentsPanel parentType="companies" parentId={id} canEdit={canEdit} />}
      </Card>

      <CompanyDrawer open={editOpen} company={c} onClose={() => setEditOpen(false)} />

      <Dialog open={contact.open} onClose={() => setContact({ open: false, form: emptyContact })} maxWidth="sm" fullWidth>
        <DialogTitle>{contact.id ? 'Edit contact' : 'Add contact'}</DialogTitle>
        <DialogContent dividers>
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="First name" required value={contact.form.first_name} onChange={setC('first_name')} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Last name" value={contact.form.last_name ?? ''} onChange={setC('last_name')} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Job title" value={contact.form.job_title ?? ''} onChange={setC('job_title')} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Department" value={contact.form.department ?? ''} onChange={setC('department')} /></Grid>
            <Grid size={12}><TextField label="Email" type="email" value={contact.form.email ?? ''} onChange={setC('email')} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Phone" value={contact.form.phone ?? ''} onChange={setC('phone')} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Mobile" value={contact.form.mobile ?? ''} onChange={setC('mobile')} /></Grid>
            <Grid size={12}><TextField label="Remarks" multiline minRows={2} value={contact.form.remarks ?? ''} onChange={setC('remarks')} /></Grid>
            <Grid size={12}><FormControlLabel control={<Switch checked={contact.form.is_primary} onChange={(e) => setContact((s) => ({ ...s, form: { ...s.form, is_primary: e.target.checked } }))} />} label="Primary contact" /></Grid>
          </Grid>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={() => setContact({ open: false, form: emptyContact })}>Cancel</Button>
          <LoadingButton variant="contained" loading={saveContact.isPending} disabled={!contact.form.first_name.trim()} onClick={() => saveContact.mutate()}>Save contact</LoadingButton>
        </DialogActions>
      </Dialog>

      <BankDialog open={bankOpen} companyId={id} onClose={() => setBankOpen(false)} onSaved={refresh} />

      <ConfirmDialog open={!!confirm} title={confirm?.title ?? ''} message={confirm?.message ?? ''} confirmLabel="Confirm" danger
        loading={confirmRun.isPending} onConfirm={() => confirmRun.mutate()} onClose={() => setConfirm(null)} />
    </>
  );
}

function BankDialog({ open, companyId, onClose, onSaved }: { open: boolean; companyId: number; onClose: () => void; onSaved: () => void }) {
  const notify = useNotify();
  const empty = { bank_name: '', account_name: '', account_number: '', iban: '', swift_bic: '', currency: null as string | null, is_primary: false };
  const [form, setForm] = useState(empty);
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: () => companiesApi.addBank(companyId, Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))),
    onSuccess: (r) => { notify.success(r.message); setForm(empty); onSaved(); onClose(); },
    onError: (e) => setError(errorMessage(e)),
  });
  const set = (k: keyof typeof empty) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [k]: e.target.value }));

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Add bank account</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Grid container spacing={2}>
          <Grid size={12}><TextField label="Bank name" required value={form.bank_name} onChange={set('bank_name')} /></Grid>
          <Grid size={{ xs: 12, sm: 6 }}><TextField label="Account name" value={form.account_name} onChange={set('account_name')} /></Grid>
          <Grid size={{ xs: 12, sm: 6 }}><TextField label="Account number" value={form.account_number} onChange={set('account_number')} /></Grid>
          <Grid size={{ xs: 12, sm: 8 }}><TextField label="IBAN" value={form.iban} onChange={set('iban')} /></Grid>
          <Grid size={{ xs: 12, sm: 4 }}><TextField label="SWIFT / BIC" value={form.swift_bic} onChange={set('swift_bic')} /></Grid>
          <Grid size={{ xs: 12, sm: 6 }}><CurrencySelect label="Currency" value={form.currency} onChange={(v) => setForm((f) => ({ ...f, currency: v }))} /></Grid>
          <Grid size={{ xs: 12, sm: 6 }}><TextField select label="Primary" value={form.is_primary ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_primary: e.target.value === '1' }))}><MenuItem value="0">No</MenuItem><MenuItem value="1">Yes</MenuItem></TextField></Grid>
        </Grid>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <LoadingButton variant="contained" loading={save.isPending} disabled={!form.bank_name.trim()} onClick={() => save.mutate()}>Save</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}

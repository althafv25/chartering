import { useEffect, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Alert, AlertTitle, Autocomplete, Button, Chip, DialogActions, DialogContent, FormControlLabel, Grid, MenuItem, Switch, TextField } from '@mui/material';
import { DrawerDialog, DrawerDialogTitle } from '../../components/DrawerDialog';
import { companiesApi } from '../../api/masters';
import { ApiError } from '../../api/client';
import { LoadingButton } from '../../components/LoadingButton';
import { CountrySelect, CurrencySelect } from '../../components/MasterPickers';
import { COMPANY_ROLES } from '../../constants/masters';
import { decimalPattern } from '../../constants/masters';
import { useNotify } from '../../hooks/useNotify';
import { applyServerErrors } from '../../utils/forms';
import { humanize } from '../../utils/format';
import type { Company } from '../../types/masters';

const opt = z.string().trim().optional().or(z.literal(''));
const schema = z.object({
  legal_name: z.string().trim().min(1, 'Required').max(200),
  trading_name: opt,
  roles: z.array(z.string()).min(1, 'Select at least one role'),
  country: z.string().nullable(),
  city: opt,
  address_line1: opt,
  address_line2: opt,
  postal_code: opt,
  email: z.string().trim().email('Enter a valid email').optional().or(z.literal('')),
  phone: opt,
  website: z.string().trim().url('Include http:// or https://').optional().or(z.literal('')),
  tax_number: opt,
  vat_registered: z.boolean(),
  default_currency: z.string().nullable(),
  payment_terms_days: z.string().regex(/^\d{0,3}$/, 'Whole days').optional().or(z.literal('')),
  credit_limit: z.string().regex(decimalPattern(2), 'Number with up to 2 decimals').optional().or(z.literal('')),
  status: z.enum(['active', 'inactive', 'blocked']),
  remarks: opt,
});
type Form = z.infer<typeof schema>;

const toForm = (c: Company | null): Form => ({
  legal_name: c?.legal_name ?? '', trading_name: c?.trading_name ?? '', roles: c?.roles ?? [], country: c?.country ?? null,
  city: c?.city ?? '', address_line1: c?.address_line1 ?? '', address_line2: c?.address_line2 ?? '', postal_code: c?.postal_code ?? '',
  email: c?.email ?? '', phone: c?.phone ?? '', website: c?.website ?? '', tax_number: c?.tax_number ?? '', vat_registered: c?.vat_registered ?? false,
  default_currency: c?.default_currency ?? null, payment_terms_days: c?.payment_terms_days?.toString() ?? '', credit_limit: c?.credit_limit ?? '',
  status: c?.status ?? 'active', remarks: c?.remarks ?? '',
});

/** Empty strings → null so optional fields can be cleared. */
const toPayload = (v: Form) => Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x === '' ? null : x]));

export function CompanyDrawer({ open, company, defaultRole, onClose, onSaved }: {
  open: boolean;
  company: Company | null;
  defaultRole?: string;
  onClose: () => void;
  onSaved?: (c: Company) => void;
}) {
  const qc = useQueryClient();
  const notify = useNotify();
  const [duplicates, setDuplicates] = useState<string[] | null>(null);
  const { register, control, handleSubmit, reset, setError, formState: { errors } } = useForm<Form>({ resolver: zodResolver(schema) });

  useEffect(() => {
    if (open) {
      reset({ ...toForm(company), roles: company?.roles ?? (defaultRole ? [defaultRole] : []) });
      setDuplicates(null);
    }
  }, [open, company, defaultRole, reset]);

  const save = useMutation({
    mutationFn: ({ values, confirm }: { values: Form; confirm: boolean }) => {
      const body = { ...toPayload(values), ...(confirm ? { confirm_duplicate: true } : {}) };
      return company ? companiesApi.update(company.id, { ...body, lock_version: company.lock_version }) : companiesApi.create(body);
    },
    onSuccess: (r) => {
      notify.success(r.message);
      qc.invalidateQueries({ queryKey: ['companies'] });
      onSaved?.(r.data);
      onClose();
    },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'possible_duplicate') {
        setDuplicates(e.fieldErrors.duplicates ?? []);
        return;
      }
      if (!applyServerErrors(e, setError)) notify.error(e);
    },
  });

  const submit = (confirm: boolean) => handleSubmit((values) => save.mutate({ values, confirm }));

  return (
    <DrawerDialog open={open} onClose={save.isPending ? undefined : onClose} width={720} closeLabel="Close company form">
      <form onSubmit={submit(false)} noValidate>
        <DrawerDialogTitle>{company ? `Edit ${company.legal_name}` : 'Add company'}</DrawerDialogTitle>
        <DialogContent>
          {duplicates && (
            <Alert severity="warning" sx={{ mb: 2 }} action={<Button color="inherit" size="small" disabled={save.isPending} onClick={submit(true)}>Save anyway</Button>}>
              <AlertTitle>Possible duplicate</AlertTitle>
              {duplicates.map((d) => <div key={d}>{d}</div>)}
            </Alert>
          )}
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Legal name" required autoFocus {...register('legal_name')} error={!!errors.legal_name} helperText={errors.legal_name?.message} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Trading name" {...register('trading_name')} /></Grid>
            <Grid size={{ xs: 12, sm: 8 }}>
              <Controller control={control} name="roles" render={({ field }) => (
                <Autocomplete multiple size="small" options={[...COMPANY_ROLES]} value={field.value ?? []} onChange={(_, v) => field.onChange(v)}
                  getOptionLabel={humanize}
                  renderValue={(vals, getItemProps) => vals.map((v, index) => { const { key, ...p } = getItemProps({ index }); return <Chip key={key} size="small" label={humanize(v)} {...p} />; })}
                  renderInput={(p) => <TextField {...p} label="Roles" required error={!!errors.roles} helperText={errors.roles?.message ?? 'Owner, charterer, broker, agent, supplier…'} />} />
              )} />
            </Grid>
            <Grid size={{ xs: 12, sm: 4 }}>
              <Controller control={control} name="status" render={({ field }) => (
                <TextField select label="Status" value={field.value ?? 'active'} onChange={field.onChange}>
                  <MenuItem value="active">Active</MenuItem><MenuItem value="inactive">Inactive</MenuItem><MenuItem value="blocked">Blocked</MenuItem>
                </TextField>
              )} />
            </Grid>
            <Grid size={{ xs: 12, sm: 4 }}>
              <Controller control={control} name="country" render={({ field }) => <CountrySelect label="Country" value={field.value} onChange={field.onChange} error={errors.country?.message} />} />
            </Grid>
            <Grid size={{ xs: 12, sm: 4 }}><TextField label="City" {...register('city')} /></Grid>
            <Grid size={{ xs: 12, sm: 4 }}><TextField label="Postal code" {...register('postal_code')} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Address line 1" {...register('address_line1')} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Address line 2" {...register('address_line2')} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Email" type="email" {...register('email')} error={!!errors.email} helperText={errors.email?.message} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Phone" {...register('phone')} /></Grid>
            <Grid size={12}><TextField label="Website" {...register('website')} error={!!errors.website} helperText={errors.website?.message} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="VAT / tax number" {...register('tax_number')} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <Controller control={control} name="default_currency" render={({ field }) => <CurrencySelect label="Default currency" value={field.value} onChange={field.onChange} />} />
            </Grid>
            <Grid size={12}>
              <Controller control={control} name="vat_registered" render={({ field }) => (
                <FormControlLabel control={<Switch checked={!!field.value} onChange={(e) => field.onChange(e.target.checked)} />} label="VAT registered" />
              )} />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Payment terms (days)" inputMode="numeric" {...register('payment_terms_days')} error={!!errors.payment_terms_days} helperText={errors.payment_terms_days?.message} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Credit limit" inputMode="decimal" {...register('credit_limit')} error={!!errors.credit_limit} helperText={errors.credit_limit?.message} /></Grid>
            <Grid size={12}><TextField label="Remarks" multiline minRows={2} {...register('remarks')} /></Grid>
          </Grid>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} disabled={save.isPending}>Cancel</Button>
          <LoadingButton type="submit" variant="contained" loading={save.isPending}>{company ? 'Save changes' : 'Create company'}</LoadingButton>
        </DialogActions>
      </form>
    </DrawerDialog>
  );
}

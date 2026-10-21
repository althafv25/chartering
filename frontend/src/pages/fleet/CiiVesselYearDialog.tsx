import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, DialogActions, DialogContent, MenuItem, Stack, TextField, Typography } from '@mui/material';
import SpeedOutlined from '@mui/icons-material/SpeedOutlined';
import LocalGasStationOutlined from '@mui/icons-material/LocalGasStationOutlined';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import { LoadingButton } from '../../components/LoadingButton';
import { FormFieldGrid, FormLayout, FormSection } from '../../components/FormSection';
import { StatusChip } from '../../components/StatusChip';
import { ciiApi } from '../../api/cii';
import { vesselsApi } from '../../api/masters';
import { referenceApi } from '../../api/masters';
import { errorMessage } from '../../api/client';
import { useNotify } from '../../hooks/useNotify';
import { isDecimal } from '../../utils/decimal';
import { Can } from '../../auth/guards';
import { P } from '../../constants/permissions';
import type { CiiVesselYear } from '../../types/cii';

interface Props {
  open: boolean;
  onClose: () => void;
  year: CiiVesselYear | null;
  vesselId?: number;
}

export function CiiVesselYearDialog({ open, onClose, year, vesselId }: Props) {
  const qc = useQueryClient();
  const notify = useNotify();
  const [vessel, setVessel] = useState<number | ''>('');
  const [reportingYear, setReportingYear] = useState(String(new Date().getFullYear()));
  const [formulaSetId, setFormulaSetId] = useState<number | ''>('');
  const [capacityMetric, setCapacityMetric] = useState<'DWT' | 'GT'>('DWT');
  const [capacityValue, setCapacityValue] = useState('');
  const [distance, setDistance] = useState('');
  const [fuels, setFuels] = useState<{ code: string; tonnes: string }[]>([{ code: '', tonnes: '' }]);
  const [error, setError] = useState<string | null>(null);

  const vessels = useQuery({ queryKey: ['vessels', 'active-all'], queryFn: () => vesselsApi.list({ per_page: 100, status: 'active' }), enabled: open });
  const fuelTypes = useQuery({ queryKey: ['reference', 'fuel-types', 'active'], queryFn: () => referenceApi.list('fuel-types', true), enabled: open, staleTime: 5 * 60_000 });
  const formulaSets = useQuery({ queryKey: ['cii', 'formula-sets'], queryFn: () => ciiApi.formulaSets().then((r) => r.data), enabled: open });

  useEffect(() => {
    if (!open) return;
    setError(null);
    if (year) {
      setVessel(year.vessel_id);
      setReportingYear(String(year.reporting_year));
      setFormulaSetId(year.formula_set_id);
      setCapacityMetric(year.capacity_metric);
      setCapacityValue(year.capacity_value);
      setDistance(year.distance_sailed_nm);
      const entries = Object.entries(year.fuel_consumption ?? {});
      setFuels(entries.length ? entries.map(([code, tonnes]) => ({ code, tonnes })) : [{ code: '', tonnes: '' }]);
    } else {
      setVessel(vesselId ?? '');
      setReportingYear(String(new Date().getFullYear()));
      setFormulaSetId('');
      setCapacityMetric('DWT');
      setCapacityValue('');
      setDistance('');
      setFuels([{ code: '', tonnes: '' }]);
    }
  }, [open, year, vesselId]);

  const isEditable = !year || year.status !== 'verified';
  const isNew = !year;

  const save = useMutation({
    mutationFn: () => {
      const fuel_consumption = Object.fromEntries(fuels.filter((f) => f.code && f.tonnes).map((f) => [f.code, f.tonnes]));
      return ciiApi.save(year?.id ?? null, {
        ...(isNew ? { vessel_id: vessel, reporting_year: Number(reportingYear) } : { lock_version: year.lock_version }),
        formula_set_id: formulaSetId, capacity_metric: capacityMetric, capacity_value: capacityValue,
        distance_sailed_nm: distance, fuel_consumption,
      });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['cii', 'vessel-years'] });
      notify.success(isNew ? 'CII record created.' : 'CII record updated.');
      onClose();
    },
    onError: (e) => setError(errorMessage(e)),
  });

  const calculate = useMutation({
    mutationFn: () => ciiApi.calculate(year!.id),
    onSuccess: (r) => { qc.invalidateQueries({ queryKey: ['cii', 'vessel-years'] }); notify.success(`Calculated: rating ${r.data.rating ?? '—'}.`); },
    onError: (e) => setError(errorMessage(e)),
  });

  const verify = useMutation({
    mutationFn: () => ciiApi.verify(year!.id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['cii', 'vessel-years'] }); notify.success('CII record verified.'); onClose(); },
    onError: (e) => setError(errorMessage(e)),
  });

  const canSave = vessel !== '' && reportingYear && formulaSetId !== '' && capacityValue && distance
    && isDecimal(capacityValue, 3) && isDecimal(distance, 2) && fuels.some((f) => f.code && f.tonnes);

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm">
      <form onSubmit={(e) => { e.preventDefault(); if (canSave) save.mutate(); }}>
        <DialogTitle>{isNew ? 'New CII record' : `CII · ${year.vessel?.name ?? 'Vessel'} · ${year.reporting_year}`}</DialogTitle>
        <DialogContent dividers>
          <FormLayout>
            {error && <Alert severity="error" onClose={() => setError(null)}>{error}</Alert>}
            {year && (
              <Stack direction="row" spacing={1} alignItems="center">
                <StatusChip status={year.status} />
                {year.rating && <StatusChip status={year.rating} label={`Rating ${year.rating}`} />}
              </Stack>
            )}

            <FormSection title="Vessel and reporting year" icon={<SpeedOutlined />}>
              <FormFieldGrid columns={2}>
                <TextField select label="Vessel" required disabled={!isNew} value={vessel} onChange={(e) => setVessel(Number(e.target.value))}>
                  {(vessels.data?.data ?? []).map((v) => <MenuItem key={v.id} value={v.id}>{v.name}</MenuItem>)}
                </TextField>
                <TextField label="Reporting year" required disabled={!isNew} value={reportingYear}
                  onChange={(e) => setReportingYear(e.target.value.replace(/\D/g, '').slice(0, 4))} />
                <TextField select label="Formula set" required disabled={!isEditable} value={formulaSetId} onChange={(e) => setFormulaSetId(Number(e.target.value))}
                  helperText="Draft sets are safe to use for estimates; mark verified before relying on it for filing.">
                  {(formulaSets.data ?? []).map((fs) => (
                    <MenuItem key={fs.id} value={fs.id} disabled={fs.status === 'retired'}>{fs.name} ({fs.status})</MenuItem>
                  ))}
                </TextField>
                <TextField select label="Capacity metric" required disabled={!isEditable} value={capacityMetric} onChange={(e) => setCapacityMetric(e.target.value as 'DWT' | 'GT')}>
                  <MenuItem value="DWT">DWT</MenuItem>
                  <MenuItem value="GT">GT</MenuItem>
                </TextField>
                <TextField label="Capacity value" required disabled={!isEditable} value={capacityValue} onChange={(e) => setCapacityValue(e.target.value)}
                  error={!!capacityValue && !isDecimal(capacityValue, 3)} helperText="Tonnes, up to 3 decimals" />
                <TextField label="Distance sailed (nm)" required disabled={!isEditable} value={distance} onChange={(e) => setDistance(e.target.value)}
                  error={!!distance && !isDecimal(distance, 2)} />
              </FormFieldGrid>
            </FormSection>

            <FormSection title="Fuel consumption (metric tonnes)" icon={<LocalGasStationOutlined />}
              action={isEditable && <Button size="small" onClick={() => setFuels((f) => [...f, { code: '', tonnes: '' }])}>Add fuel</Button>}>
              <Stack spacing={1}>
                {fuels.map((f, i) => (
                  <Stack key={i} direction="row" spacing={1}>
                    <TextField select label="Fuel" disabled={!isEditable} value={f.code} sx={{ flex: 1 }}
                      onChange={(e) => setFuels((rows) => rows.map((r, j) => (j === i ? { ...r, code: e.target.value } : r)))}>
                      {(fuelTypes.data ?? []).map((ft) => <MenuItem key={ft.id} value={ft.code}>{ft.code} — {ft.name}</MenuItem>)}
                    </TextField>
                    <TextField label="Tonnes" disabled={!isEditable} value={f.tonnes} sx={{ flex: 1 }}
                      error={!!f.tonnes && !isDecimal(f.tonnes, 3)}
                      onChange={(e) => setFuels((rows) => rows.map((r, j) => (j === i ? { ...r, tonnes: e.target.value } : r)))} />
                    {isEditable && fuels.length > 1 && (
                      <Button color="error" onClick={() => setFuels((rows) => rows.filter((_, j) => j !== i))}>Remove</Button>
                    )}
                  </Stack>
                ))}
              </Stack>
            </FormSection>

            {year?.calculation_trace && (
              <FormSection title="Last calculation" icon={<SpeedOutlined />} hint={`Calculator version ${year.calculation_version}`}>
                <Stack spacing={0.5}>
                  <Typography variant="body2">Attained CII: {year.attained_cii ?? '—'}</Typography>
                  <Typography variant="body2">Required CII (reference line): {year.required_cii ?? '—'}</Typography>
                  {year.verified_by && <Typography variant="caption" color="text.secondary">Verified by {year.verified_by.name} on {year.verified_at?.slice(0, 10)}</Typography>}
                </Stack>
              </FormSection>
            )}
          </FormLayout>
        </DialogContent>
        <DialogActions>
          <Box sx={{ flex: 1 }} />
          {year && year.status !== 'verified' && (
            <Can permission={P.CiiManage}>
              <LoadingButton loading={calculate.isPending} onClick={() => calculate.mutate()} disabled={!canSave}>Calculate</LoadingButton>
            </Can>
          )}
          {year && year.status === 'calculated' && (
            <Can permission={P.CiiManage}>
              <LoadingButton loading={verify.isPending} onClick={() => verify.mutate()} variant="outlined" color="success">Verify</LoadingButton>
            </Can>
          )}
          <Button onClick={onClose}>Close</Button>
          {isEditable && (
            <Can permission={P.CiiManage}>
              <LoadingButton type="submit" variant="contained" loading={save.isPending} disabled={!canSave}>Save</LoadingButton>
            </Can>
          )}
        </DialogActions>
      </form>
    </Dialog>
  );
}

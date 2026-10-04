import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Card, CardContent, CardHeader, FormControlLabel, Grid, MenuItem, Stack, Switch, TextField } from '@mui/material';
import { referenceApi, vesselsApi } from '../../api/masters';
import { ApiError, errorMessage } from '../../api/client';
import { PageHeader } from '../../components/PageHeader';
import { LoadingButton } from '../../components/LoadingButton';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { CompanyAutocomplete, CountrySelect } from '../../components/MasterPickers';
import { OWNERSHIP_TYPES, VESSEL_RECORD_STATUSES } from '../../constants/masters';
import { useNotify } from '../../hooks/useNotify';
import { humanize } from '../../utils/format';
import type { AttributeDef, CompanyRef, Vessel, VesselTypeItem } from '../../types/masters';

type V = Record<string, string | number | boolean | null>;

/** Field layout: [key, label, unit?, kind] — decimals stay strings; the API validates scale. */
const SECTIONS: { title: string; fields: [string, string, string?, ('text' | 'int' | 'dec')?][] }[] = [
  { title: 'Identity', fields: [['code', 'Short code'], ['name', 'Vessel name'], ['imo_number', 'IMO number'], ['mmsi', 'MMSI'], ['call_sign', 'Call sign'], ['official_number', 'Official number'],
    ['subtype', 'Subtype'], ['port_of_registry', 'Port of registry'], ['year_built', 'Year built', undefined, 'int'], ['builder', 'Builder'], ['class_society', 'Class society'], ['class_notation', 'Class notation']] },
  { title: 'Dimensions & tonnage', fields: [['loa_m', 'LOA', 'm', 'dec'], ['lbp_m', 'LBP', 'm', 'dec'], ['beam_m', 'Beam', 'm', 'dec'], ['depth_m', 'Depth', 'm', 'dec'],
    ['summer_draft_m', 'Summer draft', 'm', 'dec'], ['air_draft_m', 'Air draft', 'm', 'dec'], ['dwt_mt', 'DWT', 't', 'dec'], ['gt', 'GT', undefined, 'dec'], ['nt', 'NT', undefined, 'dec']] },
  { title: 'Machinery & speed', fields: [['main_engine', 'Main engine(s)'], ['main_engine_power_kw', 'Main engine power', 'kW', 'dec'], ['aux_engines', 'Auxiliary engines'],
    ['aux_engine_power_kw', 'Aux power', 'kW', 'dec'], ['propulsion', 'Propulsion'], ['service_speed_kn', 'Service speed', 'kn', 'dec'], ['max_speed_kn', 'Max speed', 'kn', 'dec'], ['eco_speed_kn', 'Eco speed', 'kn', 'dec']] },
  { title: 'Offshore capability & capacity', fields: [['deck_area_m2', 'Clear deck area', 'm²', 'dec'], ['deck_strength_t_m2', 'Deck strength', 't/m²', 'dec'], ['bollard_pull_t', 'Bollard pull', 't', 'dec'],
    ['crane_swl_t', 'Crane SWL', 't', 'dec'], ['crew_capacity', 'Crew capacity', 'persons', 'int'], ['passenger_capacity', 'Passenger capacity', 'persons', 'int']] },
];

const PARTIES: [string, string, string][] = [
  ['owner_company_id', 'Owner', 'owner'], ['manager_company_id', 'Manager', ''], ['commercial_manager_company_id', 'Commercial manager', ''], ['technical_manager_company_id', 'Technical manager', ''],
];
const PARTY_REL: Record<string, keyof Vessel> = { owner_company_id: 'owner', manager_company_id: 'manager', commercial_manager_company_id: 'commercial_manager', technical_manager_company_id: 'technical_manager' };

export default function VesselFormPage() {
  const params = useParams();
  const id = params.id ? Number(params.id) : null;
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const vessel = useQuery({ queryKey: ['vessels', id], queryFn: () => vesselsApi.get(id!), enabled: !!id });
  const types = useQuery({ queryKey: ['reference', 'vessel-types', 'active'], queryFn: () => referenceApi.list<VesselTypeItem>('vessel-types', true) });
  const [v, setV] = useState<V>({ ownership_type: 'owned', status: 'active', flag_country: null, dp_class: '' });
  const [custom, setCustom] = useState<Record<string, string | boolean>>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!vessel.data) return;
    const d = vessel.data as unknown as V;
    setV(Object.fromEntries(Object.entries(d).filter(([, x]) => typeof x !== 'object' || x === null)) as V);
    setCustom(Object.fromEntries(Object.entries(vessel.data.custom_attributes ?? {}).map(([k, x]) => [k, typeof x === 'boolean' ? x : String(x)])));
  }, [vessel.data]);

  const schema: AttributeDef[] = useMemo(
    () => types.data?.find((t) => t.id === Number(v.vessel_type_id))?.attribute_schema ?? [],
    [types.data, v.vessel_type_id],
  );

  const save = useMutation({
    mutationFn: () => {
      const keys = [...SECTIONS.flatMap((s) => s.fields.map((f) => f[0])), 'vessel_type_id', 'flag_country', 'ownership_type', 'dp_class', 'status', 'remarks', 'crew_management_vessel_id', ...PARTIES.map((p) => p[0])];
      const body: Record<string, unknown> = Object.fromEntries(keys.map((k) => [k, v[k] === '' || v[k] === undefined ? null : v[k]]));
      body.custom_attributes = Object.fromEntries(schema.map((a) => [a.key, custom[a.key] === '' || custom[a.key] === undefined ? null : custom[a.key]]));
      return id ? vesselsApi.update(id, { ...body, lock_version: vessel.data?.lock_version }) : vesselsApi.create(body);
    },
    onSuccess: (r) => {
      notify.success(r.message);
      qc.invalidateQueries({ queryKey: ['vessels'] });
      navigate(`/fleet/vessels/${r.data.id}`);
    },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'validation_failed') { setErrors(e.fieldErrors); setError('Please correct the highlighted fields.'); return; }
      setErrors({});
      setError(errorMessage(e));
    },
  });

  if (id && vessel.isLoading) return <SectionLoader />;
  if (id && vessel.isError) return <ErrorState error={vessel.error} onRetry={() => vessel.refetch()} />;

  const set = (k: string, x: string | number | boolean | null) => setV((s) => ({ ...s, [k]: x }));
  const field = ([k, label, unit, kind]: [string, string, string?, ('text' | 'int' | 'dec')?]) => (
    <Grid key={k} size={{ xs: 12, sm: 6, md: 3 }}>
      <TextField label={unit ? `${label} (${unit})` : label} value={v[k] ?? ''} required={k === 'code' || k === 'name'}
        inputMode={kind === 'dec' ? 'decimal' : kind === 'int' ? 'numeric' : undefined}
        onChange={(e) => set(k, kind === 'int' ? (e.target.value === '' ? '' : e.target.value.replace(/\D/g, '')) : e.target.value)}
        error={!!errors[k]} helperText={errors[k]?.[0]} />
    </Grid>
  );

  return (
    <>
      <PageHeader title={id ? `Edit ${vessel.data?.name}` : 'New vessel'}
        breadcrumbs={[{ label: 'Fleet' }, { label: 'Vessels', to: '/fleet/vessels' }, ...(id ? [{ label: vessel.data?.name ?? '', to: `/fleet/vessels/${id}` }] : []), { label: id ? 'Edit' : 'New' }]}
        actions={<>
          <Button onClick={() => navigate(id ? `/fleet/vessels/${id}` : '/fleet/vessels')}>Cancel</Button>
          <LoadingButton variant="contained" loading={save.isPending} onClick={() => save.mutate()}>{id ? 'Save changes' : 'Create vessel'}</LoadingButton>
        </>} />
      {error && <Alert severity={errors && Object.keys(errors).length ? 'warning' : 'error'} sx={{ mb: 2 }}>{error}</Alert>}
      <Stack spacing={2}>
        <Card>
          <CardHeader title="Classification & parties" slotProps={{ title: { variant: 'h6' } }} />
          <CardContent sx={{ pt: 0 }}>
            <Grid container spacing={2}>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <TextField select label="Vessel type" required value={v.vessel_type_id ?? ''} onChange={(e) => set('vessel_type_id', Number(e.target.value))} error={!!errors.vessel_type_id} helperText={errors.vessel_type_id?.[0]}>
                  {(types.data ?? []).map((t) => <MenuItem key={t.id} value={t.id}>{t.name}</MenuItem>)}
                </TextField>
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}><CountrySelect label="Flag" value={(v.flag_country as string) ?? null} onChange={(x) => set('flag_country', x)} error={errors.flag_country?.[0]} /></Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <TextField select label="Ownership" value={v.ownership_type ?? 'owned'} onChange={(e) => set('ownership_type', e.target.value)}>
                  {OWNERSHIP_TYPES.map((o) => <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>)}
                </TextField>
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <TextField select label="Record status" value={v.status ?? 'active'} onChange={(e) => set('status', e.target.value)}>
                  {VESSEL_RECORD_STATUSES.map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
                </TextField>
              </Grid>
              {PARTIES.map(([k, label, role]) => (
                <Grid key={k} size={{ xs: 12, sm: 6, md: 3 }}>
                  <CompanyAutocomplete label={label} role={role || undefined} value={(v[k] as number) ?? null}
                    initial={(vessel.data?.[PARTY_REL[k]] as CompanyRef | null | undefined) ?? null} onChange={(cid) => set(k, cid)} error={errors[k]?.[0]} />
                </Grid>
              ))}
            </Grid>
          </CardContent>
        </Card>

        {SECTIONS.map((s) => (
          <Card key={s.title}>
            <CardHeader title={s.title} slotProps={{ title: { variant: 'h6' } }} />
            <CardContent sx={{ pt: 0 }}>
              <Grid container spacing={2}>
                {s.fields.map(field)}
                {s.title.startsWith('Offshore') && (
                  <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                    <TextField select label="DP class" value={v.dp_class ?? ''} onChange={(e) => set('dp_class', e.target.value)}>
                      <MenuItem value="">None</MenuItem>{['DP0', 'DP1', 'DP2', 'DP3'].map((d) => <MenuItem key={d} value={d}>{d}</MenuItem>)}
                    </TextField>
                  </Grid>
                )}
              </Grid>
            </CardContent>
          </Card>
        ))}

        {schema.length > 0 && (
          <Card>
            <CardHeader title="Type-specific particulars" subheader="Configured per vessel type under Masters → Reference data" slotProps={{ title: { variant: 'h6' } }} />
            <CardContent sx={{ pt: 0 }}>
              <Grid container spacing={2}>
                {schema.map((a) => {
                  const err = errors[`custom_attributes.${a.key}`]?.[0];
                  const label = a.unit ? `${a.label} (${a.unit})` : a.label;
                  return (
                    <Grid key={a.key} size={{ xs: 12, sm: 6, md: 3 }}>
                      {a.data_type === 'bool' ? (
                        <FormControlLabel control={<Switch checked={!!custom[a.key]} onChange={(e) => setCustom((c) => ({ ...c, [a.key]: e.target.checked }))} />} label={a.label} />
                      ) : a.data_type === 'select' ? (
                        <TextField select label={label} value={custom[a.key] ?? ''} error={!!err} helperText={err} onChange={(e) => setCustom((c) => ({ ...c, [a.key]: e.target.value }))}>
                          <MenuItem value="">—</MenuItem>{(a.options ?? []).map((o) => <MenuItem key={o} value={o}>{o}</MenuItem>)}
                        </TextField>
                      ) : (
                        <TextField label={label} value={custom[a.key] ?? ''} error={!!err} helperText={err}
                          inputMode={a.data_type === 'string' ? undefined : 'decimal'} onChange={(e) => setCustom((c) => ({ ...c, [a.key]: e.target.value }))} />
                      )}
                    </Grid>
                  );
                })}
              </Grid>
            </CardContent>
          </Card>
        )}

        <Card>
          <CardHeader title="Other" slotProps={{ title: { variant: 'h6' } }} />
          <CardContent sx={{ pt: 0 }}>
            <Grid container spacing={2}>
              <Grid size={{ xs: 12, md: 4 }}><TextField label="Crew Management vessel ID" value={v.crew_management_vessel_id ?? ''} onChange={(e) => set('crew_management_vessel_id', e.target.value)} helperText="Optional integration mapping (linked by IMO otherwise)" /></Grid>
              <Grid size={12}><TextField label="Remarks" multiline minRows={3} value={v.remarks ?? ''} onChange={(e) => set('remarks', e.target.value)} /></Grid>
            </Grid>
          </CardContent>
        </Card>
        <Box sx={{ display: 'flex', justifyContent: 'flex-end' }}>
          <LoadingButton variant="contained" size="large" loading={save.isPending} onClick={() => save.mutate()}>{id ? 'Save changes' : 'Create vessel'}</LoadingButton>
        </Box>
      </Stack>
    </>
  );
}

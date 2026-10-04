import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Card, MenuItem, Stack, TextField } from '@mui/material';
import { vesselsApi } from '../../api/masters';
import { PageHeader } from '../../components/PageHeader';
import { ReferenceSelect } from '../../components/MasterPickers';
import { humanize } from '../../utils/format';
import { BunkerStemsTable } from './BunkerStemsTable';

export default function BunkersPage() {
  const [vessel, setVessel] = useState('');
  const [fuel, setFuel] = useState<number | null>(null);
  const [status, setStatus] = useState('');
  const vessels = useQuery({ queryKey: ['vessels', 'active-all'], queryFn: () => vesselsApi.list({ per_page: 100, status: 'active' }) });
  return (
    <>
      <PageHeader title="Bunkers" subtitle="Bunker stems (orders and deliveries). The ROB ledger is shown per voyage." breadcrumbs={[{ label: 'Operations' }, { label: 'Bunkers' }]} />
      <Card>
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ p: 2, pb: 0 }}>
          <TextField select size="small" label="Vessel" value={vessel} onChange={(e) => setVessel(e.target.value)} sx={{ width: { md: 220 } }}>
            <MenuItem value="">All</MenuItem>{(vessels.data?.data ?? []).map((v) => <MenuItem key={v.id} value={String(v.id)}>{v.name}</MenuItem>)}
          </TextField>
          <ReferenceSelect type="fuel-types" label="Fuel" size="small" allowEmpty value={fuel} onChange={setFuel} sx={{ width: { md: 180 } }} />
          <TextField select size="small" label="Status" value={status} onChange={(e) => setStatus(e.target.value)} sx={{ width: { md: 160 } }}>
            <MenuItem value="">All</MenuItem>{['ordered', 'delivered', 'invoiced', 'cancelled'].map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
        </Stack>
        <BunkerStemsTable filters={{ vessel_id: vessel || undefined, fuel_type_id: fuel ?? undefined, status: status || undefined }} />
      </Card>
    </>
  );
}

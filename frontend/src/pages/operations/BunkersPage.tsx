import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button, Card, MenuItem, TextField } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import { vesselsApi } from '../../api/masters';
import { PageHeader } from '../../components/PageHeader';
import { ReferenceSelect } from '../../components/MasterPickers';
import { Can } from '../../auth/guards';
import { P } from '../../constants/permissions';
import { humanize } from '../../utils/format';
import { BunkerStemsTable } from './BunkerStemsTable';
import { OperationsFilterBar } from './OperationsFilterBar';

export default function BunkersPage() {
  const [vessel, setVessel] = useState('');
  const [fuel, setFuel] = useState<number | null>(null);
  const [status, setStatus] = useState('');
  const [orderRequest, setOrderRequest] = useState(0);
  const vessels = useQuery({ queryKey: ['vessels', 'active-all'], queryFn: () => vesselsApi.list({ per_page: 100, status: 'active' }) });
  return (
    <>
      <PageHeader title="Bunkers" subtitle="Bunker stems (orders and deliveries). The ROB ledger is shown per voyage." breadcrumbs={[{ label: 'Operations' }, { label: 'Bunkers' }]}
        actions={<Can permission={P.BunkersManage}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setOrderRequest((n) => n + 1)}>New order</Button></Can>} />
      <Card>
        <OperationsFilterBar active={!!vessel || fuel !== null || !!status} onClear={() => { setVessel(''); setFuel(null); setStatus(''); }}>
          <TextField select size="small" label="Vessel" value={vessel} onChange={(e) => setVessel(e.target.value)}>
            <MenuItem value="">All</MenuItem>{(vessels.data?.data ?? []).map((v) => <MenuItem key={v.id} value={String(v.id)}>{v.name}</MenuItem>)}
          </TextField>
          <TextField select size="small" label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <MenuItem value="">All</MenuItem>{['ordered', 'delivered', 'invoiced', 'cancelled'].map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
          <ReferenceSelect type="fuel-types" label="Fuel" size="small" allowEmpty value={fuel} onChange={setFuel} />
        </OperationsFilterBar>
        <BunkerStemsTable filters={{ vessel_id: vessel || undefined, fuel_type_id: fuel ?? undefined, status: status || undefined }} openOrderRequest={orderRequest} />
      </Card>
    </>
  );
}

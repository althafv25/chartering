import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Card, MenuItem, Stack, TextField } from '@mui/material';
import { vesselsApi } from '../../api/masters';
import { offshoreProjectsApi } from '../../api/offshore';
import { PageHeader } from '../../components/PageHeader';
import { ReferenceSelect } from '../../components/MasterPickers';
import { ACTIVITY_STATUSES } from '../../constants/operations';
import { humanize } from '../../utils/format';
import { ActivitiesTable } from './ActivitiesTable';

export default function OffshoreActivitiesPage() {
  const [params] = useSearchParams();
  const [vessel, setVessel] = useState('');
  const [project, setProject] = useState(params.get('project') ?? '');
  const [status, setStatus] = useState('');
  const [type, setType] = useState<number | null>(null);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const vessels = useQuery({ queryKey: ['vessels', 'active-all'], queryFn: () => vesselsApi.list({ per_page: 100, status: 'active' }) });
  const projects = useQuery({ queryKey: ['offshore-projects', 'open'], queryFn: () => offshoreProjectsApi.list({ per_page: 100 }) });

  return (
    <>
      <PageHeader title="Offshore Activities" subtitle="Activity log per vessel, project and location — hours split and revenue from the contract rate schedule"
        breadcrumbs={[{ label: 'Operations' }, { label: 'Offshore Activities' }]} />
      <Card>
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ p: 2, pb: 0 }} flexWrap="wrap" useFlexGap>
          <TextField select size="small" label="Vessel" value={vessel} onChange={(e) => setVessel(e.target.value)} sx={{ width: { md: 200 } }}>
            <MenuItem value="">All</MenuItem>{(vessels.data?.data ?? []).map((v) => <MenuItem key={v.id} value={String(v.id)}>{v.name}</MenuItem>)}
          </TextField>
          <TextField select size="small" label="Project" value={project} onChange={(e) => setProject(e.target.value)} sx={{ width: { md: 220 } }}>
            <MenuItem value="">All</MenuItem>{(projects.data?.data ?? []).map((p) => <MenuItem key={p.id} value={String(p.id)}>{p.code} — {p.name}</MenuItem>)}
          </TextField>
          <TextField select size="small" label="Status" value={status} onChange={(e) => setStatus(e.target.value)} sx={{ width: { md: 150 } }}>
            <MenuItem value="">All</MenuItem>{ACTIVITY_STATUSES.map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
          <ReferenceSelect type="offshore-activity-types" label="Type" size="small" allowEmpty value={type} onChange={setType} sx={{ width: { md: 190 } }} />
          <TextField size="small" type="date" label="From" value={from} onChange={(e) => setFrom(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: { md: 160 } }} />
          <TextField size="small" type="date" label="To" value={to} onChange={(e) => setTo(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: { md: 160 } }} />
        </Stack>
        <ActivitiesTable filters={{ vessel_id: vessel || undefined, offshore_project_id: project || undefined, status: status || undefined,
          offshore_activity_type_id: type ?? undefined, from: from || undefined, to: to || undefined }} />
      </Card>
    </>
  );
}

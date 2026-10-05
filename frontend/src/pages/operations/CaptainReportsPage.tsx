import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button, Card, MenuItem, TextField } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import { vesselsApi } from '../../api/masters';
import { PageHeader } from '../../components/PageHeader';
import { Can } from '../../auth/guards';
import { P } from '../../constants/permissions';
import { REPORT_STATUSES, REPORT_TYPES } from '../../constants/operations';
import { humanize } from '../../utils/format';
import { CaptainReportsTable } from './CaptainReportsTable';
import { OperationsFilterBar } from './OperationsFilterBar';

export default function CaptainReportsPage() {
  const [vessel, setVessel] = useState('');
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [reportRequest, setReportRequest] = useState(0);
  const vessels = useQuery({ queryKey: ['vessels', 'active-all'], queryFn: () => vesselsApi.list({ per_page: 100, status: 'active' }) });
  return (
    <>
      <PageHeader title="Captain Reports" subtitle="Noon, arrival, departure and other reports. Reports count towards actuals only after verification." breadcrumbs={[{ label: 'Operations' }, { label: 'Captain Reports' }]}
        actions={<Can permission={P.CaptainReportsCreate}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setReportRequest((n) => n + 1)}>New report</Button></Can>} />
      <Card>
        <OperationsFilterBar active={!!vessel || !!status || !!type} onClear={() => { setVessel(''); setStatus(''); setType(''); }}>
          <TextField select size="small" label="Vessel" value={vessel} onChange={(e) => setVessel(e.target.value)}>
            <MenuItem value="">All</MenuItem>{(vessels.data?.data ?? []).map((v) => <MenuItem key={v.id} value={String(v.id)}>{v.name}</MenuItem>)}
          </TextField>
          <TextField select size="small" label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <MenuItem value="">All</MenuItem>{REPORT_STATUSES.map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
          <TextField select size="small" label="Type" value={type} onChange={(e) => setType(e.target.value)}>
            <MenuItem value="">All</MenuItem>{REPORT_TYPES.map((t) => <MenuItem key={t.value} value={t.value}>{t.label}</MenuItem>)}
          </TextField>
        </OperationsFilterBar>
        <CaptainReportsTable showVessel filters={{ vessel_id: vessel || undefined, status: status || undefined, report_type: type || undefined }} openReportRequest={reportRequest} />
      </Card>
    </>
  );
}

import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Box, Button, Card, CardContent, Chip, FormControlLabel, IconButton, Stack, Switch, Tab, Tabs, Typography } from '@mui/material';
import EditOutlined from '@mui/icons-material/EditOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { portsApi } from '../../api/masters';
import { PageHeader } from '../../components/PageHeader';
import { KeyValueGrid } from '../../components/KeyValueGrid';
import { withUnit } from '../../utils/format';
import { DataTable, type Column } from '../../components/DataTable';
import { DocumentsPanel } from '../../components/DocumentsPanel';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { LoadingButton } from '../../components/LoadingButton';
import { CompanyAutocomplete } from '../../components/MasterPickers';
import { StatusChip } from '../../components/StatusChip';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { countryName } from '../../constants/countries';
import { useNotify } from '../../hooks/useNotify';
import type { PortAgent } from '../../types/masters';
import { PortDialog } from './PortsPage';

export default function PortDetailPage() {
  const id = Number(useParams().id);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [tab, setTab] = useState('overview');
  const [edit, setEdit] = useState(false);
  const [agentId, setAgentId] = useState<number | null>(null);
  const [isDefault, setIsDefault] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);

  const port = useQuery({ queryKey: ['ports', id], queryFn: () => portsApi.get(id) });
  const canEdit = can(P.PortsUpdate);
  const invalidate = () => qc.invalidateQueries({ queryKey: ['ports'] });

  const addAgent = useMutation({
    mutationFn: () => portsApi.addAgent(id, { company_id: agentId, is_default: isDefault }),
    onSuccess: (r) => { notify.success(r.message); setAgentId(null); setIsDefault(false); invalidate(); },
    onError: (e) => notify.error(e),
  });
  const removeAgent = useMutation({ mutationFn: (companyId: number) => portsApi.removeAgent(id, companyId), onSuccess: invalidate, onError: (e) => notify.error(e) });
  const remove = useMutation({ mutationFn: () => portsApi.remove(id), onSuccess: () => { invalidate(); navigate('/masters/ports'); }, onError: (e) => notify.error(e) });

  if (port.isLoading) return <SectionLoader />;
  if (port.isError || !port.data) return <ErrorState error={port.error} onRetry={() => port.refetch()} />;
  const p = port.data;

  const agentCols: Column<PortAgent>[] = [
    { key: 'name', header: 'Agent', render: (a) => <Typography fontWeight={600} fontSize={14}>{a.company.legal_name}</Typography> },
    { key: 'code', header: 'Code', render: (a) => a.company.code },
    { key: 'default', header: '', render: (a) => (a.is_default ? <Chip size="small" color="primary" label="Default" /> : null) },
    { key: 'actions', header: '', align: 'right', render: (a) => canEdit && (
      <IconButton size="small" color="error" aria-label={`Unlink ${a.company.legal_name}`} onClick={() => removeAgent.mutate(a.company.id)}><DeleteOutline fontSize="small" /></IconButton>
    ) },
  ];

  return (
    <>
      <PageHeader
        title={p.name}
        subtitle={[p.unlocode, countryName(p.country), p.region].filter(Boolean).join(' · ')}
        breadcrumbs={[{ label: 'Masters' }, { label: 'Ports', to: '/masters/ports' }, { label: p.name }]}
        actions={<>
          {can(P.PortsDelete) && <Button color="error" onClick={() => setConfirmDelete(true)}>Delete</Button>}
          {canEdit && <Button variant="contained" startIcon={<EditOutlined />} onClick={() => setEdit(true)}>Edit</Button>}
        </>}
      />
      <Card>
        <Tabs value={tab} onChange={(_, v) => setTab(v)} sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          <Tab value="overview" label="Overview" />
          <Tab value="agents" label={`Agents (${p.agents?.length ?? 0})`} />
          <Tab value="documents" label="Documents" />
        </Tabs>
        {tab === 'overview' && (
          <CardContent>
            <Stack direction="row" sx={{ mb: 2 }}><StatusChip status={p.status} /></Stack>
            <KeyValueGrid items={[
              ['UN/LOCODE', p.unlocode], ['Country', countryName(p.country)], ['Region', p.region],
              ['Latitude', p.latitude], ['Longitude', p.longitude], ['Time zone', p.timezone],
              ['Max draft', withUnit(p.max_draft_m, 'm')], ['Max LOA', withUnit(p.max_loa_m, 'm')], ['Max beam', withUnit(p.max_beam_m, 'm')],
            ]} />
            {p.restrictions && <Box sx={{ mt: 3 }}><Typography variant="subtitle2">Restrictions</Typography><Typography whiteSpace="pre-wrap" fontSize={14}>{p.restrictions}</Typography></Box>}
            {p.notes && <Box sx={{ mt: 2 }}><Typography variant="subtitle2">Notes</Typography><Typography whiteSpace="pre-wrap" fontSize={14}>{p.notes}</Typography></Box>}
            <Typography variant="body2" color="text.secondary" sx={{ mt: 3 }}>Historical Port DA and voyage calls appear here once those modules are delivered (phases 6 and 8).</Typography>
          </CardContent>
        )}
        {tab === 'agents' && (
          <Box>
            {canEdit && (
              <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ p: 2 }} alignItems={{ md: 'center' }}>
                <Box sx={{ flex: 1, maxWidth: 480 }}><CompanyAutocomplete label="Link agent from address book" role="agent" value={agentId} onChange={(cid) => setAgentId(cid)} /></Box>
                <FormControlLabel control={<Switch checked={isDefault} onChange={(e) => setIsDefault(e.target.checked)} />} label="Default agent" />
                <LoadingButton variant="outlined" disabled={!agentId} loading={addAgent.isPending} onClick={() => addAgent.mutate()}>Link agent</LoadingButton>
              </Stack>
            )}
            <DataTable columns={agentCols} rows={p.agents ?? []} rowKey={(a) => a.company.id} emptyTitle="No agents linked" emptyDescription="Companies need the Agent role in the address book." />
          </Box>
        )}
        {tab === 'documents' && <DocumentsPanel parentType="ports" parentId={id} canEdit={canEdit} />}
      </Card>
      <PortDialog open={edit} port={p} onClose={() => setEdit(false)} />
      <ConfirmDialog open={confirmDelete} title="Delete port" message={`Delete ${p.name}?`} confirmLabel="Delete" danger loading={remove.isPending}
        onConfirm={() => remove.mutate()} onClose={() => setConfirmDelete(false)} />
    </>
  );
}

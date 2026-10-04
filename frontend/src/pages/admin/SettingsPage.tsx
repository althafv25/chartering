import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, FormControlLabel, FormHelperText, Grid, MenuItem, Stack, Switch, TextField } from '@mui/material';
import { settingsApi } from '../../api/endpoints';
import { PageHeader } from '../../components/PageHeader';
import { LoadingButton } from '../../components/LoadingButton';
import { ErrorState, SectionLoader } from '../../components/Feedback';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import { humanize } from '../../utils/format';
import type { SettingsGroups, SettingValue } from '../../types/models';

const labelFor = (key: string) => humanize(key.split('.').slice(1).join(' '));

export default function SettingsPage() {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const editable = can(P.SettingsUpdate);
  const settings = useQuery({ queryKey: ['settings'], queryFn: settingsApi.get });
  const [values, setValues] = useState<Record<string, SettingValue>>({});

  useEffect(() => {
    if (!settings.data) return;
    const flat: Record<string, SettingValue> = {};
    Object.values(settings.data).forEach((g) => Object.entries(g).forEach(([k, v]) => { flat[k] = v.value; }));
    setValues(flat);
  }, [settings.data]);

  const save = useMutation({
    mutationFn: () => settingsApi.update(values),
    onSuccess: (res) => { notify.success(res.message); qc.setQueryData<SettingsGroups>(['settings'], res.data); },
    onError: (e) => notify.error(e),
  });

  if (settings.isLoading) return <SectionLoader />;
  if (settings.isError) return <ErrorState error={settings.error} onRetry={() => settings.refetch()} />;

  return (
    <>
      <PageHeader
        title="Settings"
        subtitle="Company information and system behaviour"
        breadcrumbs={[{ label: 'Administration' }, { label: 'Settings' }]}
        actions={editable && <LoadingButton variant="contained" loading={save.isPending} onClick={() => save.mutate()}>Save settings</LoadingButton>}
      />
      <Stack spacing={2}>
        {Object.entries(settings.data ?? {}).map(([group, items]) => (
          <Card key={group}>
            <CardHeader title={humanize(group)} slotProps={{ title: { variant: 'h6' } }} />
            <CardContent sx={{ pt: 0 }}>
              <Grid container spacing={2}>
                {Object.entries(items).map(([key, def]) => (
                  <Grid key={key} size={{ xs: 12, md: 6 }}>
                    {def.type === 'bool' ? (
                      <>
                        <FormControlLabel
                          control={<Switch checked={!!values[key]} disabled={!editable} onChange={(e) => setValues((v) => ({ ...v, [key]: e.target.checked }))} />}
                          label={labelFor(key)}
                        />
                        {def.help && <FormHelperText>{def.help}</FormHelperText>}
                      </>
                    ) : def.options ? (
                      <TextField select label={labelFor(key)} value={values[key] ?? ''} disabled={!editable} helperText={def.help ?? undefined}
                        onChange={(e) => setValues((v) => ({ ...v, [key]: e.target.value }))}>
                        {def.options.map((o) => <MenuItem key={o} value={o}>{/^[a-z_]+$/.test(o) ? humanize(o) : o}</MenuItem>)}
                      </TextField>
                    ) : (
                      <TextField
                        label={labelFor(key)}
                        type={def.type === 'int' ? 'number' : 'text'}
                        value={values[key] ?? ''}
                        disabled={!editable}
                        onChange={(e) => setValues((v) => ({ ...v, [key]: def.type === 'int' ? Number(e.target.value) : e.target.value }))}
                      />
                    )}
                  </Grid>
                ))}
              </Grid>
            </CardContent>
          </Card>
        ))}
      </Stack>
    </>
  );
}

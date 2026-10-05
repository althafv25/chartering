import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Checkbox, DialogActions, DialogContent, FormControlLabel, Grid, Paper, TextField, Typography } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import { rolesApi } from '../../api/endpoints';
import { LoadingButton } from '../../components/LoadingButton';
import { SectionLoader } from '../../components/Feedback';
import { useNotify } from '../../hooks/useNotify';
import { errorMessage } from '../../api/client';
import { humanize } from '../../utils/format';
import type { Role } from '../../types/models';

export function RoleDialog({ open, role, readOnly, onClose }: { open: boolean; role: Role | null; readOnly?: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const catalogue = useQuery({ queryKey: ['roles', 'catalogue'], queryFn: rolesApi.catalogue, enabled: open, staleTime: Infinity });
  const [name, setName] = useState('');
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setName(role?.name ?? '');
      setSelected(new Set(role?.permissions ?? []));
      setError(null);
    }
  }, [open, role]);

  const save = useMutation({
    mutationFn: () => role
      ? rolesApi.update(role.id, { ...(role.is_system ? {} : { name }), permissions: [...selected] })
      : rolesApi.create({ name, permissions: [...selected] }),
    onSuccess: (res) => { notify.success(res.message); qc.invalidateQueries({ queryKey: ['roles'] }); onClose(); },
    onError: (e) => setError(errorMessage(e)),
  });

  const toggle = (p: string) => setSelected((s) => { const n = new Set(s); if (n.has(p)) n.delete(p); else n.add(p); return n; });
  const toggleModule = (perms: string[], on: boolean) => setSelected((s) => { const n = new Set(s); perms.forEach((p) => (on ? n.add(p) : n.delete(p))); return n; });

  const locked = readOnly || role?.is_locked;

  return (
    <Dialog open={open} onClose={save.isPending ? undefined : onClose} maxWidth="md" fullWidth>
      <DialogTitle>{role ? (locked ? role.label : `Edit role: ${role.label}`) : 'New role'}</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        {role?.is_locked && <Alert severity="info" sx={{ mb: 2 }}>Super Admin has full access to every module and cannot be edited.</Alert>}
        <TextField
          label="Role name"
          value={name}
          onChange={(e) => setName(e.target.value)}
          disabled={!!role?.is_system || locked}
          helperText={role?.is_system ? 'System roles cannot be renamed.' : 'Lowercase, hyphenated (e.g. desk-lead).'}
          sx={{ mb: 2, maxWidth: 360 }}
        />
        {catalogue.isLoading ? <SectionLoader /> : (
          <Grid container spacing={1.5}>
            {catalogue.data?.map((group) => {
              const names = group.permissions.map((p) => p.name);
              const count = names.filter((n) => selected.has(n)).length;
              return (
                <Grid key={group.module} size={{ xs: 12, sm: 6, md: 4 }}>
                  <Paper variant="outlined" sx={{ p: 1.5, height: '100%' }}>
                    <FormControlLabel
                      control={<Checkbox size="small" checked={count === names.length} indeterminate={count > 0 && count < names.length} disabled={locked} onChange={(e) => toggleModule(names, e.target.checked)} />}
                      label={<Typography fontWeight={600} fontSize={14}>{humanize(group.module)}</Typography>}
                    />
                    <Box sx={{ pl: 3.5 }}>
                      {group.permissions.map((p) => (
                        <FormControlLabel
                          key={p.name}
                          sx={{ display: 'flex' }}
                          control={<Checkbox size="small" checked={role?.is_locked || selected.has(p.name)} disabled={locked} onChange={() => toggle(p.name)} />}
                          label={<Typography fontSize={13}>{humanize(p.action)}</Typography>}
                        />
                      ))}
                    </Box>
                  </Paper>
                </Grid>
              );
            })}
          </Grid>
        )}
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose} disabled={save.isPending}>{locked ? 'Close' : 'Cancel'}</Button>
        {!locked && <LoadingButton variant="contained" loading={save.isPending} disabled={!name.trim()} onClick={() => save.mutate()}>{role ? 'Save role' : 'Create role'}</LoadingButton>}
      </DialogActions>
    </Dialog>
  );
}

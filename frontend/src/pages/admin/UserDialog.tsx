import { useEffect } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Autocomplete, Button, Chip, DialogActions, DialogContent, Grid, TextField } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from '../../components/DrawerDialog';
import { rolesApi, usersApi, type UserPayload } from '../../api/endpoints';
import { LoadingButton } from '../../components/LoadingButton';
import { useNotify } from '../../hooks/useNotify';
import { applyServerErrors } from '../../utils/forms';
import type { User } from '../../types/models';

const makeSchema = (isEdit: boolean) => z.object({
  first_name: z.string().trim().min(1, 'Required').max(75),
  last_name: z.string().trim().max(75).optional(),
  email: z.string().trim().email('Enter a valid email'),
  phone: z.string().trim().max(50).optional(),
  job_title: z.string().trim().max(100).optional(),
  roles: z.array(z.string()).min(1, 'Select at least one role'),
  password: isEdit ? z.string().optional() : z.string().min(8, 'At least 8 characters'),
  password_confirmation: z.string().optional(),
}).refine((v) => !v.password || v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'Passwords do not match' })
  .refine((v) => !v.password || v.password.length >= 8, { path: ['password'], message: 'At least 8 characters' });

type Form = z.infer<ReturnType<typeof makeSchema>>;

export function UserDialog({ open, user, onClose }: { open: boolean; user: User | null; onClose: () => void }) {
  const isEdit = !!user;
  const qc = useQueryClient();
  const notify = useNotify();
  const roles = useQuery({ queryKey: ['roles'], queryFn: rolesApi.list, enabled: open });

  const { register, control, handleSubmit, reset, setError, formState: { errors } } = useForm<Form>({ resolver: zodResolver(makeSchema(isEdit)) });

  useEffect(() => {
    if (open) {
      reset({
        first_name: user?.first_name ?? '', last_name: user?.last_name ?? '', email: user?.email ?? '',
        phone: user?.phone ?? '', job_title: user?.job_title ?? '', roles: user?.roles ?? [],
        password: '', password_confirmation: '',
      });
    }
  }, [open, user, reset]);

  const save = useMutation({
    mutationFn: (values: Form) => {
      const body: UserPayload = { ...values };
      if (!values.password) { delete body.password; delete body.password_confirmation; }
      return isEdit ? usersApi.update(user!.id, body) : usersApi.create(body);
    },
    onSuccess: (res) => {
      notify.success(res.message);
      qc.invalidateQueries({ queryKey: ['users'] });
      qc.invalidateQueries({ queryKey: ['roles'] });
      onClose();
    },
    onError: (e) => { if (!applyServerErrors(e, setError)) notify.error(e); },
  });

  const roleOptions = roles.data ?? [];

  return (
    <Dialog open={open} onClose={save.isPending ? undefined : onClose} maxWidth="sm" fullWidth>
      <form onSubmit={handleSubmit((v) => save.mutate(v))} noValidate>
        <DialogTitle>{isEdit ? 'Edit user' : 'New user'}</DialogTitle>
        <DialogContent dividers>
          {roles.isError && <Alert severity="warning" sx={{ mb: 2 }}>Roles could not be loaded.</Alert>}
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="First name" required {...register('first_name')} error={!!errors.first_name} helperText={errors.first_name?.message} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Last name" {...register('last_name')} /></Grid>
            <Grid size={12}><TextField label="Email" type="email" required {...register('email')} error={!!errors.email} helperText={errors.email?.message} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Phone" {...register('phone')} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Job title" {...register('job_title')} /></Grid>
            <Grid size={12}>
              <Controller
                control={control}
                name="roles"
                render={({ field }) => (
                  <Autocomplete
                    multiple
                    size="small"
                    options={roleOptions.map((r) => r.name)}
                    getOptionLabel={(n) => roleOptions.find((r) => r.name === n)?.label ?? n}
                    value={field.value ?? []}
                    onChange={(_, v) => field.onChange(v)}
                    loading={roles.isLoading}
                    renderValue={(value, getItemProps) => value.map((n, index) => {
                      const { key, ...itemProps } = getItemProps({ index });
                      return <Chip key={key} size="small" label={roleOptions.find((r) => r.name === n)?.label ?? n} {...itemProps} />;
                    })}
                    renderInput={(params) => <TextField {...params} label="Roles" required error={!!errors.roles} helperText={errors.roles?.message} />}
                  />
                )}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField label={isEdit ? 'New password (optional)' : 'Password'} type="password" autoComplete="new-password" required={!isEdit} {...register('password')} error={!!errors.password} helperText={errors.password?.message} />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField label="Confirm password" type="password" autoComplete="new-password" {...register('password_confirmation')} error={!!errors.password_confirmation} helperText={errors.password_confirmation?.message} />
            </Grid>
          </Grid>
        </DialogContent>
        <DialogActions sx={{ px: 3, py: 2 }}>
          <Button onClick={onClose} disabled={save.isPending}>Cancel</Button>
          <LoadingButton type="submit" variant="contained" loading={save.isPending}>{isEdit ? 'Save changes' : 'Create user'}</LoadingButton>
        </DialogActions>
      </form>
    </Dialog>
  );
}

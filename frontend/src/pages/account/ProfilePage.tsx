import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Card, CardContent, CardHeader, Chip, Grid, Stack, TextField } from '@mui/material';
import { PageHeader } from '../../components/PageHeader';
import { LoadingButton } from '../../components/LoadingButton';
import { useAuth } from '../../auth/useAuth';
import { authApi } from '../../api/endpoints';
import { useNotify } from '../../hooks/useNotify';
import { applyServerErrors } from '../../utils/forms';

const profileSchema = z.object({
  first_name: z.string().trim().min(1, 'Required').max(75),
  last_name: z.string().trim().max(75).optional().or(z.literal('')),
  phone: z.string().trim().max(50).optional().or(z.literal('')),
  job_title: z.string().trim().max(100).optional().or(z.literal('')),
  timezone: z.string().trim().min(1, 'Required'),
});

const passwordSchema = z.object({
  current_password: z.string().min(1, 'Required'),
  password: z.string().min(8, 'At least 8 characters'),
  password_confirmation: z.string(),
}).refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'Passwords do not match' });

type ProfileForm = z.infer<typeof profileSchema>;
type PasswordForm = z.infer<typeof passwordSchema>;

export default function ProfilePage() {
  const { user, setUser } = useAuth();
  const notify = useNotify();

  const profile = useForm<ProfileForm>({
    resolver: zodResolver(profileSchema),
    defaultValues: {
      first_name: user?.first_name ?? '', last_name: user?.last_name ?? '', phone: user?.phone ?? '',
      job_title: user?.job_title ?? '', timezone: user?.timezone ?? 'Asia/Dubai',
    },
  });
  const password = useForm<PasswordForm>({ resolver: zodResolver(passwordSchema) });

  const saveProfile = async (values: ProfileForm) => {
    try {
      setUser(await authApi.updateProfile(values));
      notify.success('Profile updated.');
    } catch (e) {
      if (!applyServerErrors(e, profile.setError)) notify.error(e);
    }
  };

  const savePassword = async (values: PasswordForm) => {
    try {
      notify.success((await authApi.changePassword(values)).message);
      password.reset({ current_password: '', password: '', password_confirmation: '' });
    } catch (e) {
      if (!applyServerErrors(e, password.setError)) notify.error(e);
    }
  };

  const pe = profile.formState.errors;
  const we = password.formState.errors;

  return (
    <>
      <PageHeader title="My profile" breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'Profile' }]} />
      <Grid container spacing={2}>
        <Grid size={{ xs: 12, md: 7 }}>
          <Card component="form" onSubmit={profile.handleSubmit(saveProfile)} noValidate>
            <CardHeader title="Personal details" subheader={user?.email} slotProps={{ title: { variant: 'h6' } }}
              action={<Stack direction="row" spacing={0.5} sx={{ mt: 1, mr: 1 }}>{user?.roles.map((r) => <Chip key={r} size="small" label={r} />)}</Stack>} />
            <CardContent>
              <Grid container spacing={2}>
                <Grid size={{ xs: 12, sm: 6 }}><TextField label="First name" {...profile.register('first_name')} error={!!pe.first_name} helperText={pe.first_name?.message} /></Grid>
                <Grid size={{ xs: 12, sm: 6 }}><TextField label="Last name" {...profile.register('last_name')} /></Grid>
                <Grid size={{ xs: 12, sm: 6 }}><TextField label="Phone" {...profile.register('phone')} /></Grid>
                <Grid size={{ xs: 12, sm: 6 }}><TextField label="Job title" {...profile.register('job_title')} /></Grid>
                <Grid size={{ xs: 12, sm: 6 }}><TextField label="Time zone" {...profile.register('timezone')} error={!!pe.timezone} helperText={pe.timezone?.message ?? 'IANA name, e.g. Asia/Dubai'} /></Grid>
              </Grid>
              <Stack direction="row" justifyContent="flex-end" sx={{ mt: 2 }}>
                <LoadingButton type="submit" variant="contained" loading={profile.formState.isSubmitting}>Save changes</LoadingButton>
              </Stack>
            </CardContent>
          </Card>
        </Grid>
        <Grid size={{ xs: 12, md: 5 }}>
          <Card component="form" onSubmit={password.handleSubmit(savePassword)} noValidate>
            <CardHeader title="Change password" subheader="Other devices will be signed out." slotProps={{ title: { variant: 'h6' } }} />
            <CardContent>
              <Stack spacing={2}>
                <TextField label="Current password" type="password" autoComplete="current-password" {...password.register('current_password')} error={!!we.current_password} helperText={we.current_password?.message} />
                <TextField label="New password" type="password" autoComplete="new-password" {...password.register('password')} error={!!we.password} helperText={we.password?.message} />
                <TextField label="Confirm new password" type="password" autoComplete="new-password" {...password.register('password_confirmation')} error={!!we.password_confirmation} helperText={we.password_confirmation?.message} />
                <Stack direction="row" justifyContent="flex-end">
                  <LoadingButton type="submit" variant="contained" loading={password.formState.isSubmitting}>Update password</LoadingButton>
                </Stack>
              </Stack>
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </>
  );
}

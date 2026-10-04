import { useState } from 'react';
import { Link as RouterLink, useLocation, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Alert, IconButton, InputAdornment, Link, Stack, TextField, Typography } from '@mui/material';
import Visibility from '@mui/icons-material/Visibility';
import VisibilityOff from '@mui/icons-material/VisibilityOff';
import { useAuth } from '../../auth/useAuth';
import { errorMessage } from '../../api/client';
import { LoadingButton } from '../../components/LoadingButton';

const schema = z.object({
  email: z.string().trim().min(1, 'Email is required').email('Enter a valid email'),
  password: z.string().min(1, 'Password is required'),
});
type Form = z.infer<typeof schema>;

export default function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [error, setError] = useState<string | null>(null);
  const [show, setShow] = useState(false);
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<Form>({ resolver: zodResolver(schema) });

  const from = (location.state as { from?: { pathname: string } } | null)?.from?.pathname ?? '/';

  const onSubmit = async (values: Form) => {
    setError(null);
    try {
      await login(values.email, values.password);
      navigate(from, { replace: true });
    } catch (e) {
      setError(errorMessage(e));
    }
  };

  return (
    <form onSubmit={handleSubmit(onSubmit)} noValidate>
      <Typography variant="h5" component="h1" gutterBottom>Sign in</Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>Use your company account.</Typography>
      <Stack spacing={2}>
        {error && <Alert severity="error" role="alert">{error}</Alert>}
        <TextField label="Email" type="email" autoComplete="email" autoFocus {...register('email')} error={!!errors.email} helperText={errors.email?.message} />
        <TextField
          label="Password"
          type={show ? 'text' : 'password'}
          autoComplete="current-password"
          {...register('password')}
          error={!!errors.password}
          helperText={errors.password?.message}
          slotProps={{ input: { endAdornment: (
            <InputAdornment position="end">
              <IconButton onClick={() => setShow((s) => !s)} edge="end" aria-label={show ? 'Hide password' : 'Show password'}>
                {show ? <VisibilityOff fontSize="small" /> : <Visibility fontSize="small" />}
              </IconButton>
            </InputAdornment>
          ) } }}
        />
        <LoadingButton type="submit" variant="contained" size="large" loading={isSubmitting}>Sign in</LoadingButton>
        <Link component={RouterLink} to="/forgot-password" variant="body2" sx={{ alignSelf: 'center' }}>Forgot password?</Link>
      </Stack>
    </form>
  );
}

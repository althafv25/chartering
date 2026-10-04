import { useState } from 'react';
import { Outlet, useNavigate } from 'react-router-dom';
import { AppBar, Avatar, Box, Divider, Drawer, IconButton, ListItemIcon, Menu, MenuItem, Toolbar, Typography, useMediaQuery, useTheme } from '@mui/material';
import MenuIcon from '@mui/icons-material/Menu';
import PersonOutline from '@mui/icons-material/PersonOutline';
import Logout from '@mui/icons-material/Logout';
import { Sidebar } from './Sidebar';
import { NotificationBell } from './NotificationBell';
import { useAuth } from '../auth/useAuth';
import { initials } from '../utils/format';

const DRAWER_WIDTH = 264;

export default function AppLayout() {
  const theme = useTheme();
  const isDesktop = useMediaQuery(theme.breakpoints.up('lg'));
  const [mobileOpen, setMobileOpen] = useState(false);
  const [menuAnchor, setMenuAnchor] = useState<HTMLElement | null>(null);
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  return (
    <Box sx={{ display: 'flex', minHeight: '100vh' }}>
      <Box component="aside" sx={{ width: { lg: DRAWER_WIDTH }, flexShrink: { lg: 0 } }}>
        <Drawer
          variant={isDesktop ? 'permanent' : 'temporary'}
          open={isDesktop || mobileOpen}
          onClose={() => setMobileOpen(false)}
          ModalProps={{ keepMounted: true }}
          slotProps={{ paper: { sx: { width: DRAWER_WIDTH, border: 0 } } }}
        >
          <Sidebar onNavigate={() => setMobileOpen(false)} />
        </Drawer>
      </Box>

      <Box sx={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        <AppBar position="sticky" color="inherit" elevation={0} sx={{ borderBottom: 1, borderColor: 'divider', bgcolor: 'background.paper' }}>
          <Toolbar sx={{ gap: 1 }}>
            {!isDesktop && (
              <IconButton edge="start" onClick={() => setMobileOpen(true)} aria-label="Open navigation"><MenuIcon /></IconButton>
            )}
            <Box sx={{ flex: 1 }} />
            <NotificationBell />
            <IconButton onClick={(e) => setMenuAnchor(e.currentTarget)} aria-label="Account menu" sx={{ ml: 0.5 }}>
              <Avatar sx={{ width: 34, height: 34, bgcolor: 'secondary.main', fontSize: 14 }}>{initials(user?.name ?? '')}</Avatar>
            </IconButton>
            <Menu
              anchorEl={menuAnchor}
              open={!!menuAnchor}
              onClose={() => setMenuAnchor(null)}
              anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
              transformOrigin={{ vertical: 'top', horizontal: 'right' }}
            >
              <Box sx={{ px: 2, py: 1, minWidth: 220 }}>
                <Typography fontWeight={600}>{user?.name}</Typography>
                <Typography variant="body2" color="text.secondary">{user?.email}</Typography>
              </Box>
              <Divider />
              <MenuItem onClick={() => { setMenuAnchor(null); navigate('/profile'); }}>
                <ListItemIcon><PersonOutline fontSize="small" /></ListItemIcon>My profile
              </MenuItem>
              <MenuItem onClick={async () => { setMenuAnchor(null); await logout(); navigate('/login'); }}>
                <ListItemIcon><Logout fontSize="small" /></ListItemIcon>Sign out
              </MenuItem>
            </Menu>
          </Toolbar>
        </AppBar>

        <Box component="main" sx={{ flex: 1, p: { xs: 2, md: 3 }, maxWidth: 1600, width: '100%', mx: 'auto' }}>
          <Outlet />
        </Box>
      </Box>
    </Box>
  );
}

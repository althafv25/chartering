import { useEffect, useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { Box, Chip, Collapse, List, ListItemButton, ListItemIcon, ListItemText, Tooltip, Typography } from '@mui/material';
import ExpandLess from '@mui/icons-material/ExpandLess';
import ExpandMore from '@mui/icons-material/ExpandMore';
import AnchorIcon from '@mui/icons-material/Anchor';
import { useQuery } from '@tanstack/react-query';
import { aisApi } from '../api/ais';
import { P } from '../constants/permissions';
import { navigation, type NavLeaf, type NavSection } from '../config/navigation';
import { useAuth } from '../auth/useAuth';
import { env } from '../config/env';

const itemSx = {
  borderRadius: 2,
  mx: 1,
  mb: 0.25,
  color: 'rgba(255,255,255,0.78)',
  '& .MuiListItemIcon-root': { color: 'rgba(255,255,255,0.65)', minWidth: 36 },
  '&:hover': { bgcolor: 'rgba(255,255,255,0.07)' },
  '&.active': { bgcolor: 'rgba(79,179,217,0.22)', color: '#fff', '& .MuiListItemIcon-root': { color: '#fff' } },
  '&.Mui-disabled': { opacity: 0.45 },
};

function SoonChip({ phase }: { phase: number }) {
  return <Chip label={`P${phase}`} size="small" sx={{ height: 18, fontSize: 10, bgcolor: 'rgba(255,255,255,0.12)', color: 'rgba(255,255,255,0.7)' }} />;
}

export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const { can } = useAuth();
  const { pathname } = useLocation();

  // Optional modules (AIS) stay hidden while switched off; the status call is cheap and only made with the permission.
  const ais = useQuery({ queryKey: ['ais', 'status'], queryFn: aisApi.status, enabled: can(P.AisView), staleTime: 5 * 60_000 });
  const featureOn = (l: NavLeaf) => l.feature !== 'ais' || ais.data?.enabled === true;
  const visibleLeaf = (l: NavLeaf) => (!l.permission || can(l.permission)) && featureOn(l);
  const sections = navigation
    .map((s) => ({ ...s, children: s.children?.filter(visibleLeaf) }))
    .filter((s) => (s.children ? s.children.length > 0 : !s.permission || can(s.permission)));

  const activeGroup = sections.find((s) => s.children?.some((c) => pathname.startsWith(c.to)))?.label;
  const [open, setOpen] = useState<Record<string, boolean>>(activeGroup ? { [activeGroup]: true } : {});

  useEffect(() => {
    if (activeGroup) setOpen((o) => ({ ...o, [activeGroup]: true }));
  }, [activeGroup]);

  const renderLink = (label: string, to: string, phase?: number, Icon?: NavSection['icon'], nested = false) => {
    const disabled = phase !== undefined;
    const content = (
      <ListItemButton
        component={disabled ? 'div' : NavLink}
        {...(disabled ? {} : { to, end: to === '/' })}
        disabled={disabled}
        onClick={disabled ? undefined : onNavigate}
        sx={{ ...itemSx, pl: nested ? 6.5 : 2, py: nested ? 0.6 : 0.9 }}
      >
        {Icon && <ListItemIcon><Icon fontSize="small" /></ListItemIcon>}
        <ListItemText primary={label} slotProps={{ primary: { fontSize: nested ? 13.5 : 14, fontWeight: nested ? 500 : 600 } }} />
        {disabled && <SoonChip phase={phase} />}
      </ListItemButton>
    );

    return disabled
      ? <Tooltip key={to} title={`Planned for phase ${phase}`} placement="right"><span>{content}</span></Tooltip>
      : <Box key={to}>{content}</Box>;
  };

  return (
    <Box sx={{ height: '100%', display: 'flex', flexDirection: 'column', bgcolor: 'primary.dark', color: '#fff' }}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.25, px: 2.5, py: 2.25 }}>
        <AnchorIcon sx={{ color: '#4fb3d9' }} />
        <Box>
          <Typography fontWeight={700} lineHeight={1.1}>{env.appName}</Typography>
          <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.6)' }}>Vessel Operations</Typography>
        </Box>
      </Box>

      <Box component="nav" aria-label="Main navigation" sx={{ flex: 1, overflowY: 'auto', pb: 2 }}>
        <List disablePadding>
          {sections.map((s) => {
            if (!s.children) return renderLink(s.label, s.to!, s.phase, s.icon);

            const isOpen = !!open[s.label];
            return (
              <Box key={s.label}>
                <ListItemButton
                  onClick={() => setOpen((o) => ({ ...o, [s.label]: !isOpen }))}
                  aria-expanded={isOpen}
                  sx={{ ...itemSx, py: 0.9 }}
                >
                  <ListItemIcon><s.icon fontSize="small" /></ListItemIcon>
                  <ListItemText primary={s.label} slotProps={{ primary: { fontSize: 14, fontWeight: 600 } }} />
                  {isOpen ? <ExpandLess fontSize="small" /> : <ExpandMore fontSize="small" />}
                </ListItemButton>
                <Collapse in={isOpen} unmountOnExit>
                  <List disablePadding>{s.children.map((c) => renderLink(c.label, c.to, c.phase, undefined, true))}</List>
                </Collapse>
              </Box>
            );
          })}
        </List>
      </Box>
    </Box>
  );
}

import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Box, Button, DialogActions, DialogContent, Stack, Tooltip, Typography } from '@mui/material';
import PictureAsPdfOutlined from '@mui/icons-material/PictureAsPdfOutlined';
import DownloadOutlined from '@mui/icons-material/DownloadOutlined';
import { DrawerDialog, DrawerDialogTitle } from './DrawerDialog';
import { LoadingButton } from './LoadingButton';
import { documentsApi } from '../api/masters';
import { useAuth } from '../auth/useAuth';
import { P } from '../constants/permissions';
import { useNotify } from '../hooks/useNotify';
import type { ApiEnvelope } from '../types/api';
import type { DocumentItem } from '../types/masters';

/** Generate a saved PDF snapshot, preview authenticated bytes, and download the same file. */
export function PdfDocumentButton({ parentType, parentId, permission, generate, disabled = false, size = 'medium' }: {
  parentType: string; parentId: number; permission: string; generate: () => Promise<ApiEnvelope<DocumentItem>>;
  disabled?: boolean; size?: 'small' | 'medium';
}) {
  const { can } = useAuth();
  const qc = useQueryClient();
  const notify = useNotify();
  const [preview, setPreview] = useState<{ document: DocumentItem; url: string } | null>(null);
  const url = preview?.url;
  useEffect(() => () => { if (url) URL.revokeObjectURL(url); }, [url]);

  const pdf = useMutation({
    mutationFn: async () => {
      const response = await generate();
      qc.invalidateQueries({ queryKey: ['documents', parentType, parentId] });
      qc.invalidateQueries({ queryKey: ['documents', 'register'] });
      qc.invalidateQueries({ queryKey: [parentType, parentId, 'activity'] });
      const blob = await documentsApi.file(response.data.id);
      return { document: response.data, url: URL.createObjectURL(blob) };
    },
    onSuccess: (result) => { setPreview(result); notify.success('PDF generated and saved in Documents.'); },
    onError: (error) => notify.error(error),
  });

  if (!can(permission) || !can(P.DocumentsUpload) || !can(P.DocumentsView)) return null;
  const download = () => {
    if (!preview) return;
    const link = document.createElement('a');
    link.href = preview.url;
    link.download = preview.document.original_filename;
    link.click();
  };

  return (
    <>
      <Tooltip title={disabled ? 'Save your changes before generating a PDF.' : 'Generate a PDF from the saved record and keep a copy in Documents.'}>
        <span><LoadingButton size={size} variant="outlined" startIcon={<PictureAsPdfOutlined />} loading={pdf.isPending} disabled={disabled} onClick={() => pdf.mutate()}>Generate PDF</LoadingButton></span>
      </Tooltip>
      <DrawerDialog open={!!preview} onClose={() => setPreview(null)} maxWidth="lg">
        <DrawerDialogTitle>PDF preview</DrawerDialogTitle>
        <DialogContent sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          {preview && <>
            <Stack spacing={0.5}>
              <Typography variant="subtitle2">{preview.document.title}</Typography>
              <Typography variant="caption" color="text.secondary">{preview.document.original_filename} · Saved in Documents. This PDF reflects the record at generation time.</Typography>
            </Stack>
            <Box component="iframe" title={preview.document.title} src={`${preview.url}#view=FitH`} sx={{ flex: 1, minHeight: 400, width: '100%', border: 1, borderColor: 'divider', borderRadius: 2 }} />
            <Typography variant="caption" color="text.secondary">If your browser cannot display the preview, use Download PDF to open the file.</Typography>
          </>}
        </DialogContent>
        <DialogActions>
          <Button variant="outlined" onClick={() => setPreview(null)}>Close</Button>
          <Button variant="contained" startIcon={<DownloadOutlined />} onClick={download}>Download PDF</Button>
        </DialogActions>
      </DrawerDialog>
    </>
  );
}

import { useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Box, Button, Chip, MenuItem, Stack, TextField, Typography } from '@mui/material';
import UploadFileOutlined from '@mui/icons-material/UploadFileOutlined';
import { documentsApi } from '../api/masters';

/** Largest file accepted by the attachment pickers. */
const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

/** File picker, the chosen files and the document type used for all of them. The parent decides when to upload. */
export function AttachFiles({ files, onFiles, docTypeId, onDocTypeId, disabled, enabled, onError }: {
  files: File[];
  onFiles: (update: (current: File[]) => File[]) => void;
  docTypeId: string;
  onDocTypeId: (id: string) => void;
  disabled?: boolean;
  /** Load the document types only while the form is open. */
  enabled: boolean;
  onError: (message: string) => void;
}) {
  const input = useRef<HTMLInputElement>(null);
  const docTypes = useQuery({ queryKey: ['document-types'], queryFn: documentsApi.types, enabled, staleTime: Infinity });

  return (
    <Stack spacing={1.5}>
      <Box>
        <input ref={input} type="file" multiple hidden onChange={(e) => {
          const picked = Array.from(e.target.files ?? []);
          e.target.value = '';
          const tooBig = picked.filter((f) => f.size > MAX_ATTACHMENT_BYTES);
          if (tooBig.length > 0) onError(`${tooBig.map((f) => f.name).join(', ')} is larger than 10 MB and was not added.`);
          onFiles((cur) => [...cur, ...picked.filter((f) => f.size <= MAX_ATTACHMENT_BYTES)]);
        }} />
        <Button variant="outlined" startIcon={<UploadFileOutlined />} onClick={() => input.current?.click()} disabled={disabled}>Choose files</Button>
        <Typography variant="body2" color="text.secondary" component="span" sx={{ ml: 1.5 }}>PDF, images, Office files, max 10 MB each</Typography>
      </Box>
      {files.length > 0 && (
        <Stack direction="row" spacing={1} useFlexGap flexWrap="wrap">
          {files.map((f, i) => (
            <Chip key={`${f.name}-${i}`} size="small" label={`${f.name} (${(f.size / 1024).toFixed(0)} KB)`}
              onDelete={disabled ? undefined : () => onFiles((cur) => cur.filter((_, j) => j !== i))} />
          ))}
        </Stack>
      )}
      {files.length > 0 && (
        <TextField select size="small" label="Document type" required value={docTypeId} onChange={(e) => onDocTypeId(e.target.value)}
          disabled={disabled} sx={{ maxWidth: 320 }}>
          {(docTypes.data ?? []).map((t) => <MenuItem key={t.id} value={String(t.id)}>{t.name}</MenuItem>)}
        </TextField>
      )}
    </Stack>
  );
}

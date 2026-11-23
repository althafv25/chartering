import { documentsApi } from '../api/masters';
import { errorMessage } from '../api/client';

/**
 * Uploads each file to the saved record. Returns the files that failed, with the last reason, so the caller can keep them
 * for a retry. Documents belong to the saved record, so the record must exist first.
 */
export async function uploadAttachments(parentType: string, parentId: number, files: File[], docTypeId: string): Promise<{ failed: File[]; reason: string }> {
  const failed: File[] = [];
  let reason = '';
  for (const f of files) {
    try {
      const fd = new FormData();
      fd.append('file', f);
      fd.append('document_type_id', docTypeId);
      fd.append('title', f.name.replace(/\.[^.]+$/, ''));
      await documentsApi.upload(parentType, parentId, fd);
    } catch (e) {
      failed.push(f);
      reason = errorMessage(e);
    }
  }
  return { failed, reason };
}

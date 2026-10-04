/**
 * Captain reports are written in ship's time with a UTC offset. The form keeps the
 * local wall time and the offset separately and sends ISO 8601 with the offset; the API
 * stores UTC. These helpers only re-express a timestamp — no business calculation.
 */
export function toOffsetIso(local: string, offset: string): string {
  return `${local.length === 16 ? `${local}:00` : local}${offset}`;
}

export function fromIso(iso: string, offset: string): string {
  const sign = offset.startsWith('-') ? -1 : 1;
  const [h, m] = offset.slice(1).split(':').map(Number);
  const shifted = new Date(new Date(iso).getTime() + sign * (h * 60 + (m || 0)) * 60_000);
  return shifted.toISOString().slice(0, 16);
}

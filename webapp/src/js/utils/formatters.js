/**
 * Format bytes into human readable binary units (KiB, MiB, GiB)
 */
export function formatBytes(bytes, decimals = 2) {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const dm = decimals < 0 ? 0 : decimals;
  const sizes = ['Bytes', 'KiB', 'MiB', 'GiB', 'TiB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

/**
 * Format integer minor units (cents) into currency string
 * e.g., 1234 -> €12.34
 */
export function formatMoney(cents, currency = 'EUR') {
  return new Intl.NumberFormat('en-IE', {
    style: 'currency',
    currency: currency,
  }).format(cents / 100);
}

/**
 * Format timestamp into standard date-time string
 */
export function formatDate(timestamp) {
  if (!timestamp) return '—';
  const date = new Date(timestamp);
  return date.toLocaleDateString('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

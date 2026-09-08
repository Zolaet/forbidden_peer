import { fiatSymbol } from './constants';

/** Format a crypto amount to up to 8 significant decimals, no trailing zeros. */
export function formatCrypto(n) {
  if (n === null || n === undefined || Number.isNaN(Number(n))) return '—';
  const num = Number(n);
  const abs = Math.abs(num);
  if (abs === 0) return '0';
  if (abs >= 1) {
    return num.toLocaleString('en-US', { maximumFractionDigits: 4 });
  }
  // tiny amounts keep meaningful precision
  return num.toLocaleString('en-US', {
    minimumSignificantDigits: 4,
    maximumSignificantDigits: 6,
  });
}

/** Format a fiat amount with its currency symbol. */
export function formatFiat(n, currency, { compact = false } = {}) {
  const num = Number(n ?? 0);
  const symbol = fiatSymbol(currency);
  const formatted = num.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: compact ? 2 : 2,
  });
  return `${symbol}${formatted}`;
}

/** Format a plain price number without currency decoration. */
export function formatPrice(n) {
  return Number(n ?? 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

/** Initials for an avatar. */
export function initials(name = '') {
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? '')
    .join('') || '?';
}

/** Human-friendly relative / absolute datetime for trade expiry etc. */
export function timeFromNow(iso) {
  if (!iso) return '';
  const then = new Date(iso);
  const diffSec = Math.round((then - Date.now()) / 1000);
  const abs = Math.abs(diffSec);
  const mins = Math.round(abs / 60);
  const hrs = Math.round(abs / 3600);

  let label;
  if (abs < 60) label = `${abs}s`;
  else if (mins < 60) label = `${mins}m`;
  else if (hrs < 48) label = `${hrs}h`;
  else label = then.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

  return diffSec >= 0 ? `${label} left` : `${label} ago`;
}

export function formatDateTime(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

/** Map a backend validation error object into a readable string. */
export function apiError(err, fallback = 'Something went wrong.') {
  const data = err?.response?.data;
  if (!data) return err?.message || fallback;

  if (data.message && typeof data.message === 'string') return data.message;
  if (data.error) return data.error;

  if (data.errors) {
    const first = Object.values(data.errors)[0];
    if (Array.isArray(first)) return first[0];
    return String(first);
  }
  return fallback;
}

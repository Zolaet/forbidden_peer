// Forbidden — central app constants (ETB ⇄ USDT on BEP-20).

export const BRAND = 'Forbidden';
export const CRYPTO = 'USDT';
export const NETWORK = 'BEP-20';
export const NETWORK_LABEL = 'BSC (BEP-20)';
/**
 * Where the Laravel API lives.
 *
 * Overridable per environment — the localhost default was hardcoded, so a
 * production build shipped pointing at the developer's own machine. Set
 * VITE_API_URL at build time (see .env.example).
 */
export const API_BASE = (
  import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
).replace(/\/+$/, '');

/** The marketplace trades exactly one fiat: Ethiopian Birr. */
export const FIAT_CURRENCIES = [{ code: 'ETB', symbol: 'Br', name: 'Ethiopian Birr' }];

export const fiatSymbol = (code) => {
  const f = FIAT_CURRENCIES.find((c) => c.code === code?.toUpperCase());
  return f?.symbol ?? (code ? `${code} ` : '');
};

export const fiatName = (code) =>
  FIAT_CURRENCIES.find((c) => c.code === code?.toUpperCase())?.name ?? code;

/** Payment method options accepted by the backend (Ethiopian rails). */
export const PAYMENT_METHOD_TYPES = [
  { value: 'bank_transfer', label: 'Bank Transfer', icon: '🏦' },
  { value: 'telebirr', label: 'Telebirr', icon: '📱' },
  { value: 'other', label: 'Other', icon: '🧾' },
];

export const methodLabel = (value) =>
  PAYMENT_METHOD_TYPES.find((m) => m.value === value)?.label ?? value;

export const methodIcon = (value) =>
  PAYMENT_METHOD_TYPES.find((m) => m.value === value)?.icon ?? '🧾';

/** Humanize a method type for badge display. */
export const methodDisplay = (value) =>
  methodLabel(value).replace(/_/g, ' ');

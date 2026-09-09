// Forbidden — central app constants (ETB ⇄ USDT on BEP-20).

export const BRAND = 'Forbidden';
export const CRYPTO = 'USDT';
export const NETWORK = 'BEP-20';
export const NETWORK_LABEL = 'BSC (BEP-20)';
export const API_BASE = 'http://127.0.0.1:8000/api';

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

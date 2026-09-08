// PeerX — central app constants (rename the brand here if you like).

export const BRAND = 'PeerX';
export const CRYPTO = 'USDT';
export const API_BASE = 'http://127.0.0.1:8000/api';

/** Currencies offered on the marketplace, with display hints. */
export const FIAT_CURRENCIES = [
  { code: 'NGN', symbol: '₦', name: 'Nigerian Naira' },
  { code: 'USD', symbol: '$', name: 'US Dollar' },
  { code: 'EUR', symbol: '€', name: 'Euro' },
  { code: 'GBP', symbol: '£', name: 'British Pound' },
  { code: 'KES', symbol: 'KSh', name: 'Kenyan Shilling' },
  { code: 'GHS', symbol: 'GH₵', name: 'Ghanaian Cedi' },
  { code: 'ZAR', symbol: 'R', name: 'South African Rand' },
  { code: 'INR', symbol: '₹', name: 'Indian Rupee' },
  { code: 'AED', symbol: 'د.إ', name: 'UAE Dirham' },
  { code: 'TRY', symbol: '₺', name: 'Turkish Lira' },
];

export const fiatSymbol = (code) => {
  const f = FIAT_CURRENCIES.find((c) => c.code === code?.toUpperCase());
  return f?.symbol ?? (code ? `${code} ` : '');
};

export const fiatName = (code) =>
  FIAT_CURRENCIES.find((c) => c.code === code?.toUpperCase())?.name ?? code;

/** Payment method options accepted by the backend. */
export const PAYMENT_METHOD_TYPES = [
  { value: 'bank_transfer', label: 'Bank Transfer', icon: '🏦' },
  { value: 'mobile_money', label: 'Mobile Money', icon: '📱' },
  { value: 'paypal', label: 'PayPal', icon: '🅿️' },
  { value: 'other', label: 'Other', icon: '🧾' },
];

export const methodLabel = (value) =>
  PAYMENT_METHOD_TYPES.find((m) => m.value === value)?.label ?? value;

export const methodIcon = (value) =>
  PAYMENT_METHOD_TYPES.find((m) => m.value === value)?.icon ?? '🧾';

/** Humanize a method type for badge display. */
export const methodDisplay = (value) =>
  methodLabel(value).replace(/_/g, ' ');

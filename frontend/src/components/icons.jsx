/* Tiny inline icon set (stroke-based, lucide-like). All accept size + props. */
const S = ({ size = 18, children, ...p }) => (
  <svg
    width={size}
    height={size}
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="2"
    strokeLinecap="round"
    strokeLinejoin="round"
    aria-hidden="true"
    {...p}
  >
    {children}
  </svg>
);

export const IconX = (p) => <S {...p}><path d="M18 6 6 18M6 6l12 12" /></S>;
export const IconPlus = (p) => <S {...p}><path d="M12 5v14M5 12h14" /></S>;
export const IconWallet = (p) => (
  <S {...p}><path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7" /><path d="M17 12h.01" /></S>
);
export const IconLogout = (p) => <S {...p}><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" /></S>;
export const IconChevDown = (p) => <S {...p}><path d="m6 9 6 6 6-6" /></S>;
export const IconCopy = (p) => <S {...p}><rect width="14" height="14" x="8" y="8" rx="2" /><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" /></S>;
export const IconCheck = (p) => <S {...p}><path d="M20 6 9 17l-5-5" /></S>;
export const IconClock = (p) => <S {...p}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 3" /></S>;
export const IconShield = (p) => <S {...p}><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3Z" /></S>;
export const IconRefresh = (p) => <S {...p}><path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5" /></S>;
export const IconUser = (p) => <S {...p}><circle cx="12" cy="8" r="4" /><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6" /></S>;
export const IconBank = (p) => <S {...p}><path d="M3 21h18M4 18h16M6 18v-7M10 18v-7M14 18v-7M18 18v-7M3 7l9-4 9 4H3Z" /></S>;
export const IconTrend = (p) => <S {...p}><path d="m3 17 6-6 4 4 8-8" /><path d="M15 7h6v6" /></S>;
export const IconStar = (p) => <S {...p}><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1L12 2Z" /></S>;
export const IconSend = (p) => <S {...p}><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7Z" /></S>;
export const IconLock = (p) => <S {...p}><rect width="18" height="11" x="3" y="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></S>;
export const IconUpload = (p) => <S {...p}><path d="M12 15V3m0 0L7 8m5-5 5 5" /><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" /></S>;
export const IconArrowRight = (p) => <S {...p}><path d="M5 12h14M12 5l7 7-7 7" /></S>;
export const IconScale = (p) => <S {...p}><path d="M12 3v18M8 21h8M3 7h18M6 7l-3 6a3 3 0 0 0 6 0L6 7ZM18 7l-3 6a3 3 0 0 0 6 0l-3-6Z" /></S>;
export const IconShieldCheck = (p) => <S {...p}><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3Z" /><path d="m9 12 2 2 4-4" /></S>;
export const IconSpinner = (p) => <span className="spin" style={{ width: p.size || 20, height: p.size || 20 }} />;
export const IconTrash = (p) => <S {...p}><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6" /></S>;
export const IconCreditCard = (p) => <S {...p}><rect width="20" height="14" x="2" y="5" rx="2" /><path d="M2 10h20" /></S>;

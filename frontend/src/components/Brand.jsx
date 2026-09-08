import { BRAND } from '../lib/constants';

/** Hexagon coin glyph used as the app mark. */
export function CoinMark({ size = 26 }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 32 32"
      fill="none"
      aria-hidden="true"
      style={{ display: 'block' }}
    >
      <path
        d="M16 1.8 27.3 8v16L16 30.2 4.7 24V8L16 1.8Z"
        fill="url(#coinGrad)"
      />
      <path
        d="M16 5.2 24.8 9.9v12.2L16 26.8l-8.8-4.7V9.9L16 5.2Z"
        fill="rgba(255,255,255,0.08)"
      />
      <path
        d="M12 10.5c0-1.4 1.2-2.2 2.7-2.2h3.2c1.6 0 3 .8 3 2.4 0 1.1-.7 1.9-1.7 2.3v.05c1.3.4 2.2 1.3 2.2 2.7 0 1.8-1.5 2.8-3.3 2.8h-3.4c-1.6 0-2.7-.9-2.7-2.3"
        stroke="#0a1226"
        strokeWidth="1.9"
        strokeLinecap="round"
      />
      <path
        d="M15.4 8.5v4.9M15.4 16.4v5.1M13.4 21.5h4"
        stroke="#0a1226"
        strokeWidth="1.9"
        strokeLinecap="round"
      />
      <defs>
        <linearGradient id="coinGrad" x1="4.7" y1="2" x2="27" y2="30" gradientUnits="userSpaceOnUse">
          <stop stopColor="#8fb0ff" />
          <stop offset="0.5" stopColor="#5e8cff" />
          <stop offset="1" stopColor="#3d6bf2" />
        </linearGradient>
      </defs>
    </svg>
  );
}

/** Full wordmark (icon + name). `tone` picks the text colour on its backdrop. */
export function Wordmark({ size = 26, tone = 'light', fontSize = 19 }) {
  return (
    <span className="brand" style={{ display: 'inline-flex', alignItems: 'center', gap: 10 }}>
      <CoinMark size={size} />
      <span
        style={{
          fontSize,
          fontWeight: 800,
          letterSpacing: '-0.02em',
          color: tone === 'light' ? '#fff' : '#0a1226',
          lineHeight: 1,
        }}
      >
        Peer<span style={{ color: tone === 'light' ? 'var(--brand-2)' : 'var(--brand)' }}>X</span>
      </span>
    </span>
  );
}

export { BRAND };

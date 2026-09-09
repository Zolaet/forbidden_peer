import { useMemo, useState } from 'react';
import { useNavigate, useSearchParams, Link } from 'react-router-dom';
import api from '../api/axios';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../components/Toast';
import Field from '../components/Field';
import { CRYPTO, fiatSymbol, fiatName } from '../lib/constants';
import { apiError, formatCrypto, formatPrice } from '../lib/format';
import { IconShieldCheck, IconWallet, IconTrend, IconArrowRight } from '../components/icons';

const WINDOWS = [
  { value: 15, label: '15 minutes' },
  { value: 30, label: '30 minutes' },
  { value: 45, label: '45 minutes' },
  { value: 60, label: '60 minutes' },
];

export default function PostOffer() {
  const { user } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  const [params] = useSearchParams();

  const prefillType = params.get('type') === 'sell' ? 'sell' : params.get('type') === 'buy' ? 'buy' : null;

  const [type, setType] = useState(prefillType ?? 'buy');
  const currency = 'ETB'; // the market trades USDT against Ethiopian Birr only
  const [price, setPrice] = useState(params.get('price') || '');
  const [amount, setAmount] = useState('');
  const [minLimit, setMinLimit] = useState('');
  const [maxLimit, setMaxLimit] = useState('');
  const [payWindow, setPayWindow] = useState('30');
  const [errors, setErrors] = useState({});
  const [serverError, setServerError] = useState('');
  const [busy, setBusy] = useState(false);

  const isSell = type === 'sell';
  const symbol = fiatSymbol(currency);
  const wallet = user?.wallet;
  const available = Number(wallet?.available_balance ?? 0);

  const numPrice = Number(price);
  const numAmount = Number(amount);
  const totalFiat = numPrice * numAmount;

  const preview = useMemo(() => {
    if (!numPrice || !numAmount) return null;
    return { totalFiat };
  }, [numPrice, numAmount, totalFiat]);

  const changeType = (t) => {
    setType(t);
    setServerError('');
  };

  const validate = () => {
    const e = {};
    if (!numPrice || numPrice <= 0) e.price = 'Enter a price greater than 0.';
    if (!numAmount || numAmount <= 0) e.amount = 'Enter the amount of USDT.';
    if (isSell && numAmount > available) e.amount = `Your available balance is ${formatCrypto(available)} ${CRYPTO}.`;
    if (!minLimit || Number(minLimit) <= 0) e.minLimit = 'Required.';
    if (!maxLimit || Number(maxLimit) < Number(minLimit))
      e.maxLimit = 'Max limit can’t be below the min limit.';
    return e;
  };

  const submit = async (ev) => {
    ev.preventDefault();
    setServerError('');
    const e = validate();
    setErrors(e);
    if (Object.keys(e).length) return;

    setBusy(true);
    try {
      const { data } = await api.post('/offers', {
        type,
        fiat_currency: currency,
        price: numPrice,
        total_amount: numAmount,
        min_limit: Number(minLimit),
        max_limit: Number(maxLimit),
        payment_window_minutes: Number(payWindow),
      });
      toast.ok('Offer live', `Your ${type} ad is now public on the marketplace.`);
      navigate('/marketplace');
      return data;
    } catch (err) {
      setServerError(apiError(err, 'Could not post this offer.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="container page">
      <div className="page-head">
        <div>
          <div className="eyebrow">Advertise</div>
          <h1 className="page-title">Post an offer</h1>
          <p className="page-sub">Set your rate and let traders come to you.</p>
        </div>
        <Link to="/marketplace" className="btn btn-ghost btn-sm">Back to market</Link>
      </div>

      <div className="td-cols" style={{ marginTop: 0 }}>
        <form className="panel td-card" onSubmit={submit} noValidate style={{ maxWidth: 'none' }}>
          <div className="tc-title" style={{ marginBottom: 16 }}>
            Offer details
            <small>Fields match the ad you advertise</small>
          </div>

          {/* direction */}
          <div className="field">
            <div className="label-row">
              <label>You are {isSell ? 'selling' : 'buying'} {CRYPTO}</label>
            </div>
            <div className="segmented lg" style={{ width: '100%' }}>
              <button type="button" aria-pressed={!isSell} onClick={() => changeType('buy')} style={{ flex: 1, color: !isSell ? 'var(--buy)' : undefined }}>
                <IconTrend size={17} style={{ transform: 'rotate(-90deg)' }} /> Buy {CRYPTO}
              </button>
              <button type="button" aria-pressed={isSell} onClick={() => changeType('sell')} style={{ flex: 1, color: isSell ? 'var(--sell)' : undefined }}>
                <IconTrend size={17} style={{ transform: 'rotate(90deg)' }} /> Sell {CRYPTO}
              </button>
            </div>
            <span className="tiny faint">
              {isSell
                ? 'Sell ads are covered by your USDT balance and locked into escrow when a buyer starts a trade.'
                : 'Buy ads tell crypto sellers you’re ready to pay fiat at your rate.'}
            </span>
          </div>

          <div className="row" style={{ gap: 14, flexWrap: 'wrap' }}>
            <div style={{ flex: '1 1 220px' }}>
              <Field label="Fiat currency" required>
                <div
                  className="input"
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    pointerEvents: 'none',
                  }}
                >
                  <span>
                    {currency} — {fiatName(currency)}
                  </span>
                  <span style={{ color: 'var(--text-3)' }}>{symbol}</span>
                </div>
              </Field>
            </div>
            <div style={{ flex: '1 1 220px' }}>
              <Field label={`Unit price (${currency})`} hint={<span className="tiny faint">per 1 {CRYPTO}</span>} error={errors.price} required>
                <div className="input-affix">
                  <input className="input" inputMode="decimal" placeholder="1500.00" value={price}
                    onChange={(e) => setPrice(e.target.value)} />
                  <span className="affix">{symbol}</span>
                </div>
              </Field>
            </div>
          </div>

          <div className="row" style={{ gap: 14, flexWrap: 'wrap' }}>
            <div style={{ flex: '1 1 220px' }}>
              <Field label="Total amount" hint={<span className="tiny faint">{isSell && `Balance ${formatCrypto(available)} ${CRYPTO}`}</span>} error={errors.amount} required>
                <div className="input-affix">
                  <input className="input" inputMode="decimal" placeholder="50.00" value={amount}
                    onChange={(e) => setAmount(e.target.value)} />
                  <span className="affix">{CRYPTO}</span>
                </div>
              </Field>
            </div>
            <div style={{ flex: '1 1 220px' }}>
              <Field label="Payment window" required>
                <select className="input" value={payWindow} onChange={(e) => setPayWindow(e.target.value)}>
                  {WINDOWS.map((w) => (
                    <option key={w.value} value={w.value}>{w.label}</option>
                  ))}
                </select>
              </Field>
            </div>
          </div>

          <div className="row" style={{ gap: 14, flexWrap: 'wrap' }}>
            <div style={{ flex: '1 1 220px' }}>
              <Field label="Min order (fiat)" error={errors.minLimit} required>
                <div className="input-affix">
                  <input className="input" inputMode="decimal" placeholder="1000.00" value={minLimit}
                    onChange={(e) => setMinLimit(e.target.value)} />
                  <span className="affix">{symbol}</span>
                </div>
              </Field>
            </div>
            <div style={{ flex: '1 1 220px' }}>
              <Field label="Max order (fiat)" error={errors.maxLimit} required>
                <div className="input-affix">
                  <input className="input" inputMode="decimal" placeholder="100000.00" value={maxLimit}
                    onChange={(e) => setMaxLimit(e.target.value)} />
                  <span className="affix">{symbol}</span>
                </div>
              </Field>
            </div>
          </div>

          {serverError && (
            <div className="auth-banner" role="alert">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
              {serverError}
            </div>
          )}

          <button type="submit" className={`btn ${isSell ? 'btn-sell' : 'btn-buy'} btn-lg btn-block`} disabled={busy} style={{ marginTop: 6 }}>
            {busy ? 'Posting offer…' : `Post ${isSell ? 'sell' : 'buy'} offer`}
          </button>
        </form>

        {/* preview + tips */}
        <aside>
          <div className="panel td-card action-panel">
            <div className="ap-label">Live preview</div>
            {preview ? (
              <>
                <div className="ap-head">
                  {symbol}
                  {formatPrice(totalFiat)}
                </div>
                <p className="ap-sub">
                  Trader pays <b>{symbol}
                  {formatPrice(totalFiat)}</b> for <b>{formatCrypto(numAmount)} {CRYPTO}</b> at {symbol}
                  {formatPrice(numPrice)}/unit.
                </p>
              </>
            ) : (
              <>
                <div className="ap-head" style={{ color: 'var(--text-2)' }}>—</div>
                <p className="ap-sub">Fill in the price and amount to see the order value.</p>
              </>
            )}

            <hr className="divider" />

            {isSell ? (
              <div className="stack">
                <div className="notice notice-muted">
                  <IconWallet size={16} style={{ marginTop: 2 }} />
                  <span>
                    Available to sell: <b>{formatCrypto(available)} {CRYPTO}</b>
                    {available >= numAmount && numAmount > 0 ? ' ✓ covered' : available < numAmount && numAmount > 0 ? ' — not covered' : ''}
                  </span>
                </div>
                <div className="notice notice-warn">
                  <IconShieldCheck size={16} style={{ marginTop: 2 }} />
                  <span>When a buyer starts a trade, this amount locks into escrow until you release.</span>
                </div>
              </div>
            ) : (
              <div className="stack">
                <div className="notice notice-info">
                  <IconTrend size={16} style={{ marginTop: 2 }} />
                  <span>Buy ads are matched with crypto sellers who want to trade at your rate.</span>
                </div>
                <div className="notice notice-muted">
                  <IconShieldCheck size={16} style={{ marginTop: 2 }} />
                  <span>You'll need a saved payment method before a seller can take your offer.</span>
                </div>
                <Link to="/payment-methods" className="btn btn-soft btn-sm">
                  Manage payment methods <IconArrowRight size={14} />
                </Link>
              </div>
            )}
          </div>
        </aside>
      </div>
    </div>
  );
}

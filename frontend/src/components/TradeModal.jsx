import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/axios';
import { useAuth } from '../context/AuthContext';
import { useToast } from './Toast';
import Modal from './Modal';
import Field from './Field';
import { CRYPTO, fiatSymbol } from '../lib/constants';
import { methodIcon, methodLabel } from '../lib/constants';
import { apiError, formatCrypto, formatPrice } from '../lib/format';
import { saveLocalTrade } from '../lib/localTrades';
import { IconShieldCheck, IconLock } from './icons';

export default function TradeModal({ offer, mode, onClose, onStarted }) {
  const { user } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();

  const [amount, setAmount] = useState('');
  const [methodId, setMethodId] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const methods = user?.payment_methods ?? [];

  useEffect(() => {
    if (offer) {
      setAmount('');
      setMethodId(methods[0]?.id ? String(methods[0].id) : '');
      setError('');
      setBusy(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [offer]);

  const isBuy = mode === 'buy';
  const symbol = offer ? fiatSymbol(offer.fiat_currency) : '';
  const price = Number(offer?.price ?? 0);
  const remaining = Number(offer?.remaining_amount ?? 0);

  const numAmount = Number(amount);
  const fiatTotal = numAmount * price;
  const minLimit = Number(offer?.min_limit ?? 0);
  const maxLimit = Number(offer?.max_limit ?? 0);

  const problems = useMemo(() => {
    const list = [];
    if (!amount || Number.isNaN(numAmount) || numAmount <= 0)
      list.push('Enter a valid amount.');
    else {
      if (numAmount > remaining) list.push(`Only ${formatCrypto(remaining)} ${CRYPTO} available on this ad.`);
      if (minLimit > 0 && fiatTotal < minLimit)
        list.push(`Order must be at least ${symbol}${formatPrice(minLimit)}.`);
      if (maxLimit > 0 && fiatTotal > maxLimit)
        list.push(`Order can’t exceed ${symbol}${formatPrice(maxLimit)}.`);
    }
    if (!methodId) list.push('Choose a payment method.');
    return list;
  }, [amount, numAmount, remaining, minLimit, maxLimit, fiatTotal, methodId, symbol]);

  if (!offer) return null;

  const submit = async (e) => {
    e.preventDefault();
    if (problems.length || busy) {
      setError(problems[0]);
      return;
    }
    setError('');
    setBusy(true);
    try {
      const { data } = await api.post('/trades', {
        offer_id: offer.id,
        crypto_amount: numAmount,
        payment_method_id: Number(methodId),
      });
      saveLocalTrade(data.trade, user.id, user.name);
      toast.ok('Trade started', `Order ${data.trade.trade_ref} — escrow locked.`);
      onClose?.();
      onStarted?.(data.trade);
      navigate(`/trades/${data.trade.trade_ref}`);
    } catch (err) {
      setError(apiError(err, 'Could not open this trade.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      title={isBuy ? `Buy ${CRYPTO} from ${offer.user?.name}` : `Sell ${CRYPTO} to ${offer.user?.name}`}
    >
      {/* rate summary strip */}
      <div className="row-between" style={{ padding: '12px 14px', borderRadius: 'var(--r-sm)', background: 'var(--bg-2)', border: '1px solid var(--line)', marginBottom: 18, flexWrap: 'wrap' }}>
        <span className="small muted">
          Unit price · {offer.fiat_currency}
          <b className="mono-num" style={{ marginLeft: 6, color: 'var(--text-1)' }}>
            {symbol}{formatPrice(price)}
          </b>
        </span>
        <span className="small muted">
          Available
          <b className="mono-num" style={{ marginLeft: 6, color: 'var(--text-1)' }}>
            {formatCrypto(remaining)} {CRYPTO}
          </b>
        </span>
      </div>

      {error && (
        <div className="auth-banner" role="alert" style={{ marginBottom: 16 }}>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
          {error}
        </div>
      )}

      <form onSubmit={submit} noValidate>
        <Field
          label={`I want to ${isBuy ? 'buy' : 'sell'}`}
          hint={<span className="tiny faint">Min {symbol}{formatPrice(offer.min_limit)} · Max {symbol}{formatPrice(offer.max_limit)}</span>}
          error={problems[0]}
        >
          <div className="input-affix">
            <input
              className="input"
              type="number"
              min="0"
              step="any"
              inputMode="decimal"
              placeholder="0.00"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              autoFocus
            />
            <span className="affix">{CRYPTO}</span>
          </div>
          <div className="row-between" style={{ marginTop: 8 }}>
            <span className="small muted">
              You pay {symbol}
              <b className="mono-num" style={{ marginLeft: 6, color: numAmount > 0 ? 'var(--text-1)' : 'var(--text-3)' }}>
                {numAmount > 0 ? formatPrice(fiatTotal) : '0.00'}
              </b>
            </span>
            {!isBuy && (
              <span className="tiny faint">
                Locked from your wallet into escrow
              </span>
            )}
          </div>
        </Field>

        {methods.length > 0 ? (
          <Field label="Payment method" error={problems.find((p) => p.startsWith('Choose'))}>
            <select className="input" value={methodId} onChange={(e) => setMethodId(e.target.value)}>
              {methods.map((m) => (
                <option key={m.id} value={m.id}>
                  {methodIcon(m.type)} {methodLabel(m.type)} · {m.account_name} ({m.bank_or_provider_name})
                </option>
              ))}
            </select>
          </Field>
        ) : (
          <Field label="Payment method">
            <div className="row" style={{ padding: '12px 14px', borderRadius: 'var(--r-sm)', border: '1px dashed var(--line-strong)' }}>
              <span className="small muted">You need a saved payment method to trade.</span>
              <button type="button" className="btn btn-soft btn-sm" onClick={() => navigate('/payment-methods')} style={{ marginLeft: 'auto' }}>
                Add payment method
              </button>
            </div>
          </Field>
        )}

        <button
          type="submit"
          className={`btn ${isBuy ? 'btn-buy' : 'btn-sell'} btn-lg btn-block`}
          disabled={busy || (methods.length > 0 && problems.length > 0)}
          style={{ marginTop: 6 }}
        >
          {busy
            ? 'Opening trade…'
            : `${isBuy ? 'Buy' : 'Sell'} ${amount && numAmount > 0 ? `${formatCrypto(numAmount)} ` : ''}${CRYPTO}`}
        </button>

        <p className="row" style={{ justifyContent: 'center', marginTop: 14, gap: 6 }} title="Your USDT is locked until both sides confirm">
          <IconShieldCheck size={15} style={{ color: 'var(--buy)' }} />
          <span className="tiny muted">Escrow-protected — {CRYPTO} is locked until payment is confirmed and released.</span>
        </p>
        {!isBuy && (
          <p className="row" style={{ justifyContent: 'center', marginTop: 8, gap: 6 }}>
            <IconLock size={14} style={{ color: 'var(--gold)' }} />
            <span className="tiny muted">Your {CRYPTO} is deducted from your available balance now.</span>
          </p>
        )}
      </form>
    </Modal>
  );
}

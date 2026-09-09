import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api/axios';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../components/Toast';
import {
  getLocalTrade,
  patchLocalTrade,
} from '../lib/localTrades';
import { CRYPTO, fiatSymbol, methodDisplay } from '../lib/constants';
import { apiError, formatCrypto, formatDateTime, formatPrice } from '../lib/format';
import { initials } from '../lib/format';
import StatusPill from '../components/StatusPill';
import { IconCheck, IconClock, IconShieldCheck, IconUpload, IconLock, IconArrowRight } from '../components/icons';
import '../styles/trades.css';

function Kv({ k, v, icon: Icon, mono }) {
  return (
    <div className="kv-row">
      <span className="k">
        {Icon && <Icon size={15} />} {k}
      </span>
      <span className={`v ${mono ? 'mono-num' : ''}`}>{v}</span>
    </div>
  );
}

function Step({ title, desc, icon: Icon, state }) {
  return (
    <div className={`step ${state}`}>
      <span className="st-ico">{state === 'done' ? <IconCheck size={15} /> : <Icon size={15} />}</span>
      <div className="st-body">
        <div className="st-title">{title}</div>
        {desc && <div className="st-desc">{desc}</div>}
      </div>
    </div>
  );
}

export default function TradeDetail() {
  const { tradeRef } = useParams();
  const { user, refresh } = useAuth();
  const toast = useToast();

  const [trade, setTrade] = useState(() => getLocalTrade(tradeRef));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  // mark-paid sub-state
  const [showPay, setShowPay] = useState(false);
  const [proofFile, setProofFile] = useState(null);
  const [preview, setPreview] = useState('');
  const [note, setNote] = useState('');
  // release sub-state
  const [confirmed, setConfirmed] = useState(false);
  const fileRef = useRef(null);

  useEffect(() => {
    setTrade(getLocalTrade(tradeRef));
  }, [tradeRef]);

  // Keep the wallet in sync (balances move when escrow locks / releases).
  useEffect(() => {
    refresh().catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (!user) return null;
  if (!trade) {
    return (
      <div className="container page">
        <div className="panel state-block">
          <span className="dot" style={{ width: 12, height: 12, background: 'var(--gold)' }} />
          <div className="state-title">Trade not found in this session</div>
          <p className="small">
            Trades opened on another device or before this build won't load here — the API
            doesn't expose an order list. Re-open one from the marketplace.
          </p>
          <Link to="/marketplace" className="btn btn-primary btn-sm" style={{ marginTop: 10 }}>
            Browse marketplace <IconArrowRight size={14} />
          </Link>
        </div>
      </div>
    );
  }

  const meId = String(user.id);
  const isBuyer = String(trade.buyer_id) === meId;
  const isSeller = String(trade.seller_id) === meId;
  const role = isBuyer ? 'buyer' : isSeller ? 'seller' : 'other';

  const symbol = fiatSymbol(trade.offer?.fiat_currency);
  const amount = Number(trade.crypto_amount);
  const fiat = Number(trade.fiat_amount);
  const counterpartName = isBuyer ? trade.seller_name : trade.buyer_name;
  const live = trade.status === 'pending' || trade.status === 'paid';
  const payMethod = trade.payment;

  const sync = (ref, patch) => {
    const next = patchLocalTrade(ref, patch);
    if (next) setTrade(next);
  };

  const markPaid = async (e) => {
    e.preventDefault();
    if (!proofFile) {
      setError('Attach a proof-of-payment image to continue.');
      return;
    }
    setBusy(true);
    setError('');
    const fd = new FormData();
    fd.append('proof_image', proofFile);
    if (note.trim()) fd.append('note', note.trim());
    try {
      const { data } = await api.post(`/trades/${tradeRef}/mark-paid`, fd);
      sync(tradeRef, { status: data.trade?.status ?? 'paid', paid_at: new Date().toISOString() });
      toast.ok('Payment marked', 'The seller has been notified to confirm and release.');
      setShowPay(false);
      setProofFile(null);
      setNote('');
    } catch (err) {
      setError(apiError(err, 'Could not mark as paid.'));
    } finally {
      setBusy(false);
    }
  };

  const release = async () => {
    if (!confirmed) return;
    setBusy(true);
    setError('');
    try {
      const { data } = await api.post(`/trades/${tradeRef}/release`, { confirm_receipt: 1 });
      sync(tradeRef, { status: data.trade?.status ?? 'completed', completed_at: new Date().toISOString() });
      toast.ok('Escrow released', `${formatCrypto(amount)} ${CRYPTO} sent to the buyer.`);
      refresh().catch(() => {});
    } catch (err) {
      setError(apiError(err, 'Could not release escrow.'));
    } finally {
      setBusy(false);
    }
  };

  const pickFile = (file) => {
    setProofFile(file);
    if (file) {
      const url = URL.createObjectURL(file);
      setPreview(url);
    } else {
      setPreview('');
    }
  };

  const buyerSteps = [
    {
      state: trade.status === 'paid' || trade.status === 'completed' ? 'done' : trade.status === 'pending' ? 'active' : '',
      icon: IconLock,
      title: `Send ${symbol}${formatPrice(fiat)}`,
      desc: `Transfer to ${trade.seller_name || 'the seller'}${payMethod ? ` via ${methodDisplay(payMethod.type)}` : ''}. Use the account details exchanged on the platform.`,
    },
    {
      state: trade.status === 'completed' ? 'done' : trade.status === 'paid' ? 'active' : '',
      icon: IconShieldCheck,
      title: 'Seller confirms & releases',
      desc: 'Once they verify your transfer, they release the escrow. This is usually within minutes.',
    },
    {
      state: trade.status === 'completed' ? 'done' : '',
      icon: IconCheck,
      title: `${formatCrypto(amount)} ${CRYPTO} arrives in your wallet`,
    },
  ];

  const sellerSteps = [
    {
      state: 'done',
      icon: IconLock,
      title: `${formatCrypto(amount)} ${CRYPTO} locked in escrow`,
      desc: 'The buyer opened this order against your ad, so your USDT is protected.',
    },
    {
      state: trade.status === 'paid' || trade.status === 'completed' ? 'done' : 'active',
      icon: IconUpload,
      title: 'Buyer pays & uploads proof',
      desc: `Expect ${symbol}${formatPrice(fiat)} off-platform. Only release once the money truly lands.`,
    },
    {
      state: trade.status === 'completed' ? 'done' : trade.status === 'paid' ? 'active' : '',
      icon: IconCheck,
      title: 'Release escrow to the buyer',
    },
  ];

  const steps = isBuyer ? buyerSteps : sellerSteps;

  return (
    <div className="container page">
      {/* header */}
      <div className="td-hero">
        <div>
          <div className="eyebrow">Order</div>
          <div className="th-ref">{trade.trade_ref}</div>
          <div className="th-sub">
            <StatusPill status={trade.status} />
            <span className="tiny muted">Opened {formatDateTime(trade.opened_at)}</span>
            {live && trade.expires_at && (
              <span className="tiny" style={{ color: 'var(--gold)', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                <IconClock size={12} /> {formatDateTime(trade.expires_at)} payment window
              </span>
            )}
          </div>
        </div>
        <div className="td-amount">
          <div className="ta-crypto tnum">
            {formatCrypto(amount)} <span style={{ fontSize: 15, color: 'var(--brand-2)' }}>{CRYPTO}</span>
          </div>
          <div className="ta-fiat">
            {symbol}
            {formatPrice(fiat)} total · {formatPrice(trade.unit_price)}/unit
          </div>
        </div>
      </div>

      {error && (
        <div className="auth-banner" role="alert" style={{ marginTop: 16, marginBottom: 0 }}>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
          {error}
        </div>
      )}

      <div className="td-cols">
        {/* left column */}
        <div>
          <section className="panel td-card">
            <div className="tc-title">Counterparty</div>
            <div className="counterparty" style={{ marginTop: 12 }}>
              <span className="avatar" style={{ width: 44, height: 44, fontSize: 16 }}>
                {initials(counterpartName || '?')}
              </span>
              <div>
                <div className="cp-name">{counterpartName || '—'}</div>
                <div className="cp-role">
                  {isBuyer ? 'You are the buyer — they sell you USDT' : isSeller ? 'You are the seller' : 'Trade participant'}
                </div>
              </div>
              <div style={{ marginLeft: 'auto' }}>
                <StatusPill status={trade.status} />
              </div>
            </div>

            <div className="kv">
              <Kv k="Order side" icon={isBuyer ? IconArrowRight : IconArrowRight} mono
                v={<span style={{ color: isBuyer ? 'var(--buy)' : 'var(--sell)', fontWeight: 700 }}>{isBuyer ? `Buying ${CRYPTO}` : `Selling ${CRYPTO}`}</span>} />
              <Kv k="Amount" v={`${formatCrypto(amount)} ${CRYPTO}`} mono />
              <Kv k="Unit price" v={`${symbol}${formatPrice(trade.unit_price)}`} mono />
              <Kv k="Total (fiat)" v={`${symbol}${formatPrice(fiat)}`} mono />
              {payMethod && (
                <Kv k="Payment channel" v={`${methodDisplay(payMethod.type)} · ${payMethod.account_name || ''} ${payMethod.bank_or_provider_name || ''}`} />
              )}
            </div>
          </section>

          <section className="panel td-card">
            <div className="tc-title">
              Progress
              {isSeller && <small>Escrow is held by Forbidden</small>}
            </div>
            <div className="steps">{steps.map((s, i) => (
              <Step key={i} title={s.title} desc={s.desc} icon={s.icon} state={s.state} />
            ))}</div>
          </section>

          <section className="panel td-card escrow-card">
            <div className="tc-title" style={{ color: 'var(--gold)' }}>
              <span className="row" style={{ gap: 7 }}>
                <IconLock size={16} /> Escrow guard
              </span>
            </div>
            <p className="small" style={{ color: 'var(--text-2)', marginTop: 8 }}>
              {isSeller ? (
                <>Your <b>{formatCrypto(amount)} {CRYPTO}</b> is locked by the platform and can't be spent until you release it after confirming the buyer's payment — or it's returned if the order is cancelled.</>
              ) : (
                <>The seller's <b>{formatCrypto(amount)} {CRYPTO}</b> is locked by the platform. Neither of you can touch it until you pay and they release it — no chargebacks, no "already sent" disputes.</>
              )}
            </p>
          </section>
        </div>

        {/* right column — action panel */}
        <aside>
          <div className="panel td-card action-panel">
            {isBuyer && trade.status === 'pending' && (
              <>
                <div className="ap-label">Action required</div>
                <div className="ap-head">
                  Pay {symbol}
                  <span className="mono-num">{formatPrice(fiat)}</span>
                </div>
                <p className="ap-sub">
                  Send {symbol}
                  {formatPrice(fiat)} to <b>{trade.seller_name || 'the seller'}</b>
                  {payMethod ? ` via ${methodDisplay(payMethod.type)}` : ''}. Then confirm with a
                  screenshot.
                </p>

                {!showPay ? (
                  <button className="btn btn-primary btn-lg btn-block" onClick={() => setShowPay(true)}>
                    I've made the payment
                  </button>
                ) : (
                  <form onSubmit={markPaid}>
                    <div
                      className={`drop ${proofFile ? 'has-file' : ''}`}
                      role="button"
                      tabIndex={0}
                      onClick={() => fileRef.current?.click()}
                      onKeyDown={(e) => e.key === 'Enter' && fileRef.current?.click()}
                    >
                      <input
                        ref={fileRef}
                        type="file"
                        className="file-input-hidden"
                        accept="image/png,image/jpeg,image/jpg"
                        onChange={(e) => pickFile(e.target.files?.[0] ?? null)}
                      />
                      <span className="drop-ico"><IconUpload size={20} /></span>
                      {proofFile ? (
                        <>
                          <div style={{ fontWeight: 700 }}>Proof attached · {proofFile.name}</div>
                          {preview && (
                            <img
                              src={preview}
                              alt="Payment proof"
                              style={{ maxHeight: 90, margin: '8px auto 0', borderRadius: 8, border: '1px solid var(--line)' }}
                            />
                          )}
                        </>
                      ) : (
                        <>
                          <div style={{ fontWeight: 700 }}>Upload proof of payment</div>
                          <div className="tiny faint">PNG or JPG, up to 5&nbsp;MB</div>
                        </>
                      )}
                    </div>

                    <textarea
                      className="input"
                      style={{ marginTop: 10 }}
                      placeholder="Note to seller (optional)"
                      value={note}
                      onChange={(e) => setNote(e.target.value)}
                      maxLength={500}
                    />

                    <div className="row" style={{ marginTop: 14, gap: 10 }}>
                      <button type="button" className="btn btn-ghost" style={{ flex: 1 }} onClick={() => { setShowPay(false); setProofFile(null); }}>
                        Back
                      </button>
                      <button type="submit" className="btn btn-buy" style={{ flex: 2 }} disabled={busy || !proofFile}>
                        {busy ? 'Submitting…' : 'Confirm payment'}
                      </button>
                    </div>
                  </form>
                )}
              </>
            )}

            {isBuyer && trade.status === 'paid' && (
              <>
                <div className="ap-label">Waiting on seller</div>
                <div className="ap-head" style={{ color: 'var(--brand-2)' }}>Payment received</div>
                <p className="ap-sub">The seller has been notified. They'll confirm your transfer and release the escrow.</p>
                <div className="notice notice-info">
                  <IconClock size={16} style={{ marginTop: 2 }} />
                  <span>Hang tight. If the seller never responds the platform can step in — your USDT is safe in escrow.</span>
                </div>
              </>
            )}

            {isSeller && trade.status === 'pending' && (
              <>
                <div className="ap-label">Status</div>
                <div className="ap-head" style={{ color: 'var(--gold)' }}>{formatCrypto(amount)} {CRYPTO} in escrow</div>
                <p className="ap-sub">
                  The buyer will send <b>{symbol}
                  {formatPrice(fiat)}</b> off-platform and upload a proof. Verify the money is really in your account before releasing.
                </p>
                <div className="notice notice-warn">
                  <IconClock size={16} style={{ marginTop: 2 }} />
                  <span>Release only after the payment appears in your {payMethod ? methodDisplay(payMethod.type) : 'account'}.</span>
                </div>
              </>
            )}

            {isSeller && trade.status === 'paid' && (
              <>
                <div className="ap-label">Action required</div>
                <div className="ap-head" style={{ color: 'var(--buy)' }}>Buyer says they paid</div>
                <p className="ap-sub">
                  Confirm you received <b>{symbol}
                  {formatPrice(fiat)}</b>, then release the escrowed {formatCrypto(amount)} {CRYPTO} to the buyer.
                </p>

                <label className="row" style={{ gap: 10, padding: '10px 12px', borderRadius: 8, background: 'var(--bg-2)', border: '1px solid var(--line)', cursor: 'pointer', marginBottom: 14, fontSize: 13.5 }}>
                  <input
                    type="checkbox"
                    checked={confirmed}
                    onChange={(e) => setConfirmed(e.target.checked)}
                    style={{ width: 17, height: 17, accentColor: 'var(--buy)' }}
                  />
                  <span style={{ color: 'var(--text-2)' }}>
                    I confirm I received {symbol}
                    {formatPrice(fiat)} from the buyer off-platform.
                  </span>
                </label>

                <button className="btn btn-buy btn-lg btn-block" disabled={!confirmed || busy} onClick={release}>
                  {busy ? 'Releasing…' : `Release ${formatCrypto(amount)} ${CRYPTO}`}
                </button>
              </>
            )}

            {(trade.status === 'completed') && (
              <>
                <div className="ap-label">Done</div>
                <div className="ap-head" style={{ color: 'var(--buy)' }}>
                  <IconCheck size={18} style={{ display: 'inline' }} /> Trade completed
                </div>
                <p className="ap-sub">
                  {isBuyer
                    ? `${formatCrypto(amount)} ${CRYPTO} has been released to your wallet.`
                    : `Escrow released. ${formatCrypto(amount)} ${CRYPTO} is now with the buyer.`}
                </p>
                <div className="notice notice-ok">
                  <IconShieldCheck size={16} style={{ marginTop: 2 }} />
                  <span>Open your wallet from the top bar to see your balance.</span>
                </div>
                <Link to="/marketplace" className="btn btn-soft btn-block" style={{ marginTop: 14 }}>
                  Browse more ads
                </Link>
              </>
            )}

            {role === 'other' && (
              <>
                <div className="ap-label">Read-only</div>
                <div className="ap-head">Not your trade</div>
                <p className="ap-sub">
                  You're not a participant on this order, so no actions are available.
                </p>
              </>
            )}
          </div>
        </aside>
      </div>
    </div>
  );
}

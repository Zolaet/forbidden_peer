import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/axios';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../components/Toast';
import Field from '../components/Field';
import { CRYPTO, NETWORK_LABEL } from '../lib/constants';
import { apiError, formatCrypto } from '../lib/format';
import { IconShieldCheck, IconArrowRight } from '../components/icons';

const ADDR_RE = /^0x[a-fA-F0-9]{40}$/;

/** Withdraw page — send USDT (BEP-20) to any external address. */
export default function Withdraw() {
  const { refresh } = useAuth();
  const toast = useToast();

  const [wallet, setWallet] = useState(null);
  const [cfg, setCfg] = useState({ fee: 0, min: 1, network: 'testnet', explorer_tx: '' });
  const [loading, setLoading] = useState(true);

  const [toAddress, setToAddress] = useState('');
  const [amount, setAmount] = useState('');
  const [errors, setErrors] = useState({});
  const [serverError, setServerError] = useState('');
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(null); // {tx_hash, explorer_tx, amount}

  useEffect(() => {
    api
      .get('/wallet')
      .then(({ data }) => {
        setWallet(data.wallet);
        setCfg((c) => ({ ...c, ...(data.withdrawal ?? {}) }));
      })
      .catch((e) => setServerError(apiError(e, "Couldn't load your wallet.")))
      .finally(() => setLoading(false));
  }, []);

  const available = Number(wallet?.available_balance ?? 0);
  const numAmount = Number(amount);
  const fee = Number(cfg?.fee ?? 0);
  const net = numAmount > 0 && fee >= 0 ? Math.max(0, numAmount - fee) : 0;
  const min = Number(cfg?.min ?? 1);

  const validate = () => {
    const e = {};
    if (!ADDR_RE.test(toAddress.trim())) {
      e.toAddress = 'Enter a valid BSC address: 0x followed by 40 hex characters.';
    }
    if (!numAmount || numAmount <= 0) e.amount = 'Enter an amount.';
    else if (numAmount < min) e.amount = `Minimum withdrawal is ${formatCrypto(min)} ${CRYPTO}.`;
    else if (numAmount > available) e.amount = `Your available balance is ${formatCrypto(available)} ${CRYPTO}.`;
    else if (net <= 0) e.amount = 'Amount must be above the withdrawal fee.';
    return e;
  };

  const submit = async (ev) => {
    ev.preventDefault();
    setServerError('');
    setSent(null);
    const e = validate();
    setErrors(e);
    if (Object.keys(e).length) return;

    setBusy(true);
    try {
      const res = await api.post('/wallet/withdrawals', {
        to_address: toAddress.trim(),
        amount: numAmount,
      });
      // Refresh profile so the header wallet chip reflects the debit.
      refresh().catch(() => {});

      if (res.status === 201 && res.data?.withdrawal?.status === 'sent') {
        setSent(res.data.withdrawal);
        toast.ok('Withdrawal sent', 'Your USDT is on its way to the BSC network.');
      } else {
        setServerError(res.data?.error ?? 'Withdrawal was not broadcast.');
      }
    } catch (err) {
      setServerError(apiError(err, 'Could not send this withdrawal.'));
    } finally {
      setBusy(false);
    }
  };

  const reset = () => {
    setToAddress('');
    setAmount('');
    setErrors({});
    setSent(null);
    setServerError('');
  };

  return (
    <div className="container page" style={{ maxWidth: 720 }}>
      <div className="page-head">
        <div>
          <div className="eyebrow">Wallet</div>
          <h1 className="page-title">Withdraw {CRYPTO}</h1>
          <p className="page-sub">
            Send USDT over {NETWORK_LABEL} to any wallet address you control.
          </p>
        </div>
        <div className="row" style={{ gap: 10 }}>
          <Link to="/wallet/deposit" className="btn btn-ghost btn-sm">Deposit {CRYPTO}</Link>
          <Link to="/marketplace" className="btn btn-soft btn-sm">Marketplace</Link>
        </div>
      </div>

      {loading ? (
        <div className="panel"><p className="small faint">Loading…</p></div>
      ) : sent ? (
        <div className="panel">
          <div className="row-between" style={{ marginBottom: 10 }}>
            <span className="badge badge-soft">Sent ✓</span>
            <span className="tiny faint">{cfg.network}</span>
          </div>
          <div className="state-title" style={{ fontSize: 18 }}>
            {formatCrypto(sent.amount)} {CRYPTO} sent
          </div>
          <p className="small" style={{ color: 'var(--text-2)' }}>
            The network is processing your transfer. It usually confirms within a minute or two.
          </p>
          {sent.explorer_tx && (
            <a href={sent.explorer_tx} target="_blank" rel="noreferrer" className="btn btn-soft btn-sm" style={{ marginTop: 8 }}>
              View transaction <IconArrowRight size={14} />
            </a>
          )}
          <button className="btn btn-primary" onClick={reset} style={{ marginLeft: 10 }}>
            Make another withdrawal
          </button>
        </div>
      ) : (
        <form className="panel" onSubmit={submit} noValidate>
          <div className="tc-title" style={{ marginBottom: 16 }}>
            Send to an external address
            <small>Funds leave your balance immediately and cannot be reversed.</small>
          </div>

          <div className="row-between" style={{ marginBottom: 16 }}>
            <span className="small faint">Available balance</span>
            <span className="small tnum" style={{ fontWeight: 700 }}>
              {formatCrypto(available)} {CRYPTO}
            </span>
          </div>

          {serverError && (
            <div className="auth-banner" role="alert" style={{ marginBottom: 14 }}>
              <span>⚠</span> {serverError}
            </div>
          )}

          <Field label="Destination address (BEP-20)" error={errors.toAddress} required>
            <input
              className="input mono-num"
              placeholder="0x…"
              spellCheck="false"
              value={toAddress}
              onChange={(e) => setToAddress(e.target.value)}
              style={{ fontFamily: 'var(--font-mono, monospace)' }}
            />
            <span className="tiny faint" style={{ marginTop: 4 }}>
              Paste the USDT (BEP-20) address of the wallet you want to receive the funds.
            </span>
          </Field>

          <Field
            label={`Amount (${CRYPTO})`}
            hint={<span className="tiny faint">Minimum {formatCrypto(min)} {CRYPTO}{fee > 0 ? ` · fee ${fee}` : ''}</span>}
            error={errors.amount}
            required
          >
            <div className="input-affix">
              <input
                className="input"
                inputMode="decimal"
                placeholder="0.00"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
              />
              <span className="affix">{CRYPTO}</span>
            </div>
          </Field>

          {fee > 0 && numAmount > 0 && (
            <div className="row-between" style={{ marginBottom: 14 }}>
              <span className="small faint">Network fee</span>
              <span className="small">{formatCrypto(fee)} {CRYPTO}</span>
            </div>
          )}

          <div className="notice notice-info" style={{ marginBottom: 14 }}>
            <IconShieldCheck size={16} style={{ marginTop: 2 }} />
            <span>
              Sent instantly from our treasury on the BSC network. Double-check the address — a wrong
              BEP-20 address means lost funds.
            </span>
          </div>

          <button type="submit" className="btn btn-sell btn-lg btn-block" disabled={busy}>
            {busy ? 'Broadcasting…' : `Withdraw ${CRYPTO}`}
          </button>
        </form>
      )}
    </div>
  );
}

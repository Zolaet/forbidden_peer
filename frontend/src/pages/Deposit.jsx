import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/axios';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../components/Toast';
import { CRYPTO, NETWORK, NETWORK_LABEL } from '../lib/constants';
import { apiError, formatCrypto, formatDateTime } from '../lib/format';

const CONFIRMED = 'confirmed';

function networkBadge(key) {
  return key === 'mainnet' ? 'Mainnet' : key === 'testnet' ? 'Testnet' : (key ?? '');
}

/** Deposit page — your unique USDT (BEP-20) deposit address + history. */
export default function Deposit() {
  const { refresh } = useAuth();
  const toast = useToast();

  const [wallet, setWallet] = useState(null);
  const [deposit, setDeposit] = useState(null);
  const [depositError, setDepositError] = useState('');
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');
  const [copied, setCopied] = useState(false);
  const timer = useRef(null);

  const load = useCallback(async () => {
    const { data } = await api.get('/wallet');
    setWallet(data.wallet);
    setDeposit(data.deposit);
    setDepositError(data.deposit_error ?? '');
    return data;
  }, []);

  // Initial load, then poll while the page is open so a new on-chain deposit
  // (credited by `php artisan bsc:scan-deposits`) shows up automatically.
  useEffect(() => {
    let seen = 0;
    const tick = async () => {
      try {
        await load();
        const { data } = await api.get('/wallet/deposits');
        setRows(data.deposits ?? []);
        const confirmedNow = (data.deposits ?? []).filter((d) => d.status === CONFIRMED).length;
        if (confirmedNow > seen) refresh().catch(() => {}); // keep header chip in sync
        seen = confirmedNow;
        setErr('');
      } catch (e) {
        setErr(apiError(e, "Couldn't load your wallet. Is the API running?"));
      } finally {
        setLoading(false);
      }
    };

    tick();
    timer.current = setInterval(tick, 5000);
    return () => clearInterval(timer.current);
  }, [load, refresh]);

  const copyAddress = async () => {
    if (!deposit?.address) return;
    try {
      await navigator.clipboard.writeText(deposit.address);
      setCopied(true);
      toast.ok('Address copied', 'Paste it into your sending wallet.');
      setTimeout(() => setCopied(false), 2000);
    } catch {
      toast.err('Copy failed', 'Select the address and copy it manually.');
    }
  };

  const available = Number(wallet?.available_balance ?? 0);
  const short = deposit?.address
    ? `${deposit.address.slice(0, 10)}…${deposit.address.slice(-8)}`
    : '';

  return (
    <div className="container page">
      <div className="page-head">
        <div>
          <div className="eyebrow">Wallet</div>
          <h1 className="page-title">Deposit {CRYPTO}</h1>
          <p className="page-sub">Send USDT over {NETWORK_LABEL} to your personal address below.</p>
        </div>
        <div className="row" style={{ gap: 10 }}>
          <Link to="/wallet/withdraw" className="btn btn-ghost btn-sm">Withdraw {CRYPTO}</Link>
          <Link to="/marketplace" className="btn btn-soft btn-sm">Back to market</Link>
        </div>
      </div>

      {err && (
        <div className="auth-banner" role="alert" style={{ marginBottom: 16 }}>
          <span>⚠</span> {err}
        </div>
      )}

      <div className="row" style={{ gap: 16, alignItems: 'flex-start', flexWrap: 'wrap' }}>
        {/* Left: address panel */}
        <div className="panel" style={{ flex: '1 1 380px', maxWidth: 620 }}>
          <div className="row-between" style={{ marginBottom: 8 }}>
            <span className="badge badge-soft">
              {NETWORK} · {networkBadge(deposit?.network)}
            </span>
            <span className="tiny faint">
              Balance: <b>{formatCrypto(available)}</b> {CRYPTO}
            </span>
          </div>

          {depositError ? (
            <div className="notice notice-warn" style={{ marginTop: 6 }}>
              <span>
                Deposits aren't ready yet — {depositError}
              </span>
            </div>
          ) : deposit?.address ? (
            <>
              <div className="tiny faint" style={{ marginBottom: 6 }}>
                Your personal {NETWORK_LABEL} address
              </div>
              <button
                type="button"
                onClick={copyAddress}
                className="input mono-num"
                title="Click to copy"
                style={{
                  width: '100%',
                  textAlign: 'left',
                  cursor: 'pointer',
                  overflowX: 'auto',
                  whiteSpace: 'nowrap',
                  fontFamily: 'var(--font-mono, monospace)',
                }}
              >
                {deposit.address}
              </button>
              <div className="tiny faint" style={{ marginTop: 6 }}>{short}</div>

              <div className="row" style={{ gap: 10, marginTop: 12, flexWrap: 'wrap' }}>
                <button className="btn btn-primary" onClick={copyAddress} style={{ flex: 1 }}>
                  {copied ? 'Copied ✓' : 'Copy address'}
                </button>
                <a
                  href={`https://${networkBadge(deposit?.network).toLowerCase() === 'mainnet' ? '' : 'testnet.'}bscscan.com/address/${deposit.address}`}
                  target="_blank"
                  rel="noreferrer"
                  className="btn btn-soft"
                >
                  View on explorer
                </a>
              </div>
            </>
          ) : loading ? (
            <div className="tiny faint">Loading your deposit address…</div>
          ) : (
            <div className="state-block" style={{ minHeight: 0, padding: 20 }}>
              <div className="state-title" style={{ fontSize: 15 }}>No deposit address yet</div>
              <p className="small">Pull down to refresh or contact support.</p>
            </div>
          )}

          <div className="notice notice-warn" style={{ marginTop: 16 }}>
            <span>
              <b>Only send {CRYPTO} ({NETWORK_LABEL}).</b> Sending any other token or network to this
              address can result in permanent loss. Deposits are credited after{' '}
              <b>{deposit?.min_confirmations ?? 15} confirmations</b>.
            </span>
          </div>
        </div>

        {/* Right: deposit history */}
        <div className="panel" style={{ flex: '1 1 340px', minWidth: 300 }}>
          <div className="tc-title" style={{ marginBottom: 10 }}>
            Recent deposits
            <small>Auto-refreshes every few seconds</small>
          </div>

          {rows.length === 0 ? (
            <p className="small faint" style={{ padding: '8px 0' }}>
              No deposits yet. Send USDT to your address above and it will appear here once the chain
              confirms it.
            </p>
          ) : (
            <div className="stack" style={{ gap: 8 }}>
              {rows.map((d) => (
                <div
                  key={d.id}
                  className="row-between"
                  style={{
                    padding: '10px 12px',
                    borderRadius: 'var(--r-sm, 10px)',
                    background: 'var(--bg-2, #0f1524)',
                    border: '1px solid var(--line-faint, rgba(255,255,255,0.06))',
                  }}
                >
                  <div>
                    <div className="small" style={{ fontWeight: 700 }}>
                      +{formatCrypto(d.amount)} {CRYPTO}
                    </div>
                    <div className="tiny faint">{formatDateTime(d.created_at)}</div>
                    <a
                      href={d.explorer_tx}
                      target="_blank"
                      rel="noreferrer"
                      className="tiny"
                      style={{ color: 'var(--brand-2, #7aa0ff)', wordBreak: 'break-all' }}
                    >
                      {d.tx_hash?.slice(0, 18)}…
                    </a>
                  </div>
                  <span
                    className="badge"
                    style={{
                      color: d.status === CONFIRMED ? 'var(--buy, #19c37d)' : 'var(--text-3)',
                      background: d.status === CONFIRMED
                        ? 'rgba(25,195,125,0.12)'
                        : 'rgba(255,255,255,0.05)',
                    }}
                  >
                    {d.status === CONFIRMED ? 'Credited' : `${d.confirmations ?? 0} confs`}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

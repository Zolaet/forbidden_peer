import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { localTradesForUser, saveLocalTrade } from '../lib/localTrades';
import api from '../api/axios';
import { CRYPTO, fiatSymbol } from '../lib/constants';
import { formatCrypto, formatDateTime, formatPrice, timeFromNow } from '../lib/format';
import StatusPill from '../components/StatusPill';
import { IconArrowRight, IconClock } from '../components/icons';
import '../styles/trades.css';

const byNewest = (a, b) => new Date(b.opened_at) - new Date(a.opened_at);

export default function Trades() {
  const { user } = useAuth();
  const meId = user?.id;

  // The local ledger renders instantly; the API list is the truth and replaces
  // it (that is also what makes trades from other devices show up here).
  const [trades, setTrades] = useState(() => localTradesForUser(meId).sort(byNewest));
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    let alive = true;

    api
      .get('/trades')
      .then(({ data }) => {
        if (!alive || !Array.isArray(data.trades)) return;
        // Mirror into localStorage so the detail page stays warm for trades
        // this browser had never seen.
        const snaps = data.trades.map((t) => saveLocalTrade(t, meId, user?.name ?? 'You'));
        setTrades(snaps);
      })
      .catch(() => {
        /* offline / expired token — the local ledger is already showing */
      })
      .finally(() => {
        if (alive) setLoaded(true);
      });

    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [meId]);

  return (
    <div className="container page">
      <div className="page-head">
        <div>
          <div className="eyebrow">Order ledger</div>
          <h1 className="page-title">My trades</h1>
          <p className="page-sub">All your orders, on both sides — from any device.</p>
        </div>
      </div>

      {trades.length === 0 && !loaded ? (
        <div className="panel state-block">
          <span className="dot" style={{ width: 12, height: 12, background: 'var(--gold)' }} />
          <div className="state-title">Loading your orders…</div>
        </div>
      ) : trades.length === 0 ? (
        <div className="panel state-block">
          <span className="avatar" style={{ width: 52, height: 52, fontSize: 20, background: 'var(--brand-soft)', color: 'var(--brand-2)' }}>
            ⟳
          </span>
          <div className="state-title">No trades yet</div>
          <p className="small">Buy an active ad in the marketplace to open your first escrow trade.</p>
          <Link to="/marketplace" className="btn btn-primary btn-sm" style={{ marginTop: 10 }}>
            Go to marketplace <IconArrowRight size={14} />
          </Link>
        </div>
      ) : (
        <div className="stack">
          {trades.map((t) => {
            const meBuyer = String(t.buyer_id) === String(meId);
            const symbol = fiatSymbol(t.offer?.fiat_currency);
            const live = t.status === 'pending' || t.status === 'paid';

            return (
              <Link to={`/trades/${t.trade_ref}`} className="trade-item" key={t.trade_ref}>
                <div className="ti-main">
                  <div className="row" style={{ gap: 8 }}>
                    <span className={`ti-dir ${meBuyer ? 'buy' : 'sell'}`}>
                      {meBuyer ? `Buying` : `Selling`} {CRYPTO}
                    </span>
                    <StatusPill status={t.status} />
                  </div>
                  <div className="ti-sub">
                    {t.trade_ref} · {formatDateTime(t.opened_at)}
                    {live && t.expires_at && (
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, color: 'var(--gold)' }}>
                        <IconClock size={11} /> {timeFromNow(t.expires_at)}
                      </span>
                    )}
                  </div>
                </div>

                <div className="ti-amt">
                  <div className="ta-big tnum">
                    {formatCrypto(t.crypto_amount)} {CRYPTO}
                  </div>
                  <div className="ta-sub">
                    @ {symbol}
                    {formatPrice(t.unit_price)}
                  </div>
                </div>

                <div className="ti-amt">
                  <div className="ta-big tnum" style={{ fontWeight: 700 }}>
                    {symbol}
                    {formatPrice(t.fiat_amount)}
                  </div>
                  <div className="ta-sub">with {meBuyer ? t.seller_name || 'seller' : t.buyer_name || 'buyer'}</div>
                </div>

                <div className="ti-action">
                  <span className="icon-btn" style={{ border: '1px solid var(--line)' }}>
                    <IconArrowRight size={16} />
                  </span>
                </div>
              </Link>
            );
          })}
        </div>
      )}
    </div>
  );
}

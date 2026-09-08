import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { localTradesForUser } from '../lib/localTrades';
import { CRYPTO, fiatSymbol } from '../lib/constants';
import { formatCrypto, formatDateTime, formatPrice, timeFromNow } from '../lib/format';
import StatusPill from '../components/StatusPill';
import { IconArrowRight, IconClock } from '../components/icons';
import '../styles/trades.css';

export default function Trades() {
  const { user } = useAuth();
  const meId = user?.id;
  const trades = localTradesForUser(meId).sort(
    (a, b) => new Date(b.opened_at) - new Date(a.opened_at)
  );

  return (
    <div className="container page">
      <div className="page-head">
        <div>
          <div className="eyebrow">Order ledger</div>
          <h1 className="page-title">My trades</h1>
          <p className="page-sub">Trades opened in this browser session, on both sides.</p>
        </div>
      </div>

      {trades.length === 0 ? (
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

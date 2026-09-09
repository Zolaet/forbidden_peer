import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api/axios';
import OfferCard from '../components/OfferCard';
import TradeModal from '../components/TradeModal';
import { useAuth } from '../context/AuthContext';
import { CRYPTO } from '../lib/constants';
import { IconTrend, IconRefresh, IconPlus, IconArrowRight, IconScale } from '../components/icons';
import '../styles/market.css';

function Skeletons() {
  return (
    <div className="mkt-list" aria-hidden>
      {Array.from({ length: 5 }).map((_, i) => (
        <div key={i} className="sk" style={{ height: 76 }} />
      ))}
    </div>
  );
}

export default function Marketplace() {
  const { user } = useAuth();
  const navigate = useNavigate();

  // mode = viewer intent. A buyer views SELLER ads; a seller views BUYER ads.
  const [mode, setMode] = useState('buy');
  const isBuy = mode === 'buy';
  const fetchType = isBuy ? 'sell' : 'buy';

  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState({});
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [err, setErr] = useState('');
  const [buying, setBuying] = useState(null);

  const fetchOffers = useCallback(
    async (page = 1, append = false) => {
      if (!append) setLoading(true);
      setErr('');
      try {
        const { data } = await api.get('/offers', {
          params: {
            type: fetchType,
            page,
          },
        });
        const pageOffers = data.offers ?? { data: [] };
        const list = pageOffers.data ?? [];
        setRows((prev) => (append ? [...prev, ...list] : list));
        setMeta(pageOffers);
      } catch {
        setErr("Couldn't reach the marketplace. Is the Laravel API running?");
        if (!append) setRows([]);
      } finally {
        setLoading(false);
        setLoadingMore(false);
      }
    },
    [fetchType]
  );

  useEffect(() => {
    setRows([]);
    fetchOffers();
  }, [fetchOffers]);

  const switchMode = (m) => {
    if (m !== mode) {
      setBuying(null);
      setMode(m);
    }
  };

  return (
    <div className="container page">
      <div className="mkt-head">
        <div className="mkt-tabs" role="tablist">
          <button
            className="mkt-tab buy-tab"
            aria-pressed={isBuy}
            onClick={() => switchMode('buy')}
          >
            <IconTrend size={18} style={{ transform: 'rotate(-90deg)' }} /> Buy {CRYPTO}
          </button>
          <button
            className="mkt-tab sell-tab"
            aria-pressed={!isBuy}
            onClick={() => switchMode('sell')}
          >
            <IconTrend size={18} style={{ transform: 'rotate(90deg)' }} /> Sell {CRYPTO}
          </button>
        </div>

        <div className="mkt-tool">
          <Link to="/post" className="btn btn-ghost btn-sm">
            <IconPlus size={15} /> Post an offer
          </Link>
          <button
            className="icon-btn"
            aria-label="Refresh"
            title="Refresh ads"
            onClick={() => fetchOffers()}
            disabled={loading}
          >
            <IconRefresh size={16} />
          </button>
        </div>
      </div>

      <div className="quick-fiats" style={{ marginBottom: 18, gap: 8 }}>
        <span className="chip" style={{ cursor: 'default' }}>ETB — Ethiopian Birr</span>
        <span className="tiny faint" style={{ alignSelf: 'center' }}>
          USDT ⇄ Birr only · escrow protected
        </span>
      </div>

      {/* How-to-sell strip (only on the Sell tab) */}
      {!isBuy && (
        <div
          className="row-between"
          style={{
            gap: 14,
            flexWrap: 'wrap',
            padding: '14px 16px',
            borderRadius: 'var(--r-md)',
            background: 'linear-gradient(90deg, rgba(251,81,104,0.10), rgba(251,81,104,0.02))',
            border: '1px solid rgba(251,81,104,0.25)',
            marginBottom: 16,
          }}
        >
          <span className="row" style={{ gap: 10 }}>
            <span className="avatar" style={{ background: 'var(--sell-soft)', color: 'var(--sell)' }}>
              <IconScale size={18} />
            </span>
            <span className="small" style={{ color: 'var(--text-2)', maxWidth: 520 }}>
              These buyers are looking for {CRYPTO}. Advertise your own sell rate and they can buy
              straight from you — funds lock in escrow until you release.
            </span>
          </span>
          <Link to="/post?type=sell" className="btn btn-sell btn-sm">
            <IconPlus size={15} /> Post sell ad
          </Link>
        </div>
      )}

      {!loading && rows.length > 0 && (
        <div className="ad-labels">
          <span>Advertiser</span>
          <span>Unit price</span>
          <span>Available</span>
          <span>Limit</span>
          <span style={{ textAlign: 'right', paddingRight: 2 }}>
            {isBuy ? `${meta.total ?? rows.length} sellers` : `${meta.total ?? rows.length} buyers`}
          </span>
        </div>
      )}

      {loading ? (
        <Skeletons />
      ) : err ? (
        <div className="panel state-block">
          <span className="dot" style={{ background: 'var(--sell)', width: 12, height: 12 }} />
          <div className="state-title">{err}</div>
          <p className="small">
            Start Laravel with{' '}
            <code className="mono tiny" style={{ background: 'var(--bg-2)', padding: '2px 7px' }}>
              php artisan serve
            </code>{' '}
            on port 8000, then retry.
          </p>
          <button className="btn btn-soft btn-sm" onClick={() => fetchOffers()} style={{ marginTop: 8 }}>
            <IconRefresh size={14} /> Retry
          </button>
        </div>
      ) : rows.length === 0 ? (
        <div className="panel state-block">
          <span
            className="avatar"
            style={{
              width: 52,
              height: 52,
              fontSize: 22,
              background: isBuy ? 'var(--buy-soft)' : 'var(--sell-soft)',
              color: isBuy ? 'var(--buy)' : 'var(--sell)',
            }}
          >
            {isBuy ? 'B' : 'S'}
          </span>
          <div className="state-title">
            No active {isBuy ? 'sell' : 'buy'} ads right now
          </div>
          <p className="small">Be the first to list one and start trading.</p>
          <Link
            to={`/post?type=${isBuy ? 'sell' : 'buy'}`}
            className="btn btn-primary btn-sm"
            style={{ marginTop: 10 }}
          >
            Post a {isBuy ? 'sell' : 'buy'} offer <IconArrowRight size={14} />
          </Link>
        </div>
      ) : (
        <>
          <div className="mkt-list">
            {rows.map((offer) => {
              const own = user && String(offer.user_id) === String(user.id);
              const soldOut = Number(offer.remaining_amount ?? 0) <= 0;

              if (isBuy) {
                return (
                  <OfferCard
                    key={offer.id}
                    offer={offer}
                    ctaLabel={`Buy ${CRYPTO}`}
                    ctaTone="buy"
                    own={own}
                    soldOut={soldOut}
                    onCta={own ? null : (o) => setBuying(o)}
                  />
                );
              }
              return (
                <OfferCard
                  key={offer.id}
                  offer={offer}
                  ctaLabel="Sell to this ad"
                  ctaTone="sell"
                  own={own}
                  soldOut={soldOut}
                  onCta={
                    own
                      ? null
                      : (o) =>
                          navigate(
                            `/post?type=sell&fiat=${o.fiat_currency}&price=${o.price}`
                          )
                  }
                />
              );
            })}
          </div>

          {meta.current_page < meta.last_page && (
            <div className="center" style={{ marginTop: 22 }}>
              <button className="btn btn-soft" onClick={() => { setLoadingMore(true); fetchOffers(meta.current_page + 1, true); }} disabled={loadingMore}>
                {loadingMore ? 'Loading…' : 'Load more ads'}
              </button>
            </div>
          )}
        </>
      )}

      <TradeModal offer={buying} mode="buy" onClose={() => setBuying(null)} />
    </div>
  );
}

import { CRYPTO } from '../lib/constants';
import { fiatSymbol } from '../lib/constants';
import { formatCrypto, formatPrice, initials } from '../lib/format';
import { IconClock } from './icons';

/**
 * A single marketplace ad row.
 * `ctaLabel`/`ctaTone` drive the action button ("Buy USDT" on a buyer page,
 * "Sell to this ad" on the seller page, etc.).
 */
export default function OfferCard({
  offer,
  ctaLabel,
  ctaTone = 'buy',
  own = false,
  soldOut = false,
  pending = false,
  onCta,
}) {
  const symbol = fiatSymbol(offer.fiat_currency);
  const btnClass =
    ctaTone === 'buy' ? 'btn-buy' : ctaTone === 'sell' ? 'btn-sell' : 'btn-ghost';
  const priceClass = ctaTone === 'buy' ? 'buy' : 'sell';

  return (
    <div className="ad" aria-label={`${offer.type} offer by ${offer.user?.name}`}>
      <div className="ad-adv">
        <span className="avatar">{initials(offer.user?.name)}</span>
        <span style={{ minWidth: 0 }}>
          <span className="aa-name">{offer.user?.name}</span>
          <span className="aa-sub" style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
            <IconClock size={11} style={{ opacity: 0.7 }} />
            {offer.payment_window_minutes}-min payment window
          </span>
        </span>
      </div>

      <div className="ad-price">
        <div className="p-top">Unit price · {offer.fiat_currency}</div>
        <div className={`p-num ${priceClass}`}>
          {symbol}
          {formatPrice(offer.price)}
        </div>
      </div>

      <div className="ad-cell">
        <div className="c-label">Available</div>
        <div className="c-val tnum">
          {soldOut ? '—' : formatCrypto(offer.remaining_amount ?? 0)}
          <span className="faint"> {CRYPTO}</span>
        </div>
      </div>

      <div className="ad-cell">
        <div className="c-label">Limit</div>
        <div className="c-val faint tnum">
          {symbol}
          {formatPrice(offer.min_limit)} – {symbol}
          {formatPrice(offer.max_limit)}
        </div>
      </div>

      <div className="ad-actions">
        {own ? (
          <span className="ad-own">You posted this</span>
        ) : (
          <>
            <button
              className={`btn ${btnClass}`}
              disabled={soldOut || pending || !onCta}
              onClick={() => onCta?.(offer)}
            >
              {pending ? '…' : ctaLabel}
            </button>
            {soldOut && <span className="ad-own">Sold out</span>}
          </>
        )}
      </div>
    </div>
  );
}

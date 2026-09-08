/**
 * Client-side "trade ledger".
 *
 * The backend exposes no GET endpoint for a user's trades, so after a trade is
 * opened we keep a lightweight mirror in localStorage. This lets the buyer
 * revisit the trade to mark it paid, and lets the seller (same browser, second
 * account) release escrow — mirroring the real order lifecycle.
 *
 * This is a UI convenience only; the backend remains the source of truth.
 */

const KEY = 'px_local_trades_v1';

function read() {
  try {
    const raw = localStorage.getItem(KEY);
    const list = raw ? JSON.parse(raw) : [];
    return Array.isArray(list) ? list : [];
  } catch {
    return [];
  }
}

function write(list) {
  try {
    localStorage.setItem(KEY, JSON.stringify(list.slice(-60)));
  } catch {
    /* storage full / private mode — non-fatal */
  }
}

/** Build a stable snapshot from an initiate response trade. */
export function snapshotTrade(trade, meId, meName = 'You') {
  return {
    trade_ref: trade.trade_ref,
    status: trade.status,
    crypto_amount: trade.crypto_amount,
    fiat_amount: trade.fiat_amount,
    unit_price: trade.unit_price,
    expires_at: trade.expires_at,
    offer: {
      id: trade.offer?.id ?? trade.offer_id,
      fiat_currency: trade.offer?.fiat_currency,
      price: trade.offer?.price ?? trade.unit_price,
    },
    buyer_id: trade.buyer_id ?? meId,
    seller_id: trade.seller_id,
    buyer_name: trade.buyer?.name ?? meName,
    seller_name: trade.seller?.name ?? (trade.seller_id === meId ? 'You' : ''),
    payment: trade.payment_method
      ? {
          type: trade.payment_method.type,
          account_name: trade.payment_method.account_name,
          bank_or_provider_name: trade.payment_method.bank_or_provider_name,
        }
      : null,
    opened_at: new Date().toISOString(),
  };
}

export function saveLocalTrade(trade, meId, meName) {
  const list = read();
  const snap = snapshotTrade(trade, meId, meName);
  const idx = list.findIndex((t) => t.trade_ref === snap.trade_ref);
  if (idx >= 0) list[idx] = { ...list[idx], ...snap };
  else list.unshift(snap);
  write(list);
  return snap;
}

export function patchLocalTrade(ref, patch) {
  const list = read();
  const idx = list.findIndex((t) => t.trade_ref === ref);
  if (idx >= 0) {
    list[idx] = { ...list[idx], ...patch, updated_at: new Date().toISOString() };
    write(list);
    return list[idx];
  }
  return null;
}

export function getLocalTrade(ref) {
  return read().find((t) => t.trade_ref === ref) ?? null;
}

export function getLocalTrades() {
  return read();
}

/** Trades where this user participates as buyer or seller. */
export function localTradesForUser(userId) {
  return read().filter((t) => String(t.buyer_id) === String(userId) || String(t.seller_id) === String(userId));
}

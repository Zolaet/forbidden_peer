import { useEffect, useRef, useState } from 'react';
import api from '../api/axios';

/**
 * Keep one trade in sync with the backend.
 *
 * Fetches `GET /trades/{ref}` immediately (so a trade exists on a device that
 * never saw it created), then re-fetches every 5s while the trade is still in
 * an open state. The other party's actions — mark paid, release, cancel, a
 * dispute, the trades:expire sweep — land on the page without a refresh, and
 * polling stops once there is nothing left to wait for.
 */
const OPEN_STATUSES = ['pending', 'paid', 'disputed'];
const POLL_MS = 5000;

export default function useTradeSync(tradeRef, { onTrade } = {}) {
  const [loaded, setLoaded] = useState(false);
  const [notFound, setNotFound] = useState(false);

  // The callback changes identity every render; the effect must not restart
  // because of it, so it is read through a ref.
  const onTradeRef = useRef(onTrade);
  onTradeRef.current = onTrade;
  const statusRef = useRef(null);

  useEffect(() => {
    let cancelled = false;

    const fetchOnce = async () => {
      try {
        const { data } = await api.get(`/trades/${tradeRef}`);
        if (cancelled) return;
        statusRef.current = data.trade?.status ?? null;
        setNotFound(false);
        setLoaded(true);
        if (data.trade) onTradeRef.current?.(data.trade);
      } catch (err) {
        if (cancelled) return;
        // A 404 is definitive; anything else is transient and the next tick
        // (or the mount fetch on a retry) will try again.
        if (err.response?.status === 404) {
          setNotFound(true);
          setLoaded(true);
        }
      }
    };

    const tick = () => {
      if (document.hidden) return;
      const status = statusRef.current;
      if (status && !OPEN_STATUSES.includes(status)) return;
      fetchOnce();
    };

    setLoaded(false);
    setNotFound(false);
    statusRef.current = null;
    fetchOnce();

    const id = setInterval(tick, POLL_MS);
    return () => {
      cancelled = true;
      clearInterval(id);
    };
  }, [tradeRef]);

  return { loaded, notFound };
}

import { createContext, useCallback, useContext, useRef, useState } from 'react';

const ToastCtx = createContext(null);
const ICONS = { ok: '✓', err: '✕', info: 'i' };

let uid = 0;

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);
  const timers = useRef(new Map());

  const dismiss = useCallback((id) => {
    timers.current.delete(id);
    setToasts((prev) => prev.map((t) => (t.id === id ? { ...t, leaving: true } : t)));
    window.setTimeout(
      () => setToasts((prev) => prev.filter((t) => t.id !== id)),
      200
    );
  }, []);

  const push = useCallback(
    (type, title, msg, duration = 4200) => {
      const id = ++uid;
      setToasts((prev) => [...prev.slice(-3), { id, type, title, msg }]);
      const timer = window.setTimeout(() => dismiss(id), duration);
      timers.current.set(id, timer);
    },
    [dismiss]
  );

  const toast = {
    ok: (title, msg) => push('ok', title, msg),
    err: (title, msg) => push('err', title, msg, 6200),
    info: (title, msg) => push('info', title, msg),
  };

  return (
    <ToastCtx.Provider value={toast}>
      {children}
      <div className="toast-wrap" role="status" aria-live="polite">
        {toasts.map((t) => (
          <div key={t.id} className={`toast ${t.leaving ? 'out' : ''}`}>
            <span className={`toast-ico toast-${t.type}`}>{ICONS[t.type]}</span>
            <div className="toast-body">
              <div className="toast-title">{t.title}</div>
              {t.msg && <div className="toast-msg">{t.msg}</div>}
            </div>
          </div>
        ))}
      </div>
    </ToastCtx.Provider>
  );
}

export const useToast = () => useContext(ToastCtx);

import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { BRAND, CRYPTO } from '../lib/constants';
import { formatCrypto } from '../lib/format';
import { initials } from '../lib/format';
import { CoinMark } from './Brand';
import Modal from './Modal';
import { useToast } from './Toast';
import {
  IconChevDown,
  IconLock,
  IconLogout,
  IconTrend,
  IconWallet,
  IconScale,
} from './icons';
import '../styles/layout.css';

function NavLinks({ onNavigate }) {
  return (
    <>
      <NavLink
        to="/marketplace"
        className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}
        onClick={onNavigate}
      >
        Marketplace
      </NavLink>
      <NavLink
        to="/trades"
        className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}
        onClick={onNavigate}
      >
        My Trades
      </NavLink>
    </>
  );
}

function WalletButton({ onClick }) {
  const { user } = useAuth();
  const wallet = user?.wallet;
  const total = Number(wallet?.available_balance ?? 0) + Number(wallet?.escrow_balance ?? 0);
  return (
    <button className="wallet-chip" onClick={onClick} aria-label="Open wallet">
      <CoinMark size={26} />
      <span className="wt-c">
        <span className="wt-label">Balance</span>
        <span className="wt-val tnum">
          {formatCrypto(total)} {CRYPTO}
        </span>
      </span>
    </button>
  );
}

function WalletModal({ open, onClose }) {
  const { user } = useAuth();
  const wallet = user?.wallet ?? {};
  const available = Number(wallet.available_balance ?? 0);
  const escrow = Number(wallet.escrow_balance ?? 0);
  const total = available + escrow;

  return (
    <Modal open={open} onClose={onClose} title="My USDT wallet">
      <div className="wallet-hero">
        <CoinMark size={46} />
        <div>
          <div className="w-total-label">Total balance</div>
          <div className="w-total tnum">
            {formatCrypto(total)} <span className="w-coin">{CRYPTO}</span>
          </div>
        </div>
      </div>
      <div className="wallet-rows">
        <div className="wallet-row">
          <span className="w-k">
            <IconLock size={16} /> Available
          </span>
          <span className="w-v avail tnum">
            {formatCrypto(available)} {CRYPTO}
          </span>
        </div>
        <div className="wallet-row">
          <span className="w-k">
            <IconScale size={16} /> In escrow
          </span>
          <span className="w-v escrow tnum">
            {formatCrypto(escrow)} {CRYPTO}
          </span>
        </div>
        <p className="tiny faint" style={{ padding: '10px 4px 0' }}>
          Escrowed {CRYPTO} is locked in active sell trades until you release payment. Available
          funds can be posted as sell offers.
        </p>
      </div>
    </Modal>
  );
}

export default function Layout() {
  const { user, logout, refresh } = useAuth();
  const navigate = useNavigate();
  const toast = useToast();
  const [menuOpen, setMenuOpen] = useState(false);
  const [walletOpen, setWalletOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const menuRef = useRef(null);

  // Fresh profile (wallet + payment methods) once the shell mounts.
  useEffect(() => {
    if (user) refresh().catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // close dropdown on outside click / route change
  useEffect(() => {
    if (!menuOpen) return;
    const onDown = (e) => {
      if (menuRef.current && !menuRef.current.contains(e.target)) setMenuOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, [menuOpen]);

  const handleLogout = async () => {
    setLoggingOut(true);
    try {
      await logout();
      toast.info('Signed out', 'See you next trade.');
      navigate('/login', { replace: true });
    } finally {
      setLoggingOut(false);
    }
  };

  const name = user?.name ?? 'Account';

  return (
    <div style={{ minHeight: '100vh', display: 'flex', flexDirection: 'column' }}>
      <header className="nav">
        <div className="container nav-inner">
          <NavLink to="/marketplace" className="nav-brand" style={{ display: 'inline-flex' }}>
            <CoinMark size={28} />
            <span>
              Peer<em>X</em>
            </span>
          </NavLink>

          <nav className="nav-links" aria-label="Primary">
            <NavLinks />
          </nav>

          <div className="nav-spacer" />

          <WalletButton onClick={() => setWalletOpen(true)} />

          <div className="user-menu" ref={menuRef}>
            <button
              className="user-trigger"
              onClick={() => setMenuOpen((v) => !v)}
              aria-expanded={menuOpen}
              aria-haspopup="menu"
            >
              <span className="avatar" style={{ width: 32, height: 32, fontSize: 12 }}>
                {initials(name)}
              </span>
              <span className="ut-name">{name}</span>
              <IconChevDown size={16} style={{ color: 'var(--text-3)' }} />
            </button>

            {menuOpen && (
              <div className="dropdown" role="menu">
                <div className="dropdown-head">
                  <div className="d-name">{name}</div>
                  <div className="d-mail">{user?.email}</div>
                </div>

                <button
                  className="dropdown-item"
                  role="menuitem"
                  onClick={() => {
                    setMenuOpen(false);
                    navigate('/marketplace');
                  }}
                >
                  <IconTrend size={16} /> Marketplace
                </button>
                <button
                  className="dropdown-item"
                  role="menuitem"
                  onClick={() => {
                    setMenuOpen(false);
                    navigate('/trades');
                  }}
                >
                  <IconTrend size={16} /> My Trades
                </button>
                <button
                  className="dropdown-item"
                  role="menuitem"
                  onClick={() => {
                    setMenuOpen(false);
                    navigate('/payment-methods');
                  }}
                >
                  <IconWallet size={16} /> Payment methods
                </button>
                <button
                  className="dropdown-item"
                  role="menuitem"
                  onClick={() => {
                    setMenuOpen(false);
                    setWalletOpen(true);
                  }}
                >
                  <IconScale size={16} /> My wallet
                </button>

                <div className="dropdown-sep" />
                <button className="dropdown-item danger" role="menuitem" disabled={loggingOut} onClick={handleLogout}>
                  <IconLogout size={16} /> {loggingOut ? 'Signing out…' : 'Sign out'}
                </button>
              </div>
            )}
          </div>
        </div>
      </header>

      <main style={{ flex: 1 }}>
        <Outlet />
      </main>

      <footer className="app-foot">
        <div className="container foot-inner">
          <span>
            © {new Date().getFullYear()} {BRAND}. Crypto P2P exchange demo.
          </span>
          <div className="foot-trust">
            <span>Escrow protected trades</span>
            <span>Fast payments</span>
            <span>24/7 marketplace</span>
          </div>
        </div>
      </footer>

      <WalletModal open={walletOpen} onClose={() => setWalletOpen(false)} />
    </div>
  );
}

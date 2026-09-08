import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { Wordmark } from './Brand';
import { IconShield, IconScale, IconClock } from './icons';
import '../styles/auth.css';

const POINTS = [
  { icon: IconShield, tone: '', text: 'Crypto locked in escrow until both sides release.' },
  { icon: IconScale, tone: 'green', text: 'Trade USDT directly with verified peers at your rate.' },
  { icon: IconClock, tone: 'gold', text: 'Bank, mobile money & PayPal — with proof of payment.' },
];

export default function AuthLayout({ children }) {
  const { user, loading } = useAuth();

  // Signed-in visitors are sent straight to the marketplace.
  if (!loading && user) return <Navigate to="/marketplace" replace />;

  return (
    <div className="auth-page">
      <div className="auth-grid">
        <div className="auth-left">
          <Wordmark size={34} fontSize={24} />
          <h1 className="lead">
            The peer-to-peer way to <span className="acc">buy &amp; sell USDT</span>
          </h1>
          <p className="sub">
            Set your own price, pay the way you like, and trade straight from your wallet —
            with every trade protected by a fair escrow flow.
          </p>
          <div className="auth-points">
            {POINTS.map(({ icon: I, tone, text }) => (
              <div className="auth-point" key={text}>
                <span className={`pt-ico ${tone}`}>
                  <I size={18} />
                </span>
                {text}
              </div>
            ))}
          </div>
        </div>

        <div className="auth-card">{children}</div>
      </div>
    </div>
  );
}

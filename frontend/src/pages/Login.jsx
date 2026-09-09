import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { apiError } from '../lib/format';
import AuthLayout from '../components/AuthLayout';
import Field from '../components/Field';
import '../styles/auth.css';

export default function Login() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const from = location.state?.from || '/marketplace';

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [show, setShow] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setBusy(true);
    try {
      await login(email.trim(), password);
      navigate(from, { replace: true });
    } catch (err) {
      setError(apiError(err, 'Invalid credentials.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthLayout>
      <h2 className="ac-title">Welcome back</h2>
      <p className="ac-sub">Sign in to continue trading on the marketplace.</p>

      {error && (
        <div className="auth-banner" role="alert">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
          {error}
        </div>
      )}

      <form onSubmit={submit} noValidate>
        <Field label="Email address" htmlFor="email" required>
          <input
            id="email"
            className="input"
            type="email"
            autoComplete="email"
            placeholder="you@example.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </Field>

        <Field label="Password" htmlFor="password" required>
          <div style={{ position: 'relative' }}>
            <input
              id="password"
              className="input"
              type={show ? 'text' : 'password'}
              autoComplete="current-password"
              placeholder="••••••••"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              style={{ paddingRight: 44 }}
              required
            />
            <button
              type="button"
              onClick={() => setShow((s) => !s)}
              aria-label={show ? 'Hide password' : 'Show password'}
              style={{
                position: 'absolute',
                right: 4,
                top: 4,
                height: 36,
                padding: '0 10px',
                color: 'var(--text-3)',
                fontSize: 12,
                fontWeight: 650,
              }}
            >
              {show ? 'HIDE' : 'SHOW'}
            </button>
          </div>
        </Field>

        <button type="submit" className="btn btn-primary btn-lg btn-block" disabled={busy} style={{ marginTop: 4 }}>
          {busy ? 'Signing in…' : 'Sign in'}
        </button>
      </form>

      <p className="auth-alt">
        New to Forbidden? <Link to="/register">Create an account</Link>
      </p>
    </AuthLayout>
  );
}

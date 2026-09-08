import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { apiError } from '../lib/format';
import AuthLayout from '../components/AuthLayout';
import Field from '../components/Field';
import { IconCheck } from '../components/icons';
import '../styles/auth.css';

export default function Register() {
  const { register } = useAuth();
  const navigate = useNavigate();

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setFieldErrors({});

    const fe = {};
    if (name.trim().length < 2) fe.name = 'Please enter your full name.';
    if (!/^\S+@\S+\.\S+$/.test(email.trim())) fe.email = 'Enter a valid email address.';
    if (password.length < 8) fe.password = 'Password must be at least 8 characters.';
    if (confirm !== password) fe.confirm = 'Passwords do not match.';

    if (Object.keys(fe).length) {
      setFieldErrors(fe);
      return;
    }

    setBusy(true);
    try {
      await register({
        name: name.trim(),
        email: email.trim(),
        password,
        password_confirmation: confirm,
      });
      navigate('/marketplace', { replace: true });
    } catch (err) {
      const msg = apiError(err, 'Registration failed.');
      setError(msg);
      // surface field errors inline, mapping backend keys to local field keys
      const mapKey = { name: 'name', email: 'email', password: 'password', password_confirmation: 'confirm' };
      const fieldErr = err?.response?.data?.errors ?? {};
      const firstKey = Object.keys(fieldErr)[0];
      if (firstKey && mapKey[firstKey]) {
        setFieldErrors({ [mapKey[firstKey]]: fieldErr[firstKey][0] });
      }
    } finally {
      setBusy(false);
    }
  };

  const PasswordOk = () =>
    password.length >= 8 ? (
      <span className="tiny" style={{ color: 'var(--buy)', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
        <IconCheck size={13} /> Strong enough
      </span>
    ) : (
      <span className="tiny faint">Minimum 8 characters</span>
    );

  return (
    <AuthLayout>
      <h2 className="ac-title">Create your account</h2>
      <p className="ac-sub">
        A USDT wallet is created for you automatically when you join.
      </p>

      {error && (
        <div className="auth-banner" role="alert">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
          {error}
        </div>
      )}

      <form onSubmit={submit} noValidate>
        <Field label="Full name" htmlFor="name" error={fieldErrors.name} required>
          <input
            id="name"
            className="input"
            type="text"
            autoComplete="name"
            placeholder="Ada Obi"
            value={name}
            onChange={(e) => setName(e.target.value)}
          />
        </Field>

        <Field label="Email address" htmlFor="email" error={fieldErrors.email} required>
          <input
            id="email"
            className="input"
            type="email"
            autoComplete="email"
            placeholder="you@example.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </Field>

        <Field label="Password" htmlFor="password" error={fieldErrors.password} hint={<PasswordOk />} required>
          <input
            id="password"
            className="input"
            type="password"
            autoComplete="new-password"
            placeholder="••••••••"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </Field>

        <Field label="Confirm password" htmlFor="confirm" error={fieldErrors.confirm} required>
          <input
            id="confirm"
            className="input"
            type="password"
            autoComplete="new-password"
            placeholder="••••••••"
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
          />
        </Field>

        <button type="submit" className="btn btn-buy btn-lg btn-block" disabled={busy} style={{ marginTop: 4 }}>
          {busy ? 'Creating account…' : 'Create account'}
        </button>
      </form>

      <p className="auth-alt">
        Already registered? <Link to="/login">Sign in</Link>
      </p>
    </AuthLayout>
  );
}

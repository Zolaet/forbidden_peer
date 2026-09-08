import { useState } from 'react';
import api from '../api/axios';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../components/Toast';
import Modal from '../components/Modal';
import Field from '../components/Field';
import {
  PAYMENT_METHOD_TYPES,
  methodDisplay,
  methodIcon,
} from '../lib/constants';
import { apiError } from '../lib/format';
import { IconPlus, IconTrash, IconShield, IconCreditCard } from '../components/icons';

const FIELD_HINTS = {
  bank_transfer: { accLabel: 'Account number', providerLabel: 'Bank name', providerPh: 'e.g. Access Bank', accPh: 'e.g. 0123456789' },
  mobile_money: { accLabel: 'Phone number', providerLabel: 'Provider', providerPh: 'e.g. M-Pesa / MTN MoMo', accPh: 'e.g. +254 712 345 678' },
  paypal: { accLabel: 'PayPal email', providerLabel: 'Provider', providerPh: 'PayPal', accPh: 'you@example.com' },
  other: { accLabel: 'Account number / ID', providerLabel: 'Provider', providerPh: 'e.g. Coinbase / Skrill', accPh: 'Identifier' },
};

export default function PaymentMethods() {
  const { user, refresh } = useAuth();
  const toast = useToast();

  const methods = user?.payment_methods ?? [];

  const [addOpen, setAddOpen] = useState(false);
  const [removing, setRemoving] = useState(null);

  // form
  const [type, setType] = useState('bank_transfer');
  const [accountName, setAccountName] = useState('');
  const [accountNumber, setAccountNumber] = useState('');
  const [provider, setProvider] = useState('');
  const [instructions, setInstructions] = useState('');
  const [errors, setErrors] = useState({});
  const [serverError, setServerError] = useState('');
  const [busy, setBusy] = useState(false);

  const reset = () => {
    setType('bank_transfer');
    setAccountName('');
    setAccountNumber('');
    setProvider('');
    setInstructions('');
    setErrors({});
    setServerError('');
  };

  const close = () => {
    setAddOpen(false);
    reset();
  };

  const submit = async (e) => {
    e.preventDefault();
    setServerError('');
    const errs = {};
    if (!accountName.trim()) errs.accountName = 'Required.';
    if (!accountNumber.trim()) errs.accountNumber = 'Required.';
    if (!provider.trim()) errs.provider = 'Required.';
    setErrors(errs);
    if (Object.keys(errs).length) return;

    setBusy(true);
    try {
      await api.post('/payment-methods', {
        type,
        account_name: accountName.trim(),
        account_number: accountNumber.trim(),
        bank_or_provider_name: provider.trim(),
        ...(instructions.trim() ? { instructions: instructions.trim() } : {}),
      });
      await refresh();
      toast.ok('Payment method added', `${methodDisplay(type)} is now active on your account.`);
      close();
    } catch (err) {
      setServerError(apiError(err, 'Could not add payment method.'));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (m) => {
    setRemoving(m.id);
    try {
      await api.delete(`/payment-methods/${m.id}`);
      await refresh();
      toast.info('Payment method removed', `${methodDisplay(m.type)} deactivated.`);
    } catch (err) {
      toast.err('Remove failed', apiError(err));
    } finally {
      setRemoving(null);
    }
  };

  const h = FIELD_HINTS[type] ?? FIELD_HINTS.other;

  return (
    <div className="container page">
      <div className="page-head">
        <div>
          <div className="eyebrow">Account</div>
          <h1 className="page-title">Payment methods</h1>
          <p className="page-sub">How buyers pay you when they take your sell offers.</p>
        </div>
        <button className="btn btn-primary" onClick={() => { setAddOpen(true); reset(); }}>
          <IconPlus size={16} /> Add payment method
        </button>
      </div>

      {methods.length === 0 ? (
        <div className="panel state-block">
          <span className="avatar" style={{ width: 52, height: 52, fontSize: 22, background: 'var(--brand-soft)', color: 'var(--brand-2)' }}>
            <IconCreditCard size={24} />
          </span>
          <div className="state-title">No payment methods yet</div>
          <p className="small">Add a bank account, mobile-money number or PayPal so traders can pay you.</p>
          <button className="btn btn-primary btn-sm" style={{ marginTop: 10 }} onClick={() => { setAddOpen(true); reset(); }}>
            <IconPlus size={15} /> Add your first method
          </button>
        </div>
      ) : (
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fill, minmax(300px, 1fr))',
            gap: 14,
          }}
        >
          {methods.map((m) => (
            <div className="panel" key={m.id} style={{ padding: 18, display: 'flex', flexDirection: 'column', gap: 4 }}>
              <div className="row-between" style={{ marginBottom: 6 }}>
                <span className="badge badge-soft" style={{ fontSize: 13, gap: 6 }}>
                  <span>{methodIcon(m.type)}</span> {methodDisplay(m.type)}
                </span>
                <button
                  className="icon-btn"
                  aria-label="Remove method"
                  title="Remove"
                  onClick={() => remove(m)}
                  disabled={removing === m.id}
                  style={{ color: 'var(--text-3)' }}
                >
                  {removing === m.id ? <span className="spin" style={{ width: 15, height: 15 }} /> : <IconTrash size={16} />}
                </button>
              </div>
              <div style={{ fontWeight: 700, fontSize: 15 }}>{m.account_name}</div>
              <div className="mono-num small" style={{ color: 'var(--text-2)' }}>
                {m.account_number}
              </div>
              <div className="tiny" style={{ color: 'var(--text-3)' }}>
                {m.bank_or_provider_name}
              </div>
              {m.instructions && (
                <div className="tiny" style={{ color: 'var(--text-3)', marginTop: 6, borderTop: '1px solid var(--line-faint)', paddingTop: 8 }}>
                  {m.instructions}
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      <div className="notice notice-muted" style={{ marginTop: 20, maxWidth: 640 }}>
        <IconShield size={17} style={{ marginTop: 1 }} />
        <span>
          Your payment methods are only shown to traders who open an order against your sell ads —
          never on your public profile or in the marketplace.
        </span>
      </div>

      <Modal open={addOpen} onClose={close} title="Add a payment method">
        {serverError && (
          <div className="auth-banner" role="alert" style={{ marginBottom: 16 }}>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
            {serverError}
          </div>
        )}

        <form onSubmit={submit} noValidate>
          <Field label="Method type" required>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
              {PAYMENT_METHOD_TYPES.map((m) => (
                <button
                  key={m.value}
                  type="button"
                  onClick={() => { setType(m.value); setErrors({}); }}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 9,
                    padding: '11px 12px',
                    borderRadius: 'var(--r-sm)',
                    border: '1px solid',
                    borderColor: type === m.value ? 'var(--brand)' : 'var(--line)',
                    background: type === m.value ? 'var(--brand-soft)' : 'var(--bg-2)',
                    color: type === m.value ? 'var(--text-1)' : 'var(--text-2)',
                    fontWeight: 600,
                    fontSize: 13.5,
                    textAlign: 'left',
                  }}
                >
                  <span style={{ fontSize: 16 }}>{m.icon}</span> {m.label}
                </button>
              ))}
            </div>
          </Field>

          <Field label="Account holder name" error={errors.accountName} required>
            <input className="input" placeholder="e.g. Ada Obi" value={accountName}
              onChange={(e) => setAccountName(e.target.value)} />
          </Field>

          <div className="row" style={{ gap: 14, flexWrap: 'wrap' }}>
            <div style={{ flex: '1 1 220px' }}>
              <Field label={h.accLabel} error={errors.accountNumber} required>
                <input className="input" placeholder={h.accPh} value={accountNumber}
                  onChange={(e) => setAccountNumber(e.target.value)} />
              </Field>
            </div>
            <div style={{ flex: '1 1 220px' }}>
              <Field label={h.providerLabel} error={errors.provider} required>
                <input className="input" placeholder={h.providerPh} value={provider}
                  onChange={(e) => setProvider(e.target.value)} />
              </Field>
            </div>
          </div>

          <Field label="Instructions to sender" optional>
            <textarea className="input" placeholder="e.g. Send a transfer and include your order ref as the narration."
              value={instructions} onChange={(e) => setInstructions(e.target.value)} maxLength={1000} />
          </Field>

          <div className="modal-foot" style={{ padding: '4px 0 0', display: 'flex' }}>
            <button type="button" className="btn btn-ghost" onClick={close} style={{ flex: 1 }}>Cancel</button>
            <button type="submit" className="btn btn-primary" style={{ flex: 2 }} disabled={busy}>
              {busy ? 'Saving…' : 'Save payment method'}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
}

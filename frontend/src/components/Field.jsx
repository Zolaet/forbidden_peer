/** Form field wrapper: label row (label + hint) + control + error line. */
export default function Field({
  label,
  hint,
  error,
  required,
  optional,
  children,
  htmlFor,
  className = '',
}) {
  return (
    <div className={`field ${error ? 'has-error' : ''} ${className}`}>
      {(label || hint) && (
        <div className="label-row">
          <label htmlFor={htmlFor}>
            {label}
            {required && <span style={{ color: 'var(--sell)' }}> *</span>}
            {optional && <span className="label-hint"> (optional)</span>}
          </label>
          {hint && <span className="label-hint">{hint}</span>}
        </div>
      )}
      {children}
      {error && (
        <div className="field-error" role="alert">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
          {error}
        </div>
      )}
    </div>
  );
}

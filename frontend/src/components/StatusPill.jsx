export const STATUS_META = {
  pending: { label: 'Awaiting payment', cls: 'st-pending', dot: '●' },
  paid: { label: 'Paid · awaiting release', cls: 'st-paid', dot: '◐' },
  completed: { label: 'Completed', cls: 'st-completed', dot: '✓' },
  cancelled: { label: 'Cancelled', cls: 'st-cancelled', dot: '✕' },
  expired: { label: 'Expired', cls: 'st-expired', dot: '✕' },
};

export default function StatusPill({ status }) {
  const m = STATUS_META[status] ?? { label: status, cls: 'st-soft' };
  return (
    <span className={`st ${m.cls}`}>
      <span className="dot" style={{ width: 6, height: 6 }} />
      {m.label}
    </span>
  );
}

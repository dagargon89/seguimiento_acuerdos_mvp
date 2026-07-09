interface StatCardProps {
  value: string | number;
  label: string;
  sublabel: string;
  variant?: 'brand' | 'accent' | 'default';
}

/** Tarjeta de métrica del panel (1:1 con StatCard() del demo). */
export function StatCard({ value, label, sublabel, variant = 'default' }: StatCardProps) {
  return (
    <div className={`stat-card stat-card--${variant}`}>
      <div className="stat-card__value">{value}</div>
      <div className="stat-card__label">{label}</div>
      <div className="stat-card__sublabel">{sublabel}</div>
    </div>
  );
}

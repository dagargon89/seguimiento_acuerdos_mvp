import type { BadgeVariant } from './EstadoHelpers';

interface BadgeProps {
  variant: BadgeVariant;
  size?: 'sm' | 'md';
  label: string;
}

/** Badge del DS con dot (1:1 con el helper Badge() del demo). */
export function Badge({ variant, size = 'sm', label }: BadgeProps) {
  return (
    <span className={`badge badge--${size} badge--${variant}`}>
      <span className="badge__dot" />
      {label}
    </span>
  );
}

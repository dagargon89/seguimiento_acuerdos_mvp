/**
 * Badge del estado de la solicitud de revisión de un acuerdo: "En revisión"
 * (pendiente) o "Rechazado" (rechazada). Devuelve null cuando no hay solicitud
 * (sin_solicitud), para poder colocarlo junto al badge de estado sin condicionales
 * repetidos en cada tabla.
 */
import type { RevisionEstado } from '../lib';
import { Badge } from './Badge';
import { revisionMeta } from './EstadoHelpers';

export function RevisionBadge({ estado, size = 'sm' }: { estado: RevisionEstado; size?: 'sm' | 'md' }) {
  const rm = revisionMeta(estado);
  if (rm === null) return null;
  return <Badge variant={rm.variant} size={size} label={rm.label} />;
}

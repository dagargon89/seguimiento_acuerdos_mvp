import { iniciales } from './EstadoHelpers';
import { estiloAvatar } from '../lib/avatarColores';

interface AvatarProps {
  nombre: string;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  /** teal (default) para responsables; blue para corresponsables (1:1 con el prototipo). */
  tono?: 'teal' | 'blue';
  /** Color hex (#RRGGBB) de identidad del usuario; si es válido, tiene prioridad sobre `tono`. */
  color?: string | null;
  title?: string;
}

/** Avatar de iniciales (1:1 con el prototipo Cívica Nocturna). */
export function Avatar({ nombre, size = 'md', tono = 'teal', color, title }: AvatarProps) {
  const estilo = estiloAvatar(color);
  return (
    <span
      className={`avatar avatar--${size}${!estilo && tono === 'blue' ? ' avatar--blue' : ''}`}
      style={estilo}
      title={title ?? nombre}
    >
      {iniciales(nombre)}
    </span>
  );
}

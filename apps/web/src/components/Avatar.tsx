import { iniciales } from './EstadoHelpers';

interface AvatarProps {
  nombre: string;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  /** teal (default) para responsables; blue para corresponsables (1:1 con el prototipo). */
  tono?: 'teal' | 'blue';
  title?: string;
}

/** Avatar de iniciales (1:1 con el prototipo Cívica Nocturna). */
export function Avatar({ nombre, size = 'md', tono = 'teal', title }: AvatarProps) {
  return (
    <span className={`avatar avatar--${size}${tono === 'blue' ? ' avatar--blue' : ''}`} title={title ?? nombre}>
      {iniciales(nombre)}
    </span>
  );
}

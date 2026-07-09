import { iniciales } from './EstadoHelpers';

interface AvatarProps {
  nombre: string;
  size?: 'sm' | 'md' | 'lg';
  title?: string;
}

/** Avatar de iniciales (1:1 con el demo). */
export function Avatar({ nombre, size = 'md', title }: AvatarProps) {
  return (
    <span className={`avatar avatar--${size}`} title={title ?? nombre}>
      {iniciales(nombre)}
    </span>
  );
}

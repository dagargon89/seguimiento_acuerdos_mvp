/**
 * Colores de avatar (identidad visual del usuario). Se guarda un color hex
 * libre por usuario (`avatar_color`); estos 7 son los presets sugeridos y el
 * resto se elige con un color picker. `null`/ausente = color por defecto.
 */

/** Color por defecto (teal Cívica Nocturna) cuando el usuario no eligió uno. */
export const AVATAR_COLOR_DEFAULT = '#2fbfa5';

/** Presets sugeridos (7). Tonos medios legibles en tema claro y oscuro. */
export const AVATAR_PRESETS: ReadonlyArray<{ hex: string; nombre: string }> = [
  { hex: '#2fbfa5', nombre: 'Teal' },
  { hex: '#5b9df5', nombre: 'Azul' },
  { hex: '#a878e6', nombre: 'Morado' },
  { hex: '#37b24d', nombre: 'Verde' },
  { hex: '#d99a2b', nombre: 'Ámbar' },
  { hex: '#e5606b', nombre: 'Rosa' },
  { hex: '#8a94a6', nombre: 'Gris' },
];

/** ¿Es un color hex `#RRGGBB` válido? */
export function esColorHexValido(color: string): boolean {
  return /^#[0-9a-fA-F]{6}$/.test(color);
}

/**
 * Estilos inline del avatar a partir de un color hex: fondo tenue, borde y
 * texto en el color, replicando el look del avatar teal por defecto. Devuelve
 * `undefined` si no hay color válido (se usa la clase CSS por defecto).
 */
export function estiloAvatar(color: string | null | undefined): { background: string; borderColor: string; color: string } | undefined {
  if (!color || !esColorHexValido(color)) return undefined;
  return {
    background: `${color}24`, // ~14% alpha
    borderColor: `${color}40`, // ~25% alpha
    color,
  };
}

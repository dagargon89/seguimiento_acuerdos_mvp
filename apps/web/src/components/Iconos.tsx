/**
 * Iconos SVG inline del sidebar (stroke, currentColor). Set propio y mínimo
 * para no sumar dependencias; 18px por defecto.
 */
import type { SVGProps } from 'react';

type IconoProps = SVGProps<SVGSVGElement> & { size?: number };

function base({ size = 18, ...props }: IconoProps) {
  return {
    width: size,
    height: size,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.8,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    'aria-hidden': true,
    ...props,
  };
}

/** Panel: rejilla de tablero. */
export function IconoPanel(props: IconoProps) {
  return (
    <svg {...base(props)}>
      <rect x="3" y="3" width="7.5" height="9.5" rx="1.5" />
      <rect x="13.5" y="3" width="7.5" height="5.5" rx="1.5" />
      <rect x="13.5" y="11.5" width="7.5" height="9.5" rx="1.5" />
      <rect x="3" y="15.5" width="7.5" height="5.5" rx="1.5" />
    </svg>
  );
}

/** Capturar acuerdo: hoja con signo +. */
export function IconoCaptura(props: IconoProps) {
  return (
    <svg {...base(props)}>
      <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
      <path d="M14 3v5h5" />
      <path d="M12 11.5v5M9.5 14h5" />
    </svg>
  );
}

/** Recordatorios: campana. */
export function IconoRecordatorios(props: IconoProps) {
  return (
    <svg {...base(props)}>
      <path d="M18 9a6 6 0 1 0-12 0c0 6-2.5 7-2.5 7h17S18 15 18 9" />
      <path d="M10.3 20a2 2 0 0 0 3.4 0" />
    </svg>
  );
}

/** Mis acuerdos: persona con palomita (acuerdos designados al usuario). */
export function IconoMisAcuerdos(props: IconoProps) {
  return (
    <svg {...base(props)}>
      <circle cx="10" cy="8" r="3.5" />
      <path d="M4.5 20c0-3 2.5-5 5.5-5 1.2 0 2.3.3 3.2.9" />
      <path d="m15 17.5 2 2 3.5-4" />
    </svg>
  );
}

/** Checklist: cuadro con palomita. */
export function IconoChecklist(props: IconoProps) {
  return (
    <svg {...base(props)}>
      <rect x="4" y="4" width="16" height="16" rx="2.5" />
      <path d="m8.5 12.5 2.5 2.5 5-5.5" />
    </svg>
  );
}

/** Áreas: capas apiladas (catálogo organizacional). */
export function IconoAreas(props: IconoProps) {
  return (
    <svg {...base(props)}>
      <path d="m12 3 9 5-9 5-9-5z" />
      <path d="m3.5 12.5 8.5 4.7 8.5-4.7" />
      <path d="m3.5 16.5 8.5 4.7 8.5-4.7" />
    </svg>
  );
}

/** Usuarios: dos personas. */
export function IconoUsuarios(props: IconoProps) {
  return (
    <svg {...base(props)}>
      <circle cx="9" cy="8.5" r="3.5" />
      <path d="M3.5 20c0-3 2.5-5 5.5-5s5.5 2 5.5 5" />
      <path d="M16 5.6a3.5 3.5 0 0 1 0 5.8M17.5 15.4c1.8.8 3 2.4 3 4.6" />
    </svg>
  );
}

/** Tema claro: sol (se muestra en modo oscuro para cambiar a claro). */
export function IconoSol(props: IconoProps) {
  return (
    <svg {...base({ size: 16, ...props })}>
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
    </svg>
  );
}

/** Tema oscuro: luna (se muestra en modo claro para cambiar a oscuro). */
export function IconoLuna(props: IconoProps) {
  return (
    <svg {...base({ size: 16, ...props })}>
      <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
    </svg>
  );
}

/** Colapsar/expandir el sidebar: doble chevron. */
export function IconoColapsar({ invertido = false, ...props }: IconoProps & { invertido?: boolean }) {
  return (
    <svg {...base(props)} style={{ transform: invertido ? 'scaleX(-1)' : undefined, ...props.style }}>
      <path d="m13 6-6 6 6 6" />
      <path d="m18 6-6 6 6 6" />
    </svg>
  );
}

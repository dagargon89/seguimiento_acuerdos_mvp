import type { Paginacion as PaginacionState } from '../lib/usePaginacion';

/** Genera la ventana de números de página con elipsis (…). */
function ventana(pagina: number, total: number): (number | '…')[] {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
  const paginas: (number | '…')[] = [1];
  const ini = Math.max(2, pagina - 1);
  const fin = Math.min(total - 1, pagina + 1);
  if (ini > 2) paginas.push('…');
  for (let p = ini; p <= fin; p++) paginas.push(p);
  if (fin < total - 1) paginas.push('…');
  paginas.push(total);
  return paginas;
}

/**
 * Controles de paginación para las tablas. No renderiza nada cuando hay una
 * sola página; el contador "Mostrando…" sí aparece siempre que haya datos.
 */
export function Paginacion({ estado, sustantivo = 'elementos' }: { estado: PaginacionState<unknown>; sustantivo?: string }) {
  const { pagina, totalPaginas, desde, hasta, total, irA, anterior, siguiente } = estado;
  if (total === 0) return null;

  return (
    <nav className="paginacion" aria-label="Paginación">
      <span className="paginacion__conteo">
        Mostrando {desde}–{hasta} de {total} {sustantivo}
      </span>
      {totalPaginas > 1 && (
        <div className="paginacion__controles">
          <button
            type="button"
            className="paginacion__btn"
            onClick={anterior}
            disabled={pagina === 1}
            aria-label="Página anterior"
          >
            ‹
          </button>
          {ventana(pagina, totalPaginas).map((p, i) =>
            p === '…' ? (
              <span key={`gap-${i}`} className="paginacion__gap" aria-hidden="true">
                …
              </span>
            ) : (
              <button
                type="button"
                key={p}
                className={`paginacion__btn${p === pagina ? ' is-active' : ''}`}
                onClick={() => irA(p)}
                aria-current={p === pagina ? 'page' : undefined}
                aria-label={`Página ${p}`}
              >
                {p}
              </button>
            ),
          )}
          <button
            type="button"
            className="paginacion__btn"
            onClick={siguiente}
            disabled={pagina === totalPaginas}
            aria-label="Página siguiente"
          >
            ›
          </button>
        </div>
      )}
    </nav>
  );
}

import type { Acuerdo, Resumen } from './types';
import { COLUMNAS_ACUERDO, filaAcuerdo, filasResumen, nombreArchivo } from './exportar';

// write-excel-file v4 usa subpath exports; en navegador es `.../browser`.
// La descarga se dispara con `.toFile(nombre)` (v4 ya no acepta `fileName`).

const PJ_PURPLE = '#53155A';
const H = (value: string) => ({ value, fontWeight: 'bold' as const, color: '#FFFFFF', backgroundColor: PJ_PURPLE });
const C = (value: string) => ({ value, wrap: true });

export async function descargarAcuerdosXlsx(acuerdos: Acuerdo[], hoyISO: string): Promise<void> {
  const writeXlsxFile = (await import('write-excel-file/browser')).default;
  const encabezado = COLUMNAS_ACUERDO.map((h) => H(h));
  const filas = acuerdos.map((a) => filaAcuerdo(a).map((v) => C(v)));
  const columns = COLUMNAS_ACUERDO.map((h) =>
    h === 'Acción' || h === 'Observaciones' ? { width: 40 } : h === 'ID' ? { width: 6 } : { width: 20 },
  );
  await writeXlsxFile([encabezado, ...filas], { columns, sheet: 'Acuerdos' }).toFile(
    nombreArchivo('acuerdos', hoyISO, 'xlsx'),
  );
}

export async function descargarResumenXlsx(r: Resumen, hoyISO: string): Promise<void> {
  const writeXlsxFile = (await import('write-excel-file/browser')).default;
  const { totales, porResponsable } = filasResumen(r);
  const data = [
    [H('Indicador'), H('Total')],
    ...totales.map(([k, v]) => [C(k), C(v)]),
    [C(''), C('')],
    [H('Responsable'), H('En proceso'), H('Vencidos'), H('Por vencer')],
    ...porResponsable.map((fila) => fila.map((v) => C(v))),
  ];
  await writeXlsxFile(data, { sheet: 'Resumen' }).toFile(nombreArchivo('resumen', hoyISO, 'xlsx'));
}

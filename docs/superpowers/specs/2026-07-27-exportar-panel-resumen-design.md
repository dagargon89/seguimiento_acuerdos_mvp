# Diseño — Quick win #1: Exportar panel (XLSX) y resumen (XLSX + PDF)

| Campo | Valor |
|---|---|
| Documento | Spec de diseño — Exportación del listado del Panel y del resumen ejecutivo |
| Fecha | 2026-07-27 |
| Backlog | doc 08 §1 (quick win #1), orden sugerido §6 |
| Depende de | Panel.tsx (lista filtrada client-side), ResumenModal.tsx (`api.getResumen()`), tokens PJ (doc 08) |
| Estado | Diseño aprobado — pendiente de plan de implementación |
| Decisiones de Dirección | Formato: **ambos** (XLSX + PDF). Enfoque: **cliente**. (respondidas 2026-07-27) |

## 1. Contexto y decisión de enfoque

Dirección comparte estados en reunión; hoy no hay salida imprimible ni exportable. El
backlog (§1) pide **XLSX del listado filtrado y del `/resumen`** y **PDF del resumen
ejecutivo**, respetando filtros activos, con identidad PJ en el PDF y **sin exponer datos
fuera del ámbito del actor**.

**Decisión (aprobada): generación en el cliente.** El listado (`Panel.tsx`) y el resumen
(`ResumenModal` vía `api.getResumen()`) **ya llegan filtrados y acotados al ámbito del
actor por el backend** (visibilidad ADR-007 + `/resumen` por ámbito). Exportar lo que el
usuario ya ve garantiza por construcción el criterio "sin datos de otros ámbitos", sin
tocar backend ni el contrato congelado (regla #3). Cero endpoints nuevos.

Se descartó el enfoque servidor (`GET /export`) por trabajo desproporcionado para un quick
win (endpoint + Policies + streaming + branding server-side) sin beneficio para el volumen
actual (≤200 filas por vista, límite ya vigente en el Panel).

### 1.1 Librerías (validadas contra la DoD "npm audit sin críticos", Etapa 3)

- **XLSX → `write-excel-file` (v4)**, cargada con `import()` **dinámico** (code-split): no
  entra al bundle inicial, así que **no compromete el umbral <350 KB gzip** (doc 06 §4).
  Purpose-built para navegador (~1.8 MB unpacked, frente a ~21 MB de `exceljs`); soporta
  color de fuente/fondo por celda para el encabezado PJ y ancho de columnas.
- **PDF → print-CSS**: una vista imprimible construida con los **tokens PJ reales**
  (fuentes Fredoka/Montserrat, morado 700, lima) + `window.print()`; el usuario elige
  "Guardar como PDF". **Cero dependencia**, máxima fidelidad de marca (regla #11) y sin
  rasterizado (texto vectorial nítido). Se descartó jsPDF/html2canvas por peor fidelidad
  tipográfica y peso extra.

`npm audit` debe quedar limpio tras instalar `write-excel-file`; si arrojara un aviso
crítico se revierte a la alternativa `exceljs` (también lazy) y se anota aquí.

## 2. Alcance

1. **XLSX del listado filtrado** — botón en la toolbar del Panel. Exporta exactamente
   `lista` (los acuerdos tras estado + responsable + área + rango + búsqueda), no `todos`.
2. **XLSX del resumen** — botón en `ResumenModal`. Totales + tabla por responsable.
3. **PDF del resumen ejecutivo** — botón en `ResumenModal`, vista imprimible con identidad PJ.

## 3. Diseño

### 3.1 Helper puro de exportación (nuevo, testeable) — `lib/exportar.ts`

Toda la transformación acuerdo→fila y resumen→filas vive en funciones **puras** (sin red,
sin DOM), testeables con vitest; la carga de la librería se hace aparte con `import()`.

```ts
// Encabezados y orden de columnas del listado (estables; es el "contrato" del export).
export const COLUMNAS_ACUERDO = [
  'ID', 'Reunión', 'Fecha reunión', 'Área', 'Tema', 'Acción',
  'Responsable', 'Corresponsables', 'Fecha compromiso', 'Estado',
  'Enlaces', 'Observaciones', 'Concluido por', 'Concluido el',
] as const;

/** Una fila por acuerdo, en el orden de COLUMNAS_ACUERDO, todo ya como string. */
export function filaAcuerdo(a: Acuerdo): string[];

/** Nombre de archivo con marca temporal local: "acuerdos_2026-07-27.xlsx". */
export function nombreArchivo(base: string, hoyISO: string, ext: 'xlsx' | 'pdf'): string;

/** Filas del resumen (totales + por responsable) para la hoja XLSX. */
export function filasResumen(r: Resumen): { totales: string[][]; porResponsable: string[][] };
```

Reglas de mapeo (listado):
- `Estado` usa la etiqueta humana (`EST[a.estado].label`: "En proceso"/"Vencido"/"Concluido").
- `Fecha compromiso`/`Fecha reunión`/`Concluido el` con `fmtL` ("8 de julio de 2026");
  `Concluido el` toma solo la parte fecha (`concluido_at` es datetime).
- `Corresponsables` = nombres unidos por `", "`; vacío = `""`.
- `Enlaces` = URLs unidas por `"\n"` (varias líneas en la celda).
- `Tema`/`Observaciones`/`Concluido por` nulos → `""`.
- Nunca se interpola en fórmula ni HTML: son valores de celda de texto (la librería
  escapa; el PDF se arma con nodos React, que escapan). No `dangerouslySetInnerHTML`.

### 3.2 Generación XLSX (efecto, no en el helper puro) — `lib/exportarXlsx.ts`

```ts
export async function descargarAcuerdosXlsx(acuerdos: Acuerdo[], hoyISO: string): Promise<void>;
export async function descargarResumenXlsx(r: Resumen, hoyISO: string): Promise<void>;
```

- `const writeXlsxFile = (await import('write-excel-file')).default;` — **code-split**.
- Fila de encabezado con estilo PJ: fondo morado `#53155A`, texto blanco, negrita.
- Anchos de columna razonables (acción/observaciones anchas, fechas angostas).
- Dispara descarga con `fileName` de `nombreArchivo(...)`.
- Errores (p.ej. chunk que no carga offline) → se capturan y se muestran con el `Toast`
  existente; no rompen la vista.

### 3.3 PDF del resumen — vista imprimible + print-CSS

- Componente `ResumenPdf` (o bloque dentro de `ResumenModal`) que renderiza el resumen con
  marca PJ: cabecera morada con "Panel de Acuerdos · Plan Juárez", ámbito y fecha; tira de
  totales (en proceso/vencidos/por vencer/concluidos); tabla por responsable.
- Impresión aislada con `@media print`: se marca el nodo con `id="resumen-print"` y una
  regla `@media print { body * { visibility:hidden } #resumen-print, #resumen-print * { visibility:visible } #resumen-print { position:absolute; inset:0 } }`
  en el CSS legacy (o un `<style media="print">` acotado). Botón "Exportar PDF" llama
  `window.print()`.
- Fidelidad de marca: usa las variables `--pj-*` y las fuentes ya cargadas; colores de
  estado (éxito/adv/error) del doc 08. Pie: "Generado el {fecha} · confidencial".

### 3.4 Ubicación en la UI

- **Panel**: botón `Exportar` (secundario) en la toolbar, junto al spacer, antes de
  "+ Nuevo acuerdo". Deshabilitado si `lista.length === 0`. Texto: "Exportar (N)".
- **ResumenModal**: en el pie del modal, dos botones: "Exportar XLSX" y "Exportar PDF".
- Sin cambios de layout más allá de estos controles (regla #11).

## 4. Verificación (DoD)

- Test unitario de `lib/exportar.ts` (vitest): `filaAcuerdo` con nulos/corresponsables/
  enlaces múltiples; `nombreArchivo`; `filasResumen` con y sin responsables.
- `cd apps/web && npm run typecheck && npm run lint && npm test && npm run build` en verde;
  **confirmar en el reporte de build que `write-excel-file` queda en un chunk aparte** y el
  bundle inicial sigue <350 KB gzip.
- `npm audit` sin críticos tras instalar la dependencia.
- E2e visual: (a) filtrar el Panel, Exportar → XLSX abre en Excel con las columnas y solo
  las filas filtradas; (b) abrir Resumen → Exportar XLSX (totales + por responsable) y
  Exportar PDF (identidad PJ, imprimible/guardable). Verificar que un coordinador solo ve/
  exporta su ámbito.

## 5. Fuera de alcance (YAGNI)

- Endpoint `GET /export` server-side (descartado en §1).
- PDF del listado completo (solo XLSX para el listado; PDF solo para el resumen ejecutivo).
- Branding de "acta" formal por reunión (se aborda en quick win/feature #5 Reuniones).
- Selección de columnas por el usuario, o exportar todas las páginas más allá del límite
  de 200 ya vigente en el Panel.
- Programar/enviar el export por correo (el resumen por correo ya existe vía el job).

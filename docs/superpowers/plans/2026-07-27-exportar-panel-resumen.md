# Exportar panel (XLSX) y resumen (XLSX + PDF) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Exportar en el cliente el listado filtrado del Panel a XLSX y el resumen ejecutivo a XLSX + PDF (con identidad PJ), sin tocar backend ni el contrato congelado.

**Architecture:** Un helper **puro** `lib/exportar.ts` (mapeo acuerdo/resumen → filas, nombre de archivo) testeable con vitest; un módulo de **efectos** `lib/exportarXlsx.ts` que carga `write-excel-file` con `import()` dinámico (code-split) y dispara la descarga; el PDF del resumen se resuelve con una vista imprimible + print-CSS y `window.print()`. Botones en la toolbar del Panel y en el pie de `ResumenModal`.

**Tech Stack:** React 19 + TypeScript 5 + Vite 7 + TanStack Query 5 + vitest + `write-excel-file` v4 (lazy).

## Global Constraints

- **Client-side only:** cero cambios en `FiltrosAcuerdos`, `api.ts`, `api.real.ts`, backend o doc 05. Se exporta lo que el usuario ya ve (ya acotado a su ámbito por el backend).
- **Bundle:** `write-excel-file` SOLO vía `import()` dinámico → chunk aparte; el bundle inicial debe seguir <350 KB gzip (doc 06 §4). Verificar en el reporte de build.
- **npm audit sin críticos** tras instalar la dependencia (DoD Etapa 3).
- **Conversión 1:1 del demo (regla #11):** solo se agregan los botones; no se altera el layout aprobado. La toolbar ya hace `flex-wrap` (no tocar su CSS salvo la clase del botón y el bloque `@media print`).
- **Escape de salida (regla #7):** valores de celda como texto (la librería escapa); PDF con nodos React (escapan). Sin `dangerouslySetInnerHTML`, sin fórmulas.
- **Marca:** "Participa Juárez" en UI de la app; el PDF/branding institucional usa "Plan Juárez" según doc 08 (cabecera del correo/resumen ya dice "Participa Juárez" — mantener consistencia con `ResumenModal`, que usa "Participa Juárez").
- **Fechas** con helpers de `lib/fechas` (`fmtL`, `hoyISO`); nada de `new Date()` crudo para formateo.

## File Structure

- Create `apps/web/src/lib/exportar.ts` — puro: `COLUMNAS_ACUERDO`, `filaAcuerdo`, `filasResumen`, `nombreArchivo`.
- Create `apps/web/src/lib/__tests__/exportar.test.ts` — tests unitarios del helper puro.
- Create `apps/web/src/lib/exportarXlsx.ts` — efecto: `descargarAcuerdosXlsx`, `descargarResumenXlsx` (lazy `write-excel-file`).
- Modify `apps/web/src/pages/Panel.tsx` — botón "Exportar (N)" en la toolbar.
- Modify `apps/web/src/components/ResumenModal.tsx` — botones "Exportar XLSX" / "Exportar PDF" + bloque imprimible marcado.
- Modify `apps/web/src/styles/legacy-demo.css` (o el CSS de estilos base) — regla `@media print` para aislar el nodo del resumen.
- Modify `apps/web/package.json` — dependencia `write-excel-file`.

---

## Task 1: Dependencia + helper puro `exportar.ts` con tests

**Files:**
- Modify: `apps/web/package.json` (+ lockfile)
- Create: `apps/web/src/lib/exportar.ts`
- Test: `apps/web/src/lib/__tests__/exportar.test.ts`

**Interfaces:**
- Consumes: `Acuerdo`, `Resumen` de `./types`; `EST` de `../components/EstadoHelpers`; `fmtL` de `./fechas`.
- Produces:
  ```ts
  export const COLUMNAS_ACUERDO: readonly string[];
  export function filaAcuerdo(a: Acuerdo): string[];
  export function filasResumen(r: Resumen): { totales: string[][]; porResponsable: string[][] };
  export function nombreArchivo(base: string, hoyISO: string, ext: 'xlsx' | 'pdf'): string;
  ```

- [ ] **Step 1: Instalar la dependencia y verificar audit**

Run:
```bash
cd apps/web && npm install write-excel-file@^4 && npm audit
```
Expected: instala v4.x; `npm audit` sin vulnerabilidades **críticas/altas** atribuibles a este paquete. Si las hubiera, revertir (`npm remove write-excel-file`) y usar `exceljs` (también lazy); anotar el cambio en la spec §1.1.

- [ ] **Step 2: Escribir los tests que fallan**

Crear `apps/web/src/lib/__tests__/exportar.test.ts`. Factory mínimo (mismo patrón que `filtrosPanel.test.ts`), construyendo solo los campos que leen los helpers.

```ts
import { describe, expect, it } from 'vitest';
import type { Acuerdo, Resumen } from '../types';
import { COLUMNAS_ACUERDO, filaAcuerdo, filasResumen, nombreArchivo } from '../exportar';

function mk(over: Partial<Acuerdo> & { id: number }): Acuerdo {
  return {
    id: over.id,
    reunion: over.reunion ?? { id: 1, nombre: 'Reunión de dirección', fecha: '2026-07-01' },
    area: over.area ?? { id: 1, nombre: 'Educación', activa: true },
    tema: over.tema ?? null,
    accion: over.accion ?? 'Acción X',
    responsable: over.responsable ?? { id: 10, nombre: 'Ana López', email: '', avatar_color: null },
    corresponsables: over.corresponsables ?? [],
    fecha_compromiso: over.fecha_compromiso ?? '2026-07-15',
    estado: over.estado ?? 'en_proceso',
    enlaces: over.enlaces ?? [],
    observaciones: over.observaciones ?? null,
    concluido_por: over.concluido_por ?? null,
    concluido_at: over.concluido_at ?? null,
  } as unknown as Acuerdo;
}

describe('filaAcuerdo', () => {
  it('tiene tantas columnas como encabezados', () => {
    expect(filaAcuerdo(mk({ id: 1 }))).toHaveLength(COLUMNAS_ACUERDO.length);
  });

  it('usa etiqueta humana del estado y formatea fechas', () => {
    const f = filaAcuerdo(mk({ id: 1, estado: 'vencido', fecha_compromiso: '2026-07-08' }));
    expect(f[COLUMNAS_ACUERDO.indexOf('Estado')]).toBe('Vencido');
    expect(f[COLUMNAS_ACUERDO.indexOf('Fecha compromiso')]).toBe('8 de julio de 2026');
  });

  it('une corresponsables y enlaces; nulos → cadena vacía', () => {
    const f = filaAcuerdo(mk({
      id: 2, tema: null, observaciones: null,
      corresponsables: [
        { id: 11, nombre: 'Beto Ruiz', email: '', avatar_color: null },
        { id: 12, nombre: 'Ceci Mora', email: '', avatar_color: null },
      ],
      enlaces: ['https://a.test/1', 'https://a.test/2'],
    }));
    expect(f[COLUMNAS_ACUERDO.indexOf('Corresponsables')]).toBe('Beto Ruiz, Ceci Mora');
    expect(f[COLUMNAS_ACUERDO.indexOf('Enlaces')]).toBe('https://a.test/1\nhttps://a.test/2');
    expect(f[COLUMNAS_ACUERDO.indexOf('Tema')]).toBe('');
    expect(f[COLUMNAS_ACUERDO.indexOf('Observaciones')]).toBe('');
  });

  it('concluido: incluye quién y cuándo (solo la fecha del datetime)', () => {
    const f = filaAcuerdo(mk({
      id: 3, estado: 'concluido',
      concluido_por: { id: 1, nombre: 'Dir Gen', email: '', avatar_color: null },
      concluido_at: '2026-07-20 10:30:00',
    }));
    expect(f[COLUMNAS_ACUERDO.indexOf('Concluido por')]).toBe('Dir Gen');
    expect(f[COLUMNAS_ACUERDO.indexOf('Concluido el')]).toBe('20 de julio de 2026');
  });
});

describe('filasResumen', () => {
  const r: Resumen = {
    ambito: 'general', area: null,
    en_proceso: 3, vencidos: 2, por_vencer_7d: 1, concluidos: 5,
    por_responsable: [
      { responsable: { id: 10, nombre: 'Ana López', email: '', avatar_color: null }, en_proceso: 2, vencidos: 1, por_vencer_7d: 0 },
    ],
  };
  it('totales cubren los 4 indicadores', () => {
    expect(filasResumen(r).totales).toEqual([
      ['En proceso', '3'], ['Vencidos', '2'], ['Por vencer (≤7 días)', '1'], ['Concluidos', '5'],
    ]);
  });
  it('una fila por responsable con sus tres conteos', () => {
    expect(filasResumen(r).porResponsable).toEqual([
      ['Ana López', '2', '1', '0'],
    ]);
  });
});

describe('nombreArchivo', () => {
  it('compone base_fecha.ext', () => {
    expect(nombreArchivo('acuerdos', '2026-07-27', 'xlsx')).toBe('acuerdos_2026-07-27.xlsx');
    expect(nombreArchivo('resumen', '2026-07-27', 'pdf')).toBe('resumen_2026-07-27.pdf');
  });
});
```

- [ ] **Step 3: Correr los tests para verificar que fallan**

Run: `cd apps/web && npx vitest run src/lib/__tests__/exportar.test.ts`
Expected: FAIL — `../exportar` no existe.

- [ ] **Step 4: Implementar el helper puro**

Crear `apps/web/src/lib/exportar.ts`:

```ts
import type { Acuerdo, Resumen } from './types';
import { EST } from '../components/EstadoHelpers';
import { fmtL } from './fechas';

/** Encabezados/orden de columnas del listado exportado (estable). */
export const COLUMNAS_ACUERDO = [
  'ID', 'Reunión', 'Fecha reunión', 'Área', 'Tema', 'Acción',
  'Responsable', 'Corresponsables', 'Fecha compromiso', 'Estado',
  'Enlaces', 'Observaciones', 'Concluido por', 'Concluido el',
] as const;

const fFecha = (iso: string | null): string => (iso ? fmtL(iso.slice(0, 10)) : '');

/** Una fila (todo string) por acuerdo, en el orden de COLUMNAS_ACUERDO. */
export function filaAcuerdo(a: Acuerdo): string[] {
  return [
    String(a.id),
    a.reunion.nombre,
    fFecha(a.reunion.fecha),
    a.area.nombre,
    a.tema ?? '',
    a.accion,
    a.responsable.nombre,
    a.corresponsables.map((c) => c.nombre).join(', '),
    fFecha(a.fecha_compromiso),
    EST[a.estado].label,
    a.enlaces.join('\n'),
    a.observaciones ?? '',
    a.concluido_por?.nombre ?? '',
    fFecha(a.concluido_at),
  ];
}

/** Totales + tabla por responsable del resumen, como filas de texto. */
export function filasResumen(r: Resumen): { totales: string[][]; porResponsable: string[][] } {
  return {
    totales: [
      ['En proceso', String(r.en_proceso)],
      ['Vencidos', String(r.vencidos)],
      ['Por vencer (≤7 días)', String(r.por_vencer_7d)],
      ['Concluidos', String(r.concluidos)],
    ],
    porResponsable: r.por_responsable.map((p) => [
      p.responsable.nombre, String(p.en_proceso), String(p.vencidos), String(p.por_vencer_7d),
    ]),
  };
}

/** "acuerdos_2026-07-27.xlsx" */
export function nombreArchivo(base: string, hoyISO: string, ext: 'xlsx' | 'pdf'): string {
  return `${base}_${hoyISO}.${ext}`;
}
```

- [ ] **Step 5: Correr los tests para verificar que pasan**

Run: `cd apps/web && npx vitest run src/lib/__tests__/exportar.test.ts`
Expected: PASS.

- [ ] **Step 6: typecheck + lint**

Run: `cd apps/web && npm run typecheck && npm run lint`
Expected: sin errores.

- [ ] **Step 7: Commit**

```bash
git add apps/web/package.json apps/web/package-lock.json apps/web/src/lib/exportar.ts apps/web/src/lib/__tests__/exportar.test.ts
git commit -m "feat(web): helper puro de exportación + dep write-excel-file (lazy)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Módulo de efectos `exportarXlsx.ts` (lazy write-excel-file)

**Files:**
- Create: `apps/web/src/lib/exportarXlsx.ts`

**Interfaces:**
- Consumes: `COLUMNAS_ACUERDO`, `filaAcuerdo`, `filasResumen`, `nombreArchivo` (Task 1); `Acuerdo`, `Resumen` de `./types`.
- Produces:
  ```ts
  export async function descargarAcuerdosXlsx(acuerdos: Acuerdo[], hoyISO: string): Promise<void>;
  export async function descargarResumenXlsx(r: Resumen, hoyISO: string): Promise<void>;
  ```

- [ ] **Step 1: Implementar**

Crear `apps/web/src/lib/exportarXlsx.ts`. `write-excel-file` (navegador) recibe una matriz de celdas `{ value, fontWeight?, color?, backgroundColor?, ... }`. Encabezado con marca PJ (fondo morado `#53155A`, texto blanco, negrita).

```ts
import type { Acuerdo, Resumen } from './types';
import { COLUMNAS_ACUERDO, filaAcuerdo, filasResumen, nombreArchivo } from './exportar';

const PJ_PURPLE = '#53155A';
const H = (value: string) => ({ value, fontWeight: 'bold' as const, color: '#FFFFFF', backgroundColor: PJ_PURPLE });
const C = (value: string) => ({ value, wrap: true });

export async function descargarAcuerdosXlsx(acuerdos: Acuerdo[], hoyISO: string): Promise<void> {
  const writeXlsxFile = (await import('write-excel-file')).default;
  const encabezado = COLUMNAS_ACUERDO.map((h) => H(h));
  const filas = acuerdos.map((a) => filaAcuerdo(a).map((v) => C(v)));
  const columns = COLUMNAS_ACUERDO.map((h) =>
    h === 'Acción' || h === 'Observaciones' ? { width: 40 } : h === 'ID' ? { width: 6 } : { width: 20 },
  );
  await writeXlsxFile([encabezado, ...filas], {
    columns,
    fileName: nombreArchivo('acuerdos', hoyISO, 'xlsx'),
    sheet: 'Acuerdos',
  });
}

export async function descargarResumenXlsx(r: Resumen, hoyISO: string): Promise<void> {
  const writeXlsxFile = (await import('write-excel-file')).default;
  const { totales, porResponsable } = filasResumen(r);
  const data = [
    [H('Indicador'), H('Total')],
    ...totales.map(([k, v]) => [C(k), C(v)]),
    [C(''), C('')],
    [H('Responsable'), H('En proceso'), H('Vencidos'), H('Por vencer')],
    ...porResponsable.map((fila) => fila.map((v) => C(v))),
  ];
  await writeXlsxFile(data, {
    fileName: nombreArchivo('resumen', hoyISO, 'xlsx'),
    sheet: 'Resumen',
  });
}
```

> Nota: la forma exacta de las opciones (`fontWeight`, `backgroundColor`, `columns`, y si acepta matriz rectangular con filas de distinto largo) se confirma contra el README de `write-excel-file` v4 al implementar; si difiere, ajustar aquí manteniendo la firma pública. Si la matriz rectangular exige mismo número de columnas por fila, rellenar con `C('')`.

- [ ] **Step 2: typecheck + lint + build (verificar code-split)**

Run: `cd apps/web && npm run typecheck && npm run lint && npm run build`
Expected: verde; en la salida de `vite build`, `write-excel-file` aparece en un chunk propio (dynamic import) y el chunk de entrada sigue <350 KB gzip.

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/lib/exportarXlsx.ts
git commit -m "feat(web): generación XLSX (listado + resumen) con write-excel-file lazy

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Botón "Exportar" en la toolbar del Panel

**Files:**
- Modify: `apps/web/src/pages/Panel.tsx`

**Interfaces:**
- Consumes: `descargarAcuerdosXlsx` (Task 2); `hoyISO` de `../lib/fechas` (ya importado); el estado `lista` ya existente.
- Produces: UI; no exporta símbolos nuevos.

- [ ] **Step 1: Import + estado de "exportando"**

Agregar `import { descargarAcuerdosXlsx } from '../lib/exportarXlsx';` y, dentro de `Panel`, `const [exportando, setExportando] = useState(false);`. Handler:

```ts
  const exportar = async () => {
    setExportando(true);
    try {
      await descargarAcuerdosXlsx(lista, hoyISO());
    } catch {
      // el fallo de descarga no debe romper la vista; feedback mínimo
      alert('No se pudo generar el archivo. Intenta de nuevo.');
    } finally {
      setExportando(false);
    }
  };
```

> Si el proyecto ya expone un `Toast`/contexto de aviso reutilizable en el Panel, usarlo en lugar de `alert`. Verificar al implementar; `alert` es el fallback aceptable para un quick win.

- [ ] **Step 2: Botón en la toolbar**

Antes del botón "+ Nuevo acuerdo" (tras `toolbar__spacer`):

```tsx
        <button
          type="button"
          className="btn btn--ghost btn--md"
          onClick={exportar}
          disabled={exportando || lista.length === 0}
          title="Exportar el listado filtrado a Excel"
        >
          {exportando ? 'Exportando…' : `Exportar (${lista.length})`}
        </button>
```

(Usar la clase de botón secundario que ya exista en el CSS legacy — `btn--ghost`/`btn--secondary`; verificar cuál está definida y usar esa, sin inventar estilos, regla #11.)

- [ ] **Step 3: typecheck + lint + build**

Run: `cd apps/web && npm run typecheck && npm run lint && npm run build`
Expected: verde.

- [ ] **Step 4: Commit**

```bash
git add apps/web/src/pages/Panel.tsx
git commit -m "feat(web): botón Exportar (XLSX del listado filtrado) en el Panel

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Exportar el resumen (XLSX + PDF) desde ResumenModal

**Files:**
- Modify: `apps/web/src/components/ResumenModal.tsx`
- Modify: CSS de estilos (bloque `@media print`)

**Interfaces:**
- Consumes: `descargarResumenXlsx` (Task 2); `hoyISO` de `../lib/fechas`; `r` (Resumen) ya disponible en el modal.
- Produces: dos botones en el pie + un nodo imprimible marcado.

- [ ] **Step 1: Botones en el pie del modal**

En el pie de `ResumenModal` (tras el bloque de contenido), agregar:

```tsx
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', padding: '0 26px 20px' }}>
          <button type="button" className="btn btn--ghost btn--sm"
            disabled={!r} onClick={() => r && descargarResumenXlsx(r, hoyISO())}>
            Exportar XLSX
          </button>
          <button type="button" className="btn btn--ghost btn--sm"
            disabled={!r} onClick={() => window.print()}>
            Exportar PDF
          </button>
        </div>
```

(XLSX igual que en el Panel: envolver en try/catch con feedback mínimo.)

- [ ] **Step 2: Marcar el nodo imprimible**

Envolver el contenido del resumen (la tarjeta del modal, o un subconjunto: cabecera + totales + tabla por responsable) con `id="resumen-print"`. Añadir al pie un renglón de marca: "Panel de Acuerdos · Plan Juárez — Generado el {fmtL(hoyISO())}".

- [ ] **Step 3: Regla @media print**

En el CSS legacy (o en `styles/`), agregar una regla acotada que, al imprimir, oculte todo salvo el nodo del resumen:

```css
@media print {
  body * { visibility: hidden; }
  #resumen-print, #resumen-print * { visibility: visible; }
  #resumen-print { position: absolute; inset: 0; margin: 0; box-shadow: none; max-height: none; overflow: visible; }
  .modal__close, .btn { display: none !important; }
}
```

(Usa los tokens PJ ya presentes vía las variables `--pj-*`/`--purple`; no se define paleta nueva, regla #11.)

- [ ] **Step 4: typecheck + lint + build + tests**

Run: `cd apps/web && npm run typecheck && npm run lint && npm run build && npm test`
Expected: todo verde.

- [ ] **Step 5: Verificación end-to-end (skill `verify`)**

Con Vite + API arriba: en Recordatorios abrir el Resumen → "Exportar XLSX" descarga `resumen_<fecha>.xlsx` con totales + por responsable; "Exportar PDF" abre el diálogo de impresión mostrando SOLO la tarjeta del resumen con marca PJ (guardar como PDF). En el Panel: filtrar y "Exportar (N)" descarga `acuerdos_<fecha>.xlsx` con exactamente las N filas filtradas y las columnas de `COLUMNAS_ACUERDO`. Como coordinador: confirmar que el export solo trae su ámbito.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/ResumenModal.tsx apps/web/src/styles/*
git commit -m "feat(web): exportar resumen a XLSX y PDF (print-CSS con identidad PJ)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Cerrar backlog

**Files:**
- Modify: `docs/07-roadmap/08_backlog_mejoras.md`

- [ ] **Step 1:** Cambiar el estado de #1 de 🟨 a ✅ (tabla resumen §2 y detalle §1). Anotar librería elegida (write-excel-file) y enfoque (cliente; PDF por print-CSS).
- [ ] **Step 2: Commit**

```bash
git add docs/07-roadmap/08_backlog_mejoras.md
git commit -m "docs(backlog): quick win #1 (exportar XLSX/PDF) hecho

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- Helper puro `exportar.ts` (`COLUMNAS_ACUERDO`/`filaAcuerdo`/`filasResumen`/`nombreArchivo`) (spec §3.1) → Task 1. ✅
- XLSX lazy `write-excel-file`, code-split, header PJ (spec §1.1, §3.2) → Task 2. ✅
- XLSX del listado filtrado desde la toolbar, deshabilitado si vacío (spec §2.1, §3.4) → Task 3. ✅
- XLSX + PDF del resumen desde ResumenModal (spec §2.2, §2.3, §3.3, §3.4) → Task 4. ✅
- PDF con identidad PJ vía print-CSS, sin dependencia (spec §1.1, §3.3) → Task 4 Steps 2–3. ✅
- Respeta ámbito del actor por construcción (spec §1) → Global Constraints (se exporta `lista`/`r` ya acotados). ✅
- npm audit limpio + bundle <350 KB (spec §4) → Task 1 Step 1, Task 2 Step 2. ✅
- Tests del helper puro (spec §4) → Task 1 Step 2. ✅
- Backlog a ✅ (higiene) → Task 5. ✅

**Placeholder scan:** las dos notas (`> Nota`) marcan puntos a confirmar contra el README de la librería y el CSS de botones existente al implementar; no son TODOs de alcance, son verificaciones de API/clase. El resto de steps llevan su bloque real.

**Type consistency:** `filaAcuerdo(a: Acuerdo): string[]` y `COLUMNAS_ACUERDO` (Task 1) consumidos con la misma forma en Task 2. `descargarAcuerdosXlsx(acuerdos: Acuerdo[], hoyISO: string)` / `descargarResumenXlsx(r: Resumen, hoyISO: string)` idénticos en definición (Task 2) y uso (Tasks 3, 4). `Resumen`/`ResumenPorResponsable` según `types.ts` (campos `en_proceso`, `vencidos`, `por_vencer_7d`, `concluidos`, `por_responsable`).

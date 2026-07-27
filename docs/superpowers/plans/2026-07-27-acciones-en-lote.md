# Acciones en lote (reprogramar + reasignar) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Selección múltiple en la vista Tabla del Panel para reprogramar o reasignar responsable de varios acuerdos a la vez, reusando los endpoints congelados desde el cliente, tolerante a fallo parcial.

**Architecture:** Un helper **puro** `lib/loteAcciones.ts` (nota automática, clasificación de error, resumen y un **ejecutor con concurrencia acotada** `ejecutarLote`) testeable con vitest; `Panel.tsx` mantiene el estado de selección (`Set<number>`) y corre el lote llamando `registrarAvance`/`editarAcuerdo` por acuerdo; dos modales pequeños capturan la fecha nueva / el responsable destino. Cero cambios al contrato ni al backend.

**Tech Stack:** React 19 + TypeScript 5 + Vite 7 + TanStack Query 5 + vitest.

## Global Constraints

- **Client-side only:** cero cambios en `api.ts`, `api.real.ts`, backend o doc 05 (contrato CONGELADO, regla #3). Solo se reusan `registrarAvance(id, {descripcion, nueva_fecha})` y `editarAcuerdo(id, {responsable_id})`.
- **Policies por acuerdo:** no se pre-filtra por permiso en el cliente; cada llamada pasa por su Policy y se audita sola en el backend. Un `403` de un ítem **no aborta** los demás (criterio de aceptación #4).
- **Fecha en `America/Ciudad_Juarez`:** usar helpers de `lib/fechas` (`hoyISO`, `fmtL`); nada de `new Date()` crudo (regla #6).
- **Reprogramar ⇒ `nueva_fecha >= hoy`** (regla vencido→en_proceso): el input de fecha usa `min = hoyISO()`.
- **Escape de salida (regla #7):** React ya escapa; sin `dangerouslySetInnerHTML`.
- **Conversión 1:1 (regla #11):** reusar clases/tokens PJ existentes; no inventar paleta. Solo se añade la columna de checkbox, la barra de acciones y una marca de error de fila.
- **Marca:** copy en español, "Participa Juárez" si aplica (no "Plan Juárez").

## File Structure

- Create `apps/web/src/lib/loteAcciones.ts` — puro: tipos + `notaReprogramacion`, `clasificarError`, `resumenLote`, `ejecutarLote`.
- Create `apps/web/src/lib/__tests__/loteAcciones.test.ts` — tests del helper puro + ejecutor.
- Create `apps/web/src/components/ReprogramarLoteModal.tsx` — modal de fecha nueva.
- Create `apps/web/src/components/ReasignarLoteModal.tsx` — modal de responsable destino.
- Modify `apps/web/src/pages/Panel.tsx` — estado de selección, `correrLote`, barra de acciones, wiring de modales/invalidación; nuevas props a `VistaTabla`.
- Modify `VistaTabla` (dentro de `Panel.tsx`) — columna de checkbox (tabla ≥640px y cards <640px) + marca de fila fallida.
- Modify `apps/web/src/styles/legacy-demo.css` — barra de acciones y marca de error de fila con tokens PJ.
- Modify `docs/07-roadmap/08_backlog_mejoras.md` — cerrar #4.

---

## Task 1: Helper puro `loteAcciones.ts` (nota, clasificación, resumen, ejecutor) con tests

**Files:**
- Create: `apps/web/src/lib/loteAcciones.ts`
- Test: `apps/web/src/lib/__tests__/loteAcciones.test.ts`

**Interfaces:**
- Consumes: `fmtL` de `./fechas`; `statusError` de `../components/EstadoHelpers`.
- Produces:
  ```ts
  export type MotivoFallo = 'sin_permiso' | 'error';
  export interface ResultadoItem { id: number; ok: boolean; motivo?: MotivoFallo }
  export function notaReprogramacion(fechaISO: string): string;
  export function clasificarError(e: unknown): MotivoFallo;
  export function resumenLote(resultados: ResultadoItem[]): { ok: number; total: number; fallidos: ResultadoItem[] };
  export function ejecutarLote(
    ids: number[],
    accion: (id: number) => Promise<unknown>,
    opts?: { concurrencia?: number; clasificar?: (e: unknown) => MotivoFallo },
  ): Promise<ResultadoItem[]>;
  ```

- [ ] **Step 1: Escribir los tests que fallan**

Crear `apps/web/src/lib/__tests__/loteAcciones.test.ts`:

```ts
import { describe, expect, it, vi } from 'vitest';
import { clasificarError, ejecutarLote, notaReprogramacion, resumenLote } from '../loteAcciones';

describe('notaReprogramacion', () => {
  it('compone la nota con la fecha en formato largo', () => {
    expect(notaReprogramacion('2026-08-15')).toBe('Reprogramación en lote al 15 de agosto de 2026');
  });
});

describe('clasificarError', () => {
  it('403 → sin_permiso; otro → error', () => {
    expect(clasificarError({ status: 403, error: 'prohibido' })).toBe('sin_permiso');
    expect(clasificarError({ status: 500 })).toBe('error');
    expect(clasificarError(new Error('x'))).toBe('error');
  });
});

describe('resumenLote', () => {
  it('cuenta ok/total y separa los fallidos', () => {
    const r = resumenLote([
      { id: 1, ok: true },
      { id: 2, ok: false, motivo: 'sin_permiso' },
      { id: 3, ok: true },
    ]);
    expect(r).toEqual({
      ok: 2,
      total: 3,
      fallidos: [{ id: 2, ok: false, motivo: 'sin_permiso' }],
    });
  });
});

describe('ejecutarLote', () => {
  it('aplica la acción a cada id y preserva el orden de ids', async () => {
    const res = await ejecutarLote([3, 1, 2], async () => undefined);
    expect(res.map((r) => r.id)).toEqual([3, 1, 2]);
    expect(res.every((r) => r.ok)).toBe(true);
  });

  it('un rechazo no aborta el resto; clasifica el fallo por id', async () => {
    const accion = vi.fn(async (id: number) => {
      if (id === 2) throw { status: 403 };
      return undefined;
    });
    const res = await ejecutarLote([1, 2, 3], accion, { concurrencia: 1 });
    expect(accion).toHaveBeenCalledTimes(3);
    expect(res).toEqual([
      { id: 1, ok: true },
      { id: 2, ok: false, motivo: 'sin_permiso' },
      { id: 3, ok: true },
    ]);
  });

  it('respeta el límite de concurrencia', async () => {
    let enVuelo = 0;
    let maxEnVuelo = 0;
    const liberar: Array<() => void> = [];
    const accion = (_id: number) =>
      new Promise<void>((resolve) => {
        enVuelo += 1;
        maxEnVuelo = Math.max(maxEnVuelo, enVuelo);
        liberar.push(() => {
          enVuelo -= 1;
          resolve();
        });
      });
    const p = ejecutarLote([1, 2, 3, 4, 5], accion, { concurrencia: 2 });
    // Deja que arranquen los primeros workers antes de liberar.
    await Promise.resolve();
    await Promise.resolve();
    while (liberar.length) liberar.shift()!();
    await p;
    expect(maxEnVuelo).toBeLessThanOrEqual(2);
  });
});
```

- [ ] **Step 2: Correr los tests para verificar que fallan**

Run: `cd apps/web && npx vitest run src/lib/__tests__/loteAcciones.test.ts`
Expected: FAIL — `../loteAcciones` no existe.

- [ ] **Step 3: Implementar el helper**

Crear `apps/web/src/lib/loteAcciones.ts`:

```ts
import { fmtL } from './fechas';
import { statusError } from '../components/EstadoHelpers';

export type MotivoFallo = 'sin_permiso' | 'error';
export interface ResultadoItem {
  id: number;
  ok: boolean;
  motivo?: MotivoFallo;
}

/** "Reprogramación en lote al 15 de agosto de 2026" */
export function notaReprogramacion(fechaISO: string): string {
  return `Reprogramación en lote al ${fmtL(fechaISO)}`;
}

/** 403 → sin permiso; cualquier otro error → error genérico. */
export function clasificarError(e: unknown): MotivoFallo {
  return statusError(e) === 403 ? 'sin_permiso' : 'error';
}

export function resumenLote(resultados: ResultadoItem[]): {
  ok: number;
  total: number;
  fallidos: ResultadoItem[];
} {
  const fallidos = resultados.filter((r) => !r.ok);
  return { ok: resultados.length - fallidos.length, total: resultados.length, fallidos };
}

/**
 * Ejecuta `accion` por cada id con concurrencia acotada. Un rechazo NO aborta
 * el resto: se captura por id y se clasifica. El resultado preserva el orden de `ids`.
 */
export async function ejecutarLote(
  ids: number[],
  accion: (id: number) => Promise<unknown>,
  opts: { concurrencia?: number; clasificar?: (e: unknown) => MotivoFallo } = {},
): Promise<ResultadoItem[]> {
  const concurrencia = Math.max(1, opts.concurrencia ?? 5);
  const clasificar = opts.clasificar ?? clasificarError;
  const porId = new Map<number, ResultadoItem>();
  let cursor = 0;
  const worker = async (): Promise<void> => {
    while (cursor < ids.length) {
      const id = ids[cursor++];
      try {
        await accion(id);
        porId.set(id, { id, ok: true });
      } catch (e) {
        porId.set(id, { id, ok: false, motivo: clasificar(e) });
      }
    }
  };
  await Promise.all(
    Array.from({ length: Math.min(concurrencia, ids.length) }, () => worker()),
  );
  return ids.map((id) => porId.get(id)!);
}
```

- [ ] **Step 4: Correr los tests para verificar que pasan**

Run: `cd apps/web && npx vitest run src/lib/__tests__/loteAcciones.test.ts`
Expected: PASS (todos).

- [ ] **Step 5: typecheck + lint**

Run: `cd apps/web && npm run typecheck && npm run lint`
Expected: sin errores.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/lib/loteAcciones.ts apps/web/src/lib/__tests__/loteAcciones.test.ts
git commit -m "feat(web): helper puro de acciones en lote (ejecutor con concurrencia + resumen)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Selección múltiple en `VistaTabla` + barra de acciones (sin ejecutar aún)

**Files:**
- Modify: `apps/web/src/pages/Panel.tsx` (estado + barra + props a `VistaTabla` + `VistaTabla`)
- Modify: `apps/web/src/styles/legacy-demo.css` (barra de acciones)

**Interfaces:**
- Consumes: `MotivoFallo` de `../lib/loteAcciones` (Task 1); `lista: Acuerdo[]` ya existente en `Panel`.
- Produces: en `Panel`, el estado `seleccion: Set<number>`, `fallidos: Map<number, MotivoFallo>`, `idsAccionables: number[]`; en `VistaTabla`, las props `seleccion`, `fallidos`, `onToggle`, `onToggleTodos`.

- [ ] **Step 1: Estado de selección en `Panel`**

En `Panel.tsx`, junto a los demás `useState` (tras `const [mesCal, setMesCal] = useState(mesActualISO());`), agregar:

```tsx
  const [seleccion, setSeleccion] = useState<Set<number>>(new Set());
  const [fallidos, setFallidos] = useState<Map<number, MotivoFallo>>(new Map());
```

Y el import (junto a los otros de `../lib`):

```tsx
import type { MotivoFallo } from '../lib/loteAcciones';
```

Tras el `useMemo` de `lista`, derivar los ids accionables (intersección con la lista filtrada actual) y helpers de selección:

```tsx
  const idsLista = useMemo(() => lista.map((a) => a.id), [lista]);
  const idsAccionables = useMemo(
    () => idsLista.filter((id) => seleccion.has(id)),
    [idsLista, seleccion],
  );

  const alternarUno = (id: number) =>
    setSeleccion((prev) => {
      const s = new Set(prev);
      if (s.has(id)) s.delete(id);
      else s.add(id);
      return s;
    });

  const alternarTodos = (marcar: boolean) =>
    setSeleccion((prev) => {
      const s = new Set(prev);
      for (const id of idsLista) {
        if (marcar) s.add(id);
        else s.delete(id);
      }
      return s;
    });

  const limpiarSeleccion = () => {
    setSeleccion(new Set());
    setFallidos(new Map());
  };
```

- [ ] **Step 2: Barra de acciones en el render de `Panel`**

Localizar el bloque de la vista tabla (`{!vistaQ.isLoading && modo === 'tabla' && (`) e insertar, **justo antes** de `<VistaTabla ... />`, la barra (visible solo con selección y en modo tabla):

```tsx
        {idsAccionables.length > 0 && (
          <div className="lote-bar">
            <span className="lote-bar__count">{idsAccionables.length} seleccionados</span>
            <div className="lote-bar__spacer" />
            <button type="button" className="btn btn--ghost btn--sm" onClick={() => setModalLote('reprogramar')}>
              Reprogramar
            </button>
            <button type="button" className="btn btn--ghost btn--sm" onClick={() => setModalLote('reasignar')}>
              Reasignar
            </button>
            <button type="button" className="btn btn--ghost btn--sm" onClick={limpiarSeleccion}>
              Limpiar
            </button>
          </div>
        )}
```

Agregar el estado del modal junto a los demás `useState`:

```tsx
  const [modalLote, setModalLote] = useState<null | 'reprogramar' | 'reasignar'>(null);
```

(En esta tarea los botones solo abren un estado que aún no se consume; el wiring real llega en Tasks 3–4. Para que typecheck no marque "declarado y no usado", `modalLote` se lee en un `void modalLote;` temporal **o** — preferido — implementar ya el render condicional vacío al final del componente:)

```tsx
      {/* placeholder de modales de lote (se llena en Tasks 3–4) */}
      {modalLote !== null && null}
```

- [ ] **Step 3: Pasar props de selección a `VistaTabla`**

Cambiar la llamada:

```tsx
        <VistaTabla
          lista={lista}
          proxPorAcuerdo={proxPorAcuerdo}
          onAbrir={abrir}
          seleccion={seleccion}
          fallidos={fallidos}
          onToggle={alternarUno}
          onToggleTodos={alternarTodos}
        />
```

- [ ] **Step 4: Columna de checkbox en `VistaTabla`**

Actualizar la firma de `VistaTabla`:

```tsx
function VistaTabla({
  lista,
  proxPorAcuerdo,
  onAbrir,
  seleccion,
  fallidos,
  onToggle,
  onToggleTodos,
}: {
  lista: Acuerdo[];
  proxPorAcuerdo: Map<number, string>;
  onAbrir: (id: number) => void;
  seleccion: Set<number>;
  fallidos: Map<number, MotivoFallo>;
  onToggle: (id: number) => void;
  onToggleTodos: (marcar: boolean) => void;
}) {
  const pag = usePaginacion(lista);
  const todosMarcados = lista.length > 0 && lista.every((a) => seleccion.has(a.id));
  const algunoMarcado = lista.some((a) => seleccion.has(a.id));
```

En el `<thead><tr>`, agregar como **primera** celda un checkbox "todas" (con estado indeterminado):

```tsx
            <th style={{ width: 34 }}>
              <input
                type="checkbox"
                aria-label="Seleccionar todos"
                checked={todosMarcados}
                ref={(el) => {
                  if (el) el.indeterminate = algunoMarcado && !todosMarcados;
                }}
                onChange={(e) => onToggleTodos(e.target.checked)}
              />
            </th>
```

En el `<tr key={a.id} onClick={() => onAbrir(a.id)}>` de la tabla, agregar como **primera** celda (con `stopPropagation` para no abrir el Drawer) y marcar la fila fallida:

```tsx
              <tr
                key={a.id}
                onClick={() => onAbrir(a.id)}
                className={fallidos.has(a.id) ? 'row--fallo' : undefined}
              >
                <td onClick={(e) => e.stopPropagation()} style={{ cursor: 'default' }}>
                  <input
                    type="checkbox"
                    aria-label={`Seleccionar acuerdo ${a.id}`}
                    checked={seleccion.has(a.id)}
                    onChange={() => onToggle(a.id)}
                  />
                </td>
```

Actualizar el `colSpan` de la fila "No hay acuerdos…" de `7` a `8`.

- [ ] **Step 5: Checkbox también en las cards apiladas (<640px)**

En el bloque de cards (`<div ... sm:hidden>`), dentro del `.map` de `pag.pagina_items`, agregar en cada card un checkbox con `stopPropagation`, antes del contenido clicable:

```tsx
            <input
              type="checkbox"
              aria-label={`Seleccionar acuerdo ${a.id}`}
              checked={seleccion.has(a.id)}
              onClick={(e) => e.stopPropagation()}
              onChange={() => onToggle(a.id)}
              style={{ marginRight: 10 }}
            />
```

(Ubicarlo de modo que el `onClick` de abrir el detalle de la card no dispare al togglear; el `stopPropagation` lo garantiza.)

- [ ] **Step 6: CSS de la barra de acciones**

En `apps/web/src/styles/legacy-demo.css`, al final, agregar (tokens PJ existentes; sin paleta nueva):

```css
/* Acciones en lote (quick win #4) */
.lote-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 12px;
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface-2, var(--cn-surface2));
}
.lote-bar__count { font-size: 13px; font-weight: 600; }
.lote-bar__spacer { flex: 1; }
.acuerdos-table tr.row--fallo > td { background: color-mix(in srgb, var(--red) 12%, transparent); }
```

- [ ] **Step 7: typecheck + lint + build**

Run: `cd apps/web && npm run typecheck && npm run lint && npm run build`
Expected: verde. (La barra abre `modalLote` pero aún no renderiza modales.)

- [ ] **Step 8: Commit**

```bash
git add apps/web/src/pages/Panel.tsx apps/web/src/styles/legacy-demo.css
git commit -m "feat(web): selección múltiple en la tabla del Panel + barra de acciones en lote

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Reprogramar en lote (modal + ejecución + feedback)

**Files:**
- Create: `apps/web/src/components/ReprogramarLoteModal.tsx`
- Modify: `apps/web/src/pages/Panel.tsx` (handler `correrLote`, render del modal)

**Interfaces:**
- Consumes: `ejecutarLote`, `resumenLote`, `notaReprogramacion` de `../lib/loteAcciones` (Task 1); `api.registrarAvance` (contrato); `hoyISO`, `fmtL` de `../lib/fechas`; `useToast`; `useQueryClient`.
- Produces: `ReprogramarLoteModal` con props `{ n: number; onCancel: () => void; onConfirm: (fechaISO: string) => void; ocupado: boolean }`; en `Panel`, `correrLote(accion)`.

- [ ] **Step 1: Componente `ReprogramarLoteModal`**

Crear `apps/web/src/components/ReprogramarLoteModal.tsx`:

```tsx
import { useState } from 'react';
import { hoyISO } from '../lib/fechas';

interface Props {
  n: number;
  ocupado: boolean;
  onCancel: () => void;
  onConfirm: (fechaISO: string) => void;
}

export function ReprogramarLoteModal({ n, ocupado, onCancel, onConfirm }: Props) {
  const [fecha, setFecha] = useState('');
  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 130, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div className="overlay-backdrop" style={{ position: 'fixed' }} onClick={onCancel} />
      <div role="dialog" aria-modal="true" aria-label="Reprogramar en lote" className="modal-card" style={{ width: 420, maxWidth: '92vw' }}>
        <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)', fontWeight: 600 }}>
          Reprogramar {n} {n === 1 ? 'acuerdo' : 'acuerdos'}
        </div>
        <div style={{ padding: '18px 22px' }}>
          <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Nueva fecha compromiso</label>
          <input
            type="date"
            value={fecha}
            min={hoyISO()}
            onChange={(e) => setFecha(e.target.value)}
            style={{ width: '100%', padding: '10px 12px', fontSize: 13 }}
          />
          <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '10px 0 0' }}>
            Se registrará un avance con nota automática en cada acuerdo. Los vencidos volverán a “en proceso”.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', padding: '0 22px 18px' }}>
          <button type="button" className="btn btn--ghost btn--sm" onClick={onCancel} disabled={ocupado}>
            Cancelar
          </button>
          <button type="button" className="btn btn--accent btn--sm" disabled={ocupado || !fecha} onClick={() => onConfirm(fecha)}>
            {ocupado ? 'Reprogramando…' : 'Reprogramar'}
          </button>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Handler `correrLote` en `Panel`**

En `Panel.tsx`, agregar imports:

```tsx
import { useQueryClient } from '@tanstack/react-query';
import { ejecutarLote, resumenLote, notaReprogramacion } from '../lib/loteAcciones';
import { ReprogramarLoteModal } from '../components/ReprogramarLoteModal';
```

Agregar estado y cliente de queries (junto a los demás hooks):

```tsx
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const [ocupadoLote, setOcupadoLote] = useState(false);
```

(Si `useToast` ya está importado/usado por otra parte del componente, no dupliques la línea.)

Agregar el handler tras `limpiarSeleccion`:

```tsx
  const correrLote = async (accion: (id: number) => Promise<unknown>) => {
    const ids = idsAccionables;
    if (ids.length === 0) return;
    setOcupadoLote(true);
    try {
      const resultados = await ejecutarLote(ids, accion);
      const { ok, total, fallidos } = resumenLote(resultados);
      if (ok > 0) toast(`Listo: ${ok} de ${total} aplicados.`, 'success');
      if (fallidos.length > 0) {
        const sinPermiso = fallidos.filter((f) => f.motivo === 'sin_permiso').length;
        const errores = fallidos.length - sinPermiso;
        const partes = [sinPermiso ? `${sinPermiso} sin permiso` : '', errores ? `${errores} con error` : ''].filter(Boolean);
        toast(`${fallidos.length} no se pudieron: ${partes.join(', ')}.`, 'error');
      }
      setFallidos(new Map(fallidos.map((f) => [f.id, f.motivo!])));
      setSeleccion(new Set(fallidos.map((f) => f.id))); // conserva seleccionados solo los fallidos
      void queryClient.invalidateQueries({ queryKey: ['acuerdos'] });
      void queryClient.invalidateQueries({ queryKey: ['recordatorios'] });
    } finally {
      setOcupadoLote(false);
      setModalLote(null);
    }
  };
```

- [ ] **Step 3: Render del modal de reprogramar**

Reemplazar el placeholder `{modalLote !== null && null}` por:

```tsx
      {modalLote === 'reprogramar' && (
        <ReprogramarLoteModal
          n={idsAccionables.length}
          ocupado={ocupadoLote}
          onCancel={() => setModalLote(null)}
          onConfirm={(fecha) =>
            correrLote((id) => api.registrarAvance(id, { descripcion: notaReprogramacion(fecha), nueva_fecha: fecha }))
          }
        />
      )}
```

- [ ] **Step 4: typecheck + lint + build**

Run: `cd apps/web && npm run typecheck && npm run lint && npm run build`
Expected: verde.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/components/ReprogramarLoteModal.tsx apps/web/src/pages/Panel.tsx
git commit -m "feat(web): reprogramar en lote (modal + ejecutor tolerante a fallo parcial)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Reasignar responsable en lote (modal + wiring)

**Files:**
- Create: `apps/web/src/components/ReasignarLoteModal.tsx`
- Modify: `apps/web/src/pages/Panel.tsx` (render del modal)

**Interfaces:**
- Consumes: `api.listUsuarios()` (contrato) vía `useQuery`; `api.editarAcuerdo` (contrato); `correrLote` (Task 3).
- Produces: `ReasignarLoteModal` con props `{ n: number; ocupado: boolean; onCancel: () => void; onConfirm: (responsableId: number) => void }`.

- [ ] **Step 1: Componente `ReasignarLoteModal`**

Crear `apps/web/src/components/ReasignarLoteModal.tsx`:

```tsx
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../lib';

interface Props {
  n: number;
  ocupado: boolean;
  onCancel: () => void;
  onConfirm: (responsableId: number) => void;
}

export function ReasignarLoteModal({ n, ocupado, onCancel, onConfirm }: Props) {
  const usuariosQ = useQuery({ queryKey: ['usuarios'], queryFn: () => api.listUsuarios() });
  const [sel, setSel] = useState<number>(0);
  // Solo roles operativos pueden ser responsables (no 'pendiente').
  const operativos = (usuariosQ.data ?? []).filter((u) => u.rol !== 'pendiente' && u.activo);
  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 130, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div className="overlay-backdrop" style={{ position: 'fixed' }} onClick={onCancel} />
      <div role="dialog" aria-modal="true" aria-label="Reasignar responsable en lote" className="modal-card" style={{ width: 420, maxWidth: '92vw' }}>
        <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)', fontWeight: 600 }}>
          Reasignar {n} {n === 1 ? 'acuerdo' : 'acuerdos'}
        </div>
        <div style={{ padding: '18px 22px' }}>
          <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Nuevo responsable</label>
          <select
            value={sel}
            onChange={(e) => setSel(Number(e.target.value))}
            style={{ width: '100%', padding: '10px 12px', fontSize: 13 }}
          >
            <option value={0}>Selecciona…</option>
            {operativos.map((u) => (
              <option key={u.id} value={u.id}>{u.nombre}</option>
            ))}
          </select>
        </div>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', padding: '0 22px 18px' }}>
          <button type="button" className="btn btn--ghost btn--sm" onClick={onCancel} disabled={ocupado}>
            Cancelar
          </button>
          <button type="button" className="btn btn--accent btn--sm" disabled={ocupado || sel === 0} onClick={() => onConfirm(sel)}>
            {ocupado ? 'Reasignando…' : 'Reasignar'}
          </button>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Render del modal de reasignar en `Panel`**

Agregar el import:

```tsx
import { ReasignarLoteModal } from '../components/ReasignarLoteModal';
```

Después del bloque `{modalLote === 'reprogramar' && (...)}`, agregar:

```tsx
      {modalLote === 'reasignar' && (
        <ReasignarLoteModal
          n={idsAccionables.length}
          ocupado={ocupadoLote}
          onCancel={() => setModalLote(null)}
          onConfirm={(responsableId) =>
            correrLote((id) => api.editarAcuerdo(id, { responsable_id: responsableId }))
          }
        />
      )}
```

- [ ] **Step 3: typecheck + lint + build + tests**

Run: `cd apps/web && npm run typecheck && npm run lint && npm run build && npm test`
Expected: todo verde.

- [ ] **Step 4: Verificación end-to-end (skill `verify`)**

Con Vite + API arriba (web :5173, api :8080), en el Panel (vista Tabla):
1. Marcar varios acuerdos con los checkboxes; el checkbox "todas" marca/desmarca la lista filtrada; la selección persiste al cambiar de página.
2. **Reprogramar**: elegir una fecha ≥ hoy → los seleccionados cambian su `fecha_compromiso`; en la bitácora de cada uno aparece "Reprogramación en lote al …"; los vencidos vuelven a "en proceso".
3. **Reasignar**: elegir un responsable → cambia en todos los seleccionados.
4. **Fallo parcial** (como coordinador, seleccionando acuerdos de otra área): la acción se aplica a los permitidos, y los denegados quedan marcados (fila roja) con el toast "N sin permiso"; el resto no se aborta.
5. La tabla y las stat cards se refrescan al terminar.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/components/ReasignarLoteModal.tsx apps/web/src/pages/Panel.tsx
git commit -m "feat(web): reasignar responsable en lote (modal + wiring)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Cerrar backlog

**Files:**
- Modify: `docs/07-roadmap/08_backlog_mejoras.md`

- [ ] **Step 1:** Cambiar el estado de #4 de ⬜ a ✅ (tabla resumen §2 y detalle §4). Anotar alcance real (reprogramar + reasignar; concluir en lote diferido) y enfoque (cliente, reusando `registrarAvance`/`editarAcuerdo`; tolerante a fallo parcial).

- [ ] **Step 2: Commit**

```bash
git add docs/07-roadmap/08_backlog_mejoras.md
git commit -m "docs(backlog): quick win #4 (acciones en lote: reprogramar + reasignar) hecho

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- Selección múltiple en vista Tabla + "todas", persistente entre páginas (spec §3) → Task 2. ✅
- Reprogramar en lote con nota automática y `nueva_fecha >= hoy` (spec §4.1) → Tasks 1, 3. ✅
- Reasignar responsable en lote, selector de roles operativos (spec §4.2) → Task 4. ✅
- Ejecución cliente, concurrencia acotada, fallo parcial no aborta, feedback por ítem + resumen (spec §5) → Tasks 1 (`ejecutarLote`/`resumenLote`), 3 (`correrLote`, toasts, filas fallidas). ✅
- Invalidación de queries al terminar (spec §5) → Task 3 Step 2. ✅
- Helper puro testeable (spec §6) → Task 1. ✅
- Cero cambios al contrato/backend (spec §1, Global Constraints) → solo se reusan `registrarAvance`/`editarAcuerdo`; verificado en cada `build`. ✅
- Backlog a ✅ (higiene) → Task 5. ✅
- Concluir en lote **fuera de v1** (spec §2) → ninguna tarea lo implementa (correcto). ✅

**Placeholder scan:** el único "placeholder" es el `{modalLote !== null && null}` de Task 2 Step 2, reemplazado explícitamente en Task 3 Step 3 — es un puente entre tareas, no un TODO abierto. Sin otros marcadores.

**Type consistency:** `MotivoFallo`/`ResultadoItem` (Task 1) usados igual en Tasks 2–3. `ejecutarLote(ids, accion, {concurrencia, clasificar})` y `resumenLote(...)` con las firmas de Task 1 consumidas en Task 3. `correrLote(accion: (id: number) => Promise<unknown>)` (Task 3) reutilizado por Task 4. Props de `VistaTabla` (`seleccion`/`fallidos`/`onToggle`/`onToggleTodos`) definidas en Task 2 Step 4 y provistas en Step 3. `api.registrarAvance(id, {descripcion, nueva_fecha})` y `api.editarAcuerdo(id, {responsable_id})` según el contrato congelado (`types.ts`: `NuevoAvance`, `EdicionAcuerdo`).

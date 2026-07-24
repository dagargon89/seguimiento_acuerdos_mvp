# Filtro por área + rango de fechas en el Panel — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar filtros de área y rango de fechas (sobre fecha compromiso) al Panel, client-side, consistentes con los filtros de responsable/búsqueda existentes.

**Architecture:** Extraer el filtrado inline del Panel a un helper puro testeable `filtrarAcuerdos(acuerdos, criterios)` en `lib/`, ampliarlo con área y rango de fechas, y conectar en `Panel.tsx` tres estados nuevos + dos controles de toolbar (Select de área, dos inputs de fecha). Sin cambios de backend ni de contrato.

**Tech Stack:** React 19 + TypeScript 5 + Vite 7 + TanStack Query 5 + vitest.

## Global Constraints

- **Client-side only:** cero cambios en `FiltrosAcuerdos`, `api.ts`, `api.real.ts`, backend o doc 05. Los filtros operan sobre los acuerdos ya cargados (`todos`, ≤200).
- **Rango de fechas sobre `fecha_compromiso`**, comparación de strings ISO `YYYY-MM-DD` (orden lexicográfico = cronológico), **inclusivo** en ambos extremos; extremo vacío = sin límite.
- **Conversión 1:1 del demo (regla #11):** solo se agregan los controles nuevos; no se altera el layout aprobado. La clase `.toolbar` ya tiene `flex-wrap: wrap` (no tocar CSS).
- **Marca:** "Participa Juárez", nunca "Plan Juárez".
- **Sin `dangerouslySetInnerHTML`** (regla #7).

## File Structure

- Create `apps/web/src/lib/filtrosPanel.ts` — helper puro `filtrarAcuerdos` + tipo `CriteriosFiltro`. Una sola responsabilidad: filtrado en memoria de acuerdos.
- Create `apps/web/src/lib/__tests__/filtrosPanel.test.ts` — tests unitarios del helper.
- Modify `apps/web/src/pages/Panel.tsx` — estado, toolbar, uso del helper, prop de área al Calendario.

---

## Task 1: Helper puro `filtrarAcuerdos` + tests

**Files:**
- Create: `apps/web/src/lib/filtrosPanel.ts`
- Test: `apps/web/src/lib/__tests__/filtrosPanel.test.ts`

**Interfaces:**
- Consumes: `Acuerdo` de `./types` (campos usados: `area.id`, `responsable.id`, `responsable.nombre`, `tema`, `accion`, `fecha_compromiso`).
- Produces:
  ```ts
  export interface CriteriosFiltro {
    area: number;        // 0 = todas
    responsable: number; // 0 = todos
    q: string;           // texto libre, case-insensitive; '' = sin filtro
    desde: string;       // ISO YYYY-MM-DD o '' (sin límite inferior)
    hasta: string;       // ISO YYYY-MM-DD o '' (sin límite superior)
  }
  export function filtrarAcuerdos(acuerdos: Acuerdo[], c: CriteriosFiltro): Acuerdo[];
  ```

- [ ] **Step 1: Escribir los tests que fallan**

Crear `apps/web/src/lib/__tests__/filtrosPanel.test.ts`. Usa un factory mínimo que construye solo los campos que el helper lee (el resto se completa con `as unknown as Acuerdo` para no acoplarse a la forma completa del tipo).

```ts
import { describe, expect, it } from 'vitest';
import type { Acuerdo } from '../types';
import { filtrarAcuerdos } from '../filtrosPanel';

function mk(over: {
  id: number; areaId: number; respId: number; respNombre: string;
  tema?: string; accion?: string; fecha: string;
}): Acuerdo {
  return {
    id: over.id,
    area: { id: over.areaId, nombre: `Área ${over.areaId}` },
    responsable: { id: over.respId, nombre: over.respNombre, email: '', avatar_color: null },
    tema: over.tema ?? null,
    accion: over.accion ?? 'Acción',
    fecha_compromiso: over.fecha,
  } as unknown as Acuerdo;
}

const SIN_FILTRO = { area: 0, responsable: 0, q: '', desde: '', hasta: '' };

const base: Acuerdo[] = [
  mk({ id: 1, areaId: 1, respId: 10, respNombre: 'Ana',   tema: 'Presupuesto', fecha: '2026-07-05' }),
  mk({ id: 2, areaId: 2, respId: 11, respNombre: 'Beto',  accion: 'Revisar obra', fecha: '2026-07-15' }),
  mk({ id: 3, areaId: 1, respId: 11, respNombre: 'Beto',  tema: 'Informe', fecha: '2026-07-25' }),
];

describe('filtrarAcuerdos', () => {
  it('sin criterios devuelve todos', () => {
    expect(filtrarAcuerdos(base, SIN_FILTRO).map((a) => a.id)).toEqual([1, 2, 3]);
  });

  it('filtra por área', () => {
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, area: 1 }).map((a) => a.id)).toEqual([1, 3]);
  });

  it('filtra por responsable', () => {
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, responsable: 11 }).map((a) => a.id)).toEqual([2, 3]);
  });

  it('filtra por texto en tema, acción o responsable (case-insensitive)', () => {
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, q: 'obra' }).map((a) => a.id)).toEqual([2]);
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, q: 'ANA' }).map((a) => a.id)).toEqual([1]);
  });

  it('filtra por rango de fechas, inclusivo en ambos extremos', () => {
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, desde: '2026-07-15', hasta: '2026-07-25' }).map((a) => a.id)).toEqual([2, 3]);
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, desde: '2026-07-15' }).map((a) => a.id)).toEqual([2, 3]);
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, hasta: '2026-07-15' }).map((a) => a.id)).toEqual([1, 2]);
  });

  it('combina área + rango + responsable + texto', () => {
    const r = filtrarAcuerdos(base, { area: 1, responsable: 11, q: 'informe', desde: '2026-07-20', hasta: '' });
    expect(r.map((a) => a.id)).toEqual([3]);
  });
});
```

- [ ] **Step 2: Correr los tests para verificar que fallan**

Run: `cd apps/web && npx vitest run src/lib/__tests__/filtrosPanel.test.ts`
Expected: FAIL — `filtrarAcuerdos` no existe (módulo no encontrado).

- [ ] **Step 3: Implementar el helper**

Crear `apps/web/src/lib/filtrosPanel.ts`:

```ts
import type { Acuerdo } from './types';

/** Criterios de filtrado client-side del Panel (todos opcionales vía valor vacío/0). */
export interface CriteriosFiltro {
  area: number;        // 0 = todas
  responsable: number; // 0 = todos
  q: string;           // texto libre, case-insensitive; '' = sin filtro
  desde: string;       // ISO YYYY-MM-DD o '' (sin límite inferior)
  hasta: string;       // ISO YYYY-MM-DD o '' (sin límite superior)
}

/**
 * Filtra en memoria los acuerdos ya cargados en el Panel. El rango opera sobre
 * `fecha_compromiso` (ISO YYYY-MM-DD, comparación lexicográfica = cronológica),
 * inclusivo en ambos extremos. No hace red — es lógica pura y testeable.
 */
export function filtrarAcuerdos(acuerdos: Acuerdo[], c: CriteriosFiltro): Acuerdo[] {
  const q = c.q.trim().toLowerCase();
  return acuerdos.filter((a) => {
    if (c.area && a.area.id !== c.area) return false;
    if (c.responsable && a.responsable.id !== c.responsable) return false;
    if (c.desde && a.fecha_compromiso < c.desde) return false;
    if (c.hasta && a.fecha_compromiso > c.hasta) return false;
    if (q && !`${a.tema ?? ''} ${a.accion} ${a.responsable.nombre}`.toLowerCase().includes(q)) return false;
    return true;
  });
}
```

- [ ] **Step 4: Correr los tests para verificar que pasan**

Run: `cd apps/web && npx vitest run src/lib/__tests__/filtrosPanel.test.ts`
Expected: PASS (6 tests).

- [ ] **Step 5: Verificar typecheck y lint**

Run: `cd apps/web && npm run typecheck && npm run lint`
Expected: sin errores.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/lib/filtrosPanel.ts apps/web/src/lib/__tests__/filtrosPanel.test.ts
git commit -m "feat(web): helper puro filtrarAcuerdos con área y rango de fechas

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Integrar los filtros en el Panel

**Files:**
- Modify: `apps/web/src/pages/Panel.tsx`

**Interfaces:**
- Consumes: `filtrarAcuerdos` / `CriteriosFiltro` de la Task 1 (import desde `../lib/filtrosPanel`); el componente `Select` ya usado en el archivo; `Acuerdo`.
- Produces: UI final; no exporta símbolos nuevos.

- [ ] **Step 1: Importar el helper**

Al inicio de `apps/web/src/pages/Panel.tsx`, agregar:

```ts
import { filtrarAcuerdos } from '../lib/filtrosPanel';
```

- [ ] **Step 2: Agregar estado local**

Junto a los estados existentes (`filtroResp`, ~línea 42), agregar:

```ts
  const [filtroArea, setFiltroArea] = useState<number>(0);
  const [fDesde, setFDesde] = useState('');
  const [fHasta, setFHasta] = useState('');
```

- [ ] **Step 3: Reemplazar el filtrado inline por el helper**

Sustituir el `useMemo lista` actual (que filtra responsable + búsqueda inline) por:

```ts
  const lista = useMemo(
    () => filtrarAcuerdos(todos, { area: filtroArea, responsable: filtroResp, q: busqueda, desde: fDesde, hasta: fHasta }),
    [todos, filtroArea, filtroResp, busqueda, fDesde, fHasta],
  );
```

- [ ] **Step 4: Derivar la lista de áreas (patrón `responsables`)**

Junto al `useMemo responsables` (~línea 94), agregar:

```ts
  const areas = useMemo(() => {
    const m = new Map<number, string>();
    for (const a of todos) m.set(a.area.id, a.area.nombre);
    return [...m.entries()].sort((p, q) => p[1].localeCompare(q[1]));
  }, [todos]);
```

- [ ] **Step 5: Agregar el Select de área y los inputs de fecha a la toolbar**

En el bloque `.toolbar`, después del Select de responsable y antes del `toolbar__spacer`, agregar el Select de área (mismo patrón que responsable):

```tsx
        <Select
          variante="toolbar"
          ariaLabel="Filtrar por área"
          value={String(filtroArea)}
          onChange={(v) => setFiltroArea(Number(v))}
          opciones={[
            { value: '0', label: 'Área: todas' },
            ...areas.map(([id, nombre]) => ({ value: String(id), label: nombre })),
          ]}
        />
        <input
          className="input"
          type="date"
          aria-label="Fecha compromiso desde"
          value={fDesde}
          onChange={(e) => setFDesde(e.target.value)}
          style={{ width: 'auto', padding: '10px 12px', fontSize: 13 }}
        />
        <input
          className="input"
          type="date"
          aria-label="Fecha compromiso hasta"
          value={fHasta}
          onChange={(e) => setFHasta(e.target.value)}
          style={{ width: 'auto', padding: '10px 12px', fontSize: 13 }}
        />
```

(La toolbar ya hace `flex-wrap`, así que estos controles fluyen a una segunda fila cuando no caben; no se toca CSS.)

- [ ] **Step 6: Pasar el filtro de área al Calendario**

En el JSX `<VistaCalendario ... />` (~línea 187), agregar la prop `filtroArea={filtroArea}` junto a `filtroResp`.

Luego, en la definición de `VistaCalendario` (~línea 752):
1. Agregar `filtroArea` a los props destructurados y al tipo (`filtroArea: number;`).
2. En el `useMemo porDia`, agregar la condición de área al filtro existente:

```ts
      const xs = dia.acuerdos.filter(
        (a) =>
          (!filtroArea || a.area.id === filtroArea) &&
          (!filtroResp || a.responsable.id === filtroResp) &&
          (!q || `${a.tema ?? ''} ${a.accion} ${a.responsable.nombre}`.toLowerCase().includes(q)),
      );
```

3. Agregar `filtroArea` al array de dependencias del `useMemo porDia`.

- [ ] **Step 7: Verificar typecheck, lint, build y tests**

Run: `cd apps/web && npm run typecheck && npm run lint && npm run build && npm test`
Expected: todo en verde (16 tests previos + 6 nuevos = 22).

- [ ] **Step 8: Verificación end-to-end (skill `verify`)**

Con los servicios corriendo (Vite :5173, API :8089), en el Panel: seleccionar un área y confirmar que la tabla/kanban y el contador "Mostrando X de Y" se reducen; fijar un rango de fechas y confirmar el recorte por fecha compromiso; combinar con estado/responsable/búsqueda; abrir el Calendario y confirmar que respeta el filtro de área. Vaciar los filtros y confirmar que se restauran todos los acuerdos.

- [ ] **Step 9: Commit**

```bash
git add apps/web/src/pages/Panel.tsx
git commit -m "feat(web): filtros de área y rango de fechas en el Panel

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- Helper puro `filtrarAcuerdos` + `CriteriosFiltro` (spec §3.1) → Task 1. ✅
- Filtro por área (Select derivado de `todos`) (spec §2, §3.3) → Task 2 Steps 4–5. ✅
- Rango de fechas sobre `fecha_compromiso`, inclusivo, extremos opcionales (spec §2, §3.1) → Task 1 Step 3 + tests Step 1; Task 2 Step 5. ✅
- Contador refleja filtros (spec §3.4) → automático vía `lista` (Task 2 Step 3). ✅
- Calendario respeta área, no rango (spec §3.4) → Task 2 Step 6. ✅
- Cero backend/contrato (spec §1) → Global Constraints; ningún step toca API/types/doc 05. ✅
- Tests (spec §4) → Task 1 Step 1 (6 casos: área, responsable, texto, rango inclusivo, extremos, combinación). ✅

**Placeholder scan:** sin TBD/TODO; cada step de código lleva su bloque real.

**Type consistency:** `CriteriosFiltro` (Task 1) = objeto pasado en Task 2 Step 3 (`{area, responsable, q, desde, hasta}`, mismos nombres/tipos). `filtrarAcuerdos(acuerdos: Acuerdo[], c: CriteriosFiltro): Acuerdo[]` idéntico en definición (Task 1) y uso (Task 2). `filtroArea`/`fDesde`/`fHasta` declarados (Step 2) y usados (Steps 3, 5, 6) coherentemente.

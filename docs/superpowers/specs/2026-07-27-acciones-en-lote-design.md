# Diseño — Quick win #4: Acciones en lote (reprogramar + reasignar)

| Campo | Valor |
|---|---|
| Documento | Spec de diseño — Selección múltiple en el Panel + acciones en lote |
| Fecha | 2026-07-27 |
| Backlog | doc 08 §4 (quick win #4), orden sugerido §6 |
| Depende de | Panel.tsx (vista Tabla + `lista` filtrada), contrato congelado `editarAcuerdo`/`registrarAvance`, `listUsuarios()`, `useToast` |
| Estado | Diseño aprobado — pendiente de plan de implementación |
| Decisiones de Dirección | Alcance v1: **reprogramar + reasignar** (concluir fuera). Enfoque: **cliente, reusando endpoints congelados**. Nota de reprogramación: **automática**. (respondidas 2026-07-27) |

## 1. Contexto y decisión de enfoque

Operar acuerdo por acuerdo es lento en reuniones de revisión. El backlog (§4) pide
**selección múltiple en la tabla del Panel** para **reprogramar / reasignar** (y concluir)
en lote, respetando las Policies por acuerdo, con auditoría por ítem y sin que una acción
denegada aborte el resto.

**Decisión de alcance (aprobada): v1 = reprogramar + reasignar.** Se deja **concluir fuera
de v1**: la conclusión exige un checklist de validación individual (regla no negociable #4);
concluir en lote rompería esa validación o exigiría rediseñar la regla. Queda para una
iteración posterior con diseño propio.

**Decisión de enfoque (aprobada): 100% cliente, reusando endpoints congelados.** El front
itera la selección y llama a los métodos existentes del contrato **una vez por acuerdo**:

- **Reprogramar** → `registrarAvance(id, { descripcion, nueva_fecha })` (la reprogramación
  es un avance con `nueva_fecha`; regresa a `en_proceso` si `nueva_fecha >= hoy`).
- **Reasignar** → `editarAcuerdo(id, { responsable_id })`.

Cada llamada **ya pasa por su Policy y se audita sola** en el backend, así que el criterio
"respetar Policies por acuerdo · todo auditado" se cumple por construcción. **No toca el
contrato congelado (regla #3) ni el backend.** Consistente con #1/#2/#3 (todo client-side).

Se descartó el **endpoint batch** (`POST /acuerdos/lote-accion` transaccional): cambia el
contrato CONGELADO (ADR + doc 05 en la misma sesión), toca Services/tests y obliga a definir
semántica de fallo parcial en el servidor — trabajo desproporcionado para un quick win, sin
beneficio al volumen actual (≤200 filas por vista).

## 2. Alcance

**Incluye (v1):**
- Selección múltiple en la **vista Tabla** del Panel (checkbox por fila + "todas").
- Barra de acciones en lote: **Reprogramar** y **Reasignar responsable**.
- Ejecución cliente con concurrencia acotada, tolerante a fallo parcial, con feedback por ítem.

**Excluye (fuera de v1 — YAGNI):**
- **Concluir en lote** (requiere checklist por acuerdo; diseño aparte).
- "Deshacer" el lote.
- Selección fuera de la vista Tabla (Tarjetas / Por reunión / Cronograma / Calendario).
- Endpoint batch en backend.

## 3. Selección (UX)

Solo activa en `modo === 'tabla'`.

- **Estado en `Panel`:** `seleccion: Set<number>` de `acuerdo.id`. **Persiste entre páginas**
  de la paginación (valor principal en una revisión larga) y entre cambios de filtro. Al
  ejecutar una acción, solo actúan los ids que **siguen presentes en `lista`** (la selección
  se intersecta con la lista filtrada actual); los ids que ya no están en `lista` se ignoran.
- **Columna checkbox** al inicio de cada fila de `VistaTabla`, con `onClick`/`onChange`
  usando `stopPropagation` para **no abrir el Drawer** (la fila entera es clicable hoy).
- **Checkbox en el encabezado** = seleccionar / limpiar **todos los de la `lista` filtrada
  actual** (no solo la página visible). Estado indeterminado cuando hay selección parcial.
- **Barra de acciones** (aparece con ≥1 seleccionado, fija sobre la tabla): texto
  *"N seleccionados"* + botones **Reprogramar**, **Reasignar** y **Limpiar selección**.

## 4. Flujos de acción

Ambos abren un modal pequeño (reusar patrón de modal existente / tokens PJ, regla #11).

### 4.1 Reprogramar
- Campo **fecha** (`<input type="date">`) para `nueva_fecha`, con `min = hoyISO()` (regla:
  reprogramar con `nueva_fecha >= hoy` regresa el acuerdo a `en_proceso`).
- **Nota automática** para la `descripcion` del avance:
  `"Reprogramación en lote al {fmtL(nueva_fecha)}"`. El usuario no escribe nota (decisión
  aprobada). La nota queda visible en la bitácora de cada acuerdo (quick win #3).
- Al confirmar: por cada id seleccionado → `registrarAvance(id, { descripcion, nueva_fecha })`.

### 4.2 Reasignar responsable
- **Selector** `<select>` de responsable, poblado con `listUsuarios()` (query TanStack;
  reusar el patrón de Captura). Se filtra a usuarios con rol operativo (no `pendiente`).
- Al confirmar: por cada id seleccionado → `editarAcuerdo(id, { responsable_id })`.

## 5. Ejecución, feedback y errores

- **Ejecutor** con concurrencia acotada (`CONCURRENCIA = 5`), sobre `Promise.allSettled`:
  una llamada rechazada (**403 sin permiso** u otro error) **no aborta las demás**. Cada
  resultado se captura por id: `{ id, ok: true }` o `{ id, ok: false, motivo }`.
- **Clasificación de error** por ítem con los helpers existentes (`statusError`/`codigoError`
  de `EstadoHelpers`): `403` → "sin permiso"; resto → "error".
- **Feedback:**
  - Resumen por **toast**: éxito *"Reprogramados 5 de 7"* (o el conteo correspondiente); si
    hubo fallos, un toast `error` con *"2 sin permiso / con error"*.
  - **Los ítems fallidos quedan seleccionados** y con una **marca de error** discreta en su
    fila (feedback por ítem, criterio de aceptación); los exitosos se quitan de la selección.
- Al terminar: **invalidar** la query de acuerdos (`['acuerdos', …]`) para refrescar la tabla
  (estados/fechas/responsable actualizados) y las stat cards.
- La **auditoría** de cada operación la produce el backend en cada llamada (no hay auditoría
  cliente).

## 6. Componentes y archivos

- **Create `apps/web/src/lib/loteAcciones.ts`** (puro + ejecutor, sin React):
  - `notaReprogramacion(fechaISO: string): string`
  - `type ResultadoItem = { id: number; ok: boolean; motivo?: 'sin_permiso' | 'error' }`
  - `resumenLote(resultados: ResultadoItem[]): { ok: number; total: number; fallidos: ResultadoItem[]; texto: string }`
  - `ejecutarLote(ids: number[], accion: (id: number) => Promise<unknown>, opts?: { concurrencia?: number }): Promise<ResultadoItem[]>`
    (clasifica el error con un mapeador inyectable para ser testeable sin depender de la red).
- **Create tests** `apps/web/src/lib/__tests__/loteAcciones.test.ts` (patrón `exportar.test.ts`):
  no aborta ante rechazo, agrega por id, respeta concurrencia, arma el resumen y la nota.
- **Modify `apps/web/src/pages/Panel.tsx`:** estado `seleccion`, handlers de acción, barra de
  acciones, wiring de invalidación; pasar `seleccion`/callbacks a `VistaTabla`.
- **Modify `VistaTabla` (en Panel.tsx):** columna de checkbox (encabezado con indeterminado +
  filas), sin romper el `onClick` de abrir Drawer.
- **Modales:** `ReprogramarLoteModal` y `ReasignarLoteModal` (componentes pequeños; pueden
  vivir en `components/` o inline en Panel según tamaño).
- **CSS:** barra de acciones y marca de error de fila con **tokens PJ existentes** en
  `styles/legacy-demo.css`; sin paleta nueva (regla #11).

## 7. Criterios de aceptación

1. En la vista Tabla puedo seleccionar varios acuerdos (por fila y "todas"), y la selección
   persiste al cambiar de página de la paginación.
2. Reprogramar el lote mueve la `fecha_compromiso` de todos los seleccionados a la fecha
   elegida y registra un avance con la nota automática; los vencidos con `nueva_fecha >= hoy`
   regresan a `en_proceso`.
3. Reasignar el lote cambia el responsable de todos los seleccionados al elegido.
4. Una acción **denegada (403) no aborta el resto**: los demás se aplican; los fallidos quedan
   marcados con su motivo (feedback por ítem).
5. Cada operación queda auditada (la produce el backend por llamada).
6. La tabla y las stat cards se refrescan al terminar.
7. `npm run typecheck && npm run lint && npm test && npm run build` en verde; sin cambios al
   contrato congelado ni al backend.

## 8. Riesgos y notas

- **Selección vs filtros:** al ejecutar se intersecta `seleccion ∩ ids(lista)`; ids fuera de
  la lista actual no se tocan (evita actuar sobre lo que el usuario ya no ve).
- **Volumen:** el Panel ya limita a `per_page: 200`; el ejecutor con concurrencia 5 mantiene
  la carga acotada. Sin paginación de servidor adicional en v1.
- **Reasignar y área:** `editarAcuerdo` valida permiso por Policy (dirección / coordinación
  del área / quien capturó, ADR-011); los no permitidos caen como "sin permiso" por ítem.

# Diseño — Quick win #2: Filtro por área + rango de fechas en el Panel

| Campo | Valor |
|---|---|
| Documento | Spec de diseño — Filtros de área y rango de fechas (Panel) |
| Fecha | 2026-07-24 |
| Backlog | doc 08 §2 (quick win #2), orden sugerido §6 |
| Depende de | Panel.tsx (patrón de filtrado client-side existente) |
| Estado | Diseño aprobado — pendiente de plan de implementación |

## 1. Contexto y decisión de enfoque

El Panel (`apps/web/src/pages/Panel.tsx`) **filtra en el cliente**: trae hasta 200
acuerdos por `estado` (`api.listAcuerdos({ estado, per_page: 200 })`) y luego filtra
**responsable** y **búsqueda** localmente en un `useMemo` (líneas ~77–80). El contador
"Mostrando X de Y" opera sobre esa lista filtrada. No usa los parámetros server-side
`desde`/`hasta` ni pagina en servidor.

**Decisión (aprobada):** agregar **área** y **rango de fechas** como filtros
**client-side**, consistentes con responsable/búsqueda. **Cero cambios de backend ni de
contrato** (`FiltrosAcuerdos`, doc 05, API intactos). Es el verdadero "quick win".

Se descartó el enfoque server-side (agregar `area_id` a `GET /acuerdos` y migrar el Panel
a paginación real) por ser bastante más trabajo y tocar el contrato (regla #3), sin
beneficio para el volumen actual (≤200 acuerdos por vista, límite ya vigente para todos
los filtros existentes).

## 2. Alcance

- Filtro por **área**: Select en la toolbar; opción "Área: todas" (default) + una por área
  presente en los acuerdos cargados.
- Filtro por **rango de fechas** sobre `fecha_compromiso`: dos inputs `type="date"`
  (desde / hasta), ambos opcionales; vacío = sin límite en ese extremo.
- Ambos combinan con los filtros existentes (estado, responsable, búsqueda) y con el
  contador y todas las vistas de lista.

## 3. Diseño

### 3.1 Helper puro de filtrado (nuevo, testeable)

Hoy el filtrado vive inline en el `useMemo lista`. Se extrae a un helper puro en
`apps/web/src/lib/` (p. ej. `filtrosPanel.ts`) para hacerlo testeable con vitest (hoy no
hay test de esa lógica):

```ts
export interface CriteriosFiltro {
  area: number;        // 0 = todas
  responsable: number; // 0 = todos
  q: string;           // texto libre (case-insensitive)
  desde: string;       // ISO YYYY-MM-DD o '' (sin límite inferior)
  hasta: string;       // ISO YYYY-MM-DD o '' (sin límite superior)
}

export function filtrarAcuerdos(acuerdos: Acuerdo[], c: CriteriosFiltro): Acuerdo[];
```

Reglas:
- `area` → `a.area.id === c.area` (omitido si `c.area === 0`).
- `responsable` → `a.responsable.id === c.responsable` (omitido si `0`).
- `q` → `` `${a.tema ?? ''} ${a.accion} ${a.responsable.nombre}`.toLowerCase().includes(q) ``
  (idéntico al actual; omitido si `q` vacío).
- `desde` → `a.fecha_compromiso >= c.desde` (omitido si vacío).
- `hasta` → `a.fecha_compromiso <= c.hasta` (omitido si vacío).
- Comparación de fechas como strings ISO `YYYY-MM-DD` (orden lexicográfico = cronológico).
  Rango **inclusivo** en ambos extremos.

El `useMemo lista` del Panel pasa a invocar `filtrarAcuerdos(todos, { area, responsable, q, desde, hasta })`.

### 3.2 Estado local (Panel.tsx)

Tres estados nuevos: `filtroArea: number` (0), `fDesde: string` (''), `fHasta: string` ('').

### 3.3 Toolbar

- **Select de área**: `areas` derivadas de `todos` (mismo patrón que `responsables`,
  línea 94): `Map<number, string>` id→nombre, ordenado por nombre. Opciones:
  `{ value: '0', label: 'Área: todas' }` + una por área. `ariaLabel="Filtrar por área"`.
- **Dos inputs `type="date"`** (desde / hasta) con `aria-label` ("Fecha compromiso desde"
  / "…hasta"), estilo consistente con `.toolbar .select` / `.input`.
- La clase `.toolbar` ya tiene `flex-wrap: wrap` + `row-gap` (legacy-demo.css:437, :255),
  así que los controles nuevos fluyen a una segunda fila sin tocar CSS. Regla #11: no se
  altera el diseño aprobado más allá de agregar estos controles.

### 3.4 Contador y vistas

- El contador "Mostrando {lista.length} de {todos.length}" refleja los nuevos filtros
  automáticamente (opera sobre `lista`).
- Tabla / Kanban / Reunión / Gantt reciben `lista` ya filtrada — sin cambios.
- **Calendario**: recibe `filtroArea` por consistencia con `filtroResp` (ya recibe
  responsable + búsqueda). El **rango de fechas no aplica** al calendario (es en sí la
  dimensión temporal); no se le pasa.

## 4. Verificación (DoD)

- Test unitario de `filtrarAcuerdos` (vitest): área sola; rango solo (límites inclusivos);
  responsable/q sin cambios de comportamiento; combinación área + rango + responsable + q;
  extremos vacíos = sin límite.
- `cd apps/web && npm run typecheck && npm run lint && npm run build && npm test` en verde.
- E2e visual: filtrar por área y por rango de fechas, confirmar que tabla/kanban/contador
  responden y combinan con estado/responsable/búsqueda; verificar que el Calendario respeta
  el filtro de área.

## 5. Fuera de alcance (YAGNI)

- Botón "limpiar filtros" (los Selects tienen "todas"; los date inputs se vacían).
- Filtrado server-side / `area_id` en `GET /acuerdos` (descartado en §1).
- Presets de fecha ("este mes", "próximos 30 días") — se eligió el rango explícito
  desde/hasta.
- Persistir filtros en URL o localStorage.

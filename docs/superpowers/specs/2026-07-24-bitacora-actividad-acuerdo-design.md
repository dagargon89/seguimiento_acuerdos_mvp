# Diseño — Quick win #3: Completar la Bitácora del acuerdo

| Campo | Valor |
|---|---|
| Documento | Spec de diseño — Bitácora unificada (actividad del acuerdo) |
| Fecha | 2026-07-24 |
| Backlog | doc 08 §3 (quick win #3), orden sugerido §6 |
| Depende de | 05_API (contrato), ADR-007 (visibilidad), ADR-012 (conclusión) |
| Estado | Diseño aprobado — pendiente de plan de implementación |

## 1. Contexto y problema

El Drawer de un acuerdo ya muestra una sección **Bitácora** (commit `717576f`) que
renderiza los `avances` con acento de color por tipo. Pero el historial completo de un
acuerdo está **disperso en dos tablas**:

- `avances` — tipos: `avance`, `reprogramacion`, `validacion` (= conclusión),
  `reapertura` (= reabrir). **Ya se muestran.**
- `auditoria` — acciones sobre la entidad `acuerdo`: `crear`, `editar`,
  `corresponsables`, `concluir`, `reabrir`, `eliminar`, `intento_*`. **No se muestran.**

**Hallazgo clave (define el diseño):** concluir/reabrir/reprogramar generan **un
`avance` Y un registro de `auditoria`** por el mismo hecho. Unir ambas tablas
ingenuamente **duplicaría** esos eventos.

## 2. Decisión de alcance

Sumar a la bitácora **solo** los eventos de auditoría que **no** tienen un avance
equivalente (los del ciclo de vida administrativo):

- ✅ `crear` — creación del acuerdo (ancla inicial de la línea de tiempo).
- ✅ `editar` — ediciones de campos (muestra **qué campos** cambiaron).
- ✅ `corresponsables` — cambios en la lista de corresponsables.

Se **excluyen**:

- ❌ `concluir` / `reabrir` — ya aparecen como avance `validacion` / `reapertura`
  (evita duplicados).
- ❌ `eliminar` — el acuerdo deja de existir (borrado duro, ADR-011); irrelevante en su
  propia bitácora.
- ❌ `intento_concluir` / `intento_reabrir` / `intento_eliminar` — son señal de
  seguridad para una futura vista de auditoría (doc 08 §8), no trazabilidad operativa
  del acuerdo. Fuera de alcance de este quick win.

## 3. Contrato / tipos (regla #3: `types.ts` + doc 05 en la misma sesión)

Tipo unificado nuevo y método en la interfaz `ApiClient`:

```ts
export interface EventoActividad {
  id: string;                    // "avance:12" | "auditoria:45" — key único cross-tabla
  fuente: 'avance' | 'auditoria';
  tipo: 'avance' | 'reprogramacion' | 'validacion' | 'reapertura'
      | 'crear' | 'editar' | 'corresponsables';
  usuario: UsuarioRef | null;    // null = acción del sistema
  descripcion: string;           // texto legible, generado en el backend
  nueva_fecha: string | null;    // solo reprogramación; null en el resto
  created_at: string;            // ISO, America/Ciudad_Juarez (regla #6)
}

// En ApiClient:
actividadAcuerdo(id: number): Promise<EventoActividad[]>;
```

- El `id` es compuesto (`fuente:pk`) porque los PKs de `avances` y `auditoria` pueden
  colisionar; sirve de `key` estable en React.
- El contrato se refleja **en la misma sesión** en `docs/05-api/05_*.md` (regla #3).

## 4. Backend — `GET /acuerdos/{id}/actividad`

- **Ruta:** `apps/api/app/Config/Routes.php` — `GET` + `OPTIONS`
  `acuerdos/(:num)/actividad` → `AcuerdosController::actividad/$1`.
- **Autorización:** reusa la Policy de **lectura** de acuerdo (ADR-007: lectura abierta a
  `direccion`/`coordinador`/`responsable`; `pendiente` → 403 `cuenta_pendiente`). Mismo
  gate que `show()`. 404 si el acuerdo no existe.
- **Construcción de la lista** (ordenada **desc por `created_at`**):
  1. `avances` del acuerdo — los 4 tipos, con su `descripcion`/`nueva_fecha`/`usuario`
     originales (un solo query).
  2. `auditoria` del acuerdo con `whereIn('accion', ['crear','editar','corresponsables'])`
     (un solo query — **cero N+1**, regla #9).
- **Descripción legible (generada en backend, no en el front):**
  - `crear` → "Creó el acuerdo".
  - `editar` → "Actualizó: " + campos de `detalle.cambios` mapeados a etiquetas
    (p. ej. `responsable_id` → "responsable", `fecha_compromiso` → "fecha compromiso").
    Campos desconocidos se muestran con su nombre crudo como fallback.
  - `corresponsables` → "Actualizó los corresponsables" (genérico; no se resuelven
    nombres para evitar queries extra — decisión explícita).
  - avances → conservan su `descripcion` tal cual.
- **`usuario`:** `UsuarioRef` (id, nombre); `null` cuando `auditoria.usuario_id` es null
  (acción del sistema).
- **Sin paginación:** un acuerdo tiene pocos eventos (YAGNI). Si en el futuro crece, se
  añade `page`/`per_page` sin romper el shape.

## 5. Front — `Drawer.tsx`

- La sección **Bitácora** deja de leer `sel.avances` y pasa a consumir
  `useQuery(['actividad', id], () => api.actividadAcuerdo(id))`.
- El mapa `TIPO_AVANCE_META` se extiende a `TIPO_EVENTO_META` incluyendo los 3 tipos
  nuevos, con color **neutro** (`var(--text-muted)`) para distinguir eventos
  administrativos de los de progreso (teal = avance/validación, ámbar = reprogramación,
  rojo = reapertura). Se conservan el borde izquierdo de 3px y el punto de color.
- Estados: **cargando** (skeleton/placeholder breve) y **vacío** ("Aún no hay actividad
  registrada.").
- El orden desc ya viene del backend; el `useMemo` defensivo de reordenado en cliente se
  mantiene sobre la lista de actividad.
- Sin `dangerouslySetInnerHTML`; React escapa (regla #7).

## 6. Verificación (DoD)

- **Backend:**
  - 200 con eventos unificados y ordenados desc; `crear`/`editar`/`corresponsables`
    presentes, `concluir`/`reabrir`/`intento_*`/`eliminar` **ausentes** (no duplica).
  - 403 `cuenta_pendiente` para rol `pendiente`; 404 para acuerdo inexistente.
  - Verificación de cero N+1 (un query por tabla).
- **Front:** `npm run typecheck && npm run lint && npm run build && npm test` en verde.
- **End-to-end:** abrir el Drawer contra la API real (servicios en 8089/5173) y confirmar
  que la bitácora muestra creación + ediciones + avances en orden cronológico, sin
  duplicar conclusiones.

## 7. Fuera de alcance

- Vista global de auditoría / actividad para Dirección (doc 08 §8).
- Mostrar valores viejo→nuevo en `editar` (la auditoría solo guarda nombres de campos).
- Resolver nombres de corresponsables en el texto del evento.
- Paginación de la bitácora.

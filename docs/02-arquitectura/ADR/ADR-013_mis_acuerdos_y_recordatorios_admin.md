# ADR-013 — Sección "Mis acuerdos" y Recordatorios como sección de administración

| Campo | Valor |
|---|---|
| Documento | ADR-013 |
| Versión | 1.0 |
| Fecha | 2026-07-14 |
| Estado | Aceptada |
| Depende de | doc 05 (contrato, v1.8), ADR-007 (visibilidad abierta) |
| Modifica | doc 05 v1.7 → v1.8 (filtro `mios`); navegación del frontend |

## 1. Contexto

La sección **Recordatorios** (`/recordatorios`) estaba en la navegación general y la veían los cuatro roles, pero es una vista de operación interna (esquema de avisos, historial de envíos). Al mismo tiempo, no existía ninguna vista donde una persona pudiera ver de un vistazo **sus** acuerdos: el Panel muestra todos (ADR-007) y su filtro por responsable no contempla la corresponsabilidad.

Dirección pidió reorganizar: Recordatorios pasa a ser sección de administración y su lugar en la navegación general lo ocupa una vista personal de acuerdos designados, **por responsabilidad y por corresponsabilidad**.

## 2. Decisión

### a) Filtro `mios=1` en `GET /acuerdos` (backend, contrato v1.8)

`AcuerdosController::index()` acepta el query param `mios`; **solo el literal `1`** activa el filtro (ausente/`0`/otro = listado normal). Restringe a los acuerdos donde el actor es:
- **responsable** (`acuerdos.responsable_id = actor.id`), o
- **corresponsable** (subconsulta a la pivote `acuerdo_corresponsables`, una sola query parametrizada — reglas №7 y №9).

Es un filtro de **lectura opt-in**: compone en AND con la visibilidad ADR-007 y con el resto de los filtros (`estado`, `q`, `desde`/`hasta`, paginación). No altera ninguna regla de escritura. En el contrato del cliente: `FiltrosAcuerdos.mios?: boolean`, serializado como `mios=1` por `api.real.ts`.

### b) Navegación del frontend

- **`/recordatorios` pasa al bloque "Administración"** del sidebar, visible para `direccion` y `coordinador` (mismo criterio que Checklist); guard de ruta que redirige a `/panel` a los demás roles. **Restricción solo en el front**: los endpoints `/recordatorios/*` y `GET /configuracion/recordatorios` conservan sus roles del doc 05 (`PUT /configuracion/recordatorios` sigue siendo solo Dirección).
- **Nace `/mis-acuerdos`** en la navegación general (página `MisAcuerdos.tsx`): consume `listAcuerdos({ mios: true, ... })`, distingue con un badge si el actor es responsable o corresponsable de cada acuerdo, oculta concluidos por defecto (RF-03.3) y abre el mismo `Drawer` de detalle del Panel.

## 3. Consecuencias

- **Sin migración de esquema** ni cambios en jobs/recordatorios.
- Doc 05 pasa a v1.8 (query `mios=1` + comentario en `listAcuerdos`); `api.ts`/`types.ts` actualizados en la misma sesión (regla №3).
- Pruebas: `FiltroMiosTest` (feature) cubre responsabilidad + corresponsabilidad, composición con `estado`/`q`, coordinador/dirección, y la regresión "sin `mios` el listado no cambia" (AU-01/ADR-007 intactos).
- El subtítulo de la página Recordatorios se ajusta (ya no aplica "cada persona ve únicamente los suyos" como descripción de la sección).
- **Reversible**: quitar la entrada/guard del nav y la página nueva; el filtro `mios` puede quedarse (opt-in, inocuo) o retirarse del contrato con otro ADR.

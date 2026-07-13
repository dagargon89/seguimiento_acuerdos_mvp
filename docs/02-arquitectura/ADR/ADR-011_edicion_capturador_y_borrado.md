# ADR-011 — Edición por el capturador y borrado de acuerdos (solo Dirección)

| Campo | Valor |
|---|---|
| Documento | ADR-011 |
| Versión | 1.0 |
| Fecha | 2026-07-13 |
| Estado | Aceptada |
| Depende de | doc 05 (contrato), ADR-007 (visibilidad), ADR-009/010 (calendario) |

## 1. Contexto

La edición estructural (`PATCH /acuerdos/{id}`) estaba limitada a Dirección y coordinación del área, y no existía forma de borrar un acuerdo. Dirección pidió: (a) que **quien capturó** un acuerdo pueda corregirlo — quien lo registró en la reunión es quien detecta el error de captura; (b) que **Dirección** pueda modificar cualquiera (ya era así) y **borrarlo** cuando haga falta.

## 2. Decisión

### a) Edición: se suma el capturador

`puedeEditarEstructura` (aplica a `PATCH /acuerdos/{id}` y `PUT .../corresponsables`) acepta ahora: Dirección, coordinación del área, **o `capturado_por_id === actor.id`** — sin importar el rol del capturador. Avances/conclusión no cambian.

Frontend: el Drawer muestra "Editar" a quien cumple la regla, con formulario inline (tema, acción, responsable, corresponsables, área, enlace, observaciones). La fecha compromiso se sigue cambiando SOLO vía avance con reprogramación, y el estado nunca se edita (reglas №4/№5 de CLAUDE.md intactas).

### b) Borrado: `DELETE /acuerdos/{id}` — solo Dirección (contrato v1.7)

- 403 auditado (`intento_eliminar`) para cualquier otro rol, mismo criterio que concluir/reabrir.
- Borrado definitivo en transacción; la cascada del DDL limpia avances, corresponsables, recordatorios y `google_sync`. La **auditoría se registra antes del delete con la ficha del acuerdo** (acción, estado, fecha) para conservar rastro de QUÉ se borró.
- El **evento de calendario se elimina** best-effort tras el commit (nuevo `CalendarSync::eliminarEventoPorId`; un fallo de la API deja evento huérfano registrado en log, jamás rompe la respuesta).
- Se envía un **aviso por correo** ("Acuerdo eliminado: …") al responsable y corresponsables, con la ficha del acuerdo tal como era — los datos y destinatarios se leen ANTES del delete (la cascada los borra). Sin registro en `recordatorios_enviados` (el acuerdo ya no existe; el rastro vive en `auditoria`); best-effort con log.
- Respuesta 204. Cliente: `eliminarAcuerdo(id): Promise<void>`.
- CORS: `DELETE` añadido a `allowedMethods` (primera vez que el contrato usa ese método).
- Frontend: botón "Eliminar acuerdo…" (solo Dirección) al pie del Drawer con confirmación inline explícita ("no se puede deshacer").

## 3. Consecuencias

- Se eligió borrado **físico** (no lógico): el caso de uso es eliminar capturas erróneas/duplicadas; el rastro queda en `auditoria` (retención 24 meses, doc 04). Si a futuro se quisiera papelera/restauración, sería un ADR nuevo.
- Pruebas EL-01..03 y EDC-01..02 (`AcuerdosEliminarEdicionTest`): cascada completa + auditoría + evento eliminado; 403 auditado sin borrar; 404; capturador edita; ajeno sigue 403.
- doc 05 → v1.7 (`DELETE /acuerdos/{id}`, roles de `PATCH` actualizados).

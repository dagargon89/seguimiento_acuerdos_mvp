# ADR-010 — Notificación de asignación e invitados en el evento de calendario

| Campo | Valor |
|---|---|
| Documento | ADR-010 |
| Versión | 1.0 |
| Fecha | 2026-07-13 |
| Estado | Aceptada |
| Depende de | ADR-003 (integración Google), ADR-009 (sincronización inmediata) |

## 1. Contexto

Con la integración real activa, Dirección pidió dos comportamientos que ADR-003 no cubría en el MVP: (a) que el responsable y corresponsables se enteren **al momento** de que se les asignó un acuerdo, y (b) que el evento de calendario los tenga como **invitados** — ADR-003 dejó esto último explícitamente previsto ("invitaciones por asistente si dirección lo pide", cambio acotado a `GoogleCalendarService`).

## 2. Decisión

### a) Correo inmediato de asignación

- Al capturar un lote (`POST /acuerdos/lote`), tras el commit se envía un correo "Nuevo acuerdo asignado" al **responsable** y a cada **corresponsable activo** (`NotificadorAsignacion`, plantilla `PlantillaCorreo::asignacion` con el rol de cada destinatario). Best-effort: un fallo de Gmail marca `fallido` y continúa; jamás rompe la captura.
- Cada envío queda registrado en `recordatorios_enviados` con el **nuevo tipo `asignacion`** (migración `AgregarTipoAsignacion`; DDL doc 03 actualizado; espejo verificado). Misma trazabilidad (gmail_message_id) y dedup (clave única acuerdo+usuario+tipo+fecha) que los recordatorios del job; aparece en el historial de la pantalla Recordatorios con la etiqueta "Asignación del acuerdo".
- Contrato: `TipoRecordatorio` gana el literal `'asignacion'` (types.ts + doc 05 → v1.6; cambio aditivo, sin firmas nuevas en `ApiClient`).

### b) Invitados (attendees) en el evento

- `GoogleCalendarService::construirEvento` agrega `attendees`: email del responsable (si está activo) + corresponsables activos, únicos. Se reconstruye en cada sync → el `patch` reconcilia cambios de responsable o corresponsables; Google conserva el responseStatus de quienes permanecen.
- `GoogleApiClientCalendarApi` traduce a `EventAttendee` y usa **`sendUpdates=all`** en insert/patch: Google envía su invitación nativa y el evento aparece en el **calendario personal** de cada invitado — resuelve la nota negativa de ADR-003 ("no aparecen hasta suscribirse al compartido").

## 3. Consecuencias

- Quien recibe un acuerdo recibe dos señales al instante: el correo institucional de asignación (con ficha del acuerdo) y la invitación de calendario de Google; los recordatorios 7/3/1 siguen igual vía job.
- Usuarios dados de baja se excluyen de invitados y correos automáticamente.
- Pruebas: NA-01..NA-03 (`NotificacionAsignacionTest`) y GC-06 (`GoogleCalendarServiceTest`, invitados con exclusión de inactivos).

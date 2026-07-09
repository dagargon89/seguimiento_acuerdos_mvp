# Sprint 2 — Recordatorios + Google · Bitácora de pruebas

Objetivo: comando `spark recordatorios:procesar`, `GmailService`, `GoogleCalendarService`,
idempotencia y plantillas de correo.

## Casos planificados (doc 06)

| ID | Caso | Suite | Resultado |
|---|---|---|---|
| RE-01 | Config `[7,3,1]` + día D genera 4 envíos por destinatario | PHPUnit | ✅ S2.1 |
| RE-02 | Override `[5,1]` ignora el global | PHPUnit | ✅ S2.1 |
| RE-03 | Destinatarios = responsable + corresponsables | PHPUnit | ✅ S2.1 |
| RE-04 | Re-ejecutar el job el mismo día NO duplica (UNIQUE natural) | PHPUnit | ✅ S2.1 |
| RE-05 | Acuerdo concluido NO genera envíos | PHPUnit | ✅ S2.1 |
| RE-06 | Reprogramación regenera futuros y cancela obsoletos | PHPUnit | ✅ S2.1 |
| RE-07 | Fallo de Mailer → registro `fallido`, el job continúa | PHPUnit | ✅ S2.1 (vía interfaz Mailer) |
| RE-08 | Seguimiento de vencido respeta `cada_dias`/`max_repeticiones` | PHPUnit | ✅ S2.1 |
| RE-09 | Cambiar default global no altera overrides | PHPUnit | ✅ (S1.7) |
| RE-10 | Resumen periódico agrupa por ámbito del rol | PHPUnit | ✅ S2.1 |
| Vencidos | Job marca en_proceso pasado → vencido; no toca concluidos | PHPUnit | ✅ S2.1 |
| GC-01 | Captura crea `google_sync` pendiente; job crea evento | PHPUnit | ✅ S2.3 |
| GC-02 | Reprogramación mueve el evento (patch, sin duplicar) | PHPUnit | ✅ S2.3 (event_id estable) |
| GC-03 | Conclusión renombra evento `[Concluido]` | PHPUnit | ✅ S2.3 (+ color neutro) |
| GC-04 | Error de API → estado `error`, reintenta hasta 3 | PHPUnit | ✅ S2.3 |
| GC-05 | Idempotencia: job sin cambios no llama a la API | PHPUnit | ✅ S2.3 |

## Suites ejecutadas

| Fecha | Tarea | Comando | Resultado |
|---|---|---|---|
| 2026-07-09 | S2.1 Job recordatorios + RecordatorioService | `cd apps/api && vendor/bin/phpunit` | ✅ 180/180 (766 aserciones) |
| 2026-07-09 | S2.2 GmailService + plantillas de correo | `cd apps/api && vendor/bin/phpunit` | ✅ 197/197 (810 aserciones) |
| 2026-07-09 | S2.3 GoogleCalendarService + google_sync | `cd apps/api && vendor/bin/phpunit` | ✅ 203/203 (835 aserciones) |

S2.3 (+6 casos GC): crear/patch idempotente, `[Concluido]`, error+reintentos<3, cero llamadas si sincronizado.
Vía puerto `CalendarApi` con `FakeCalendarApi` (sin red). Commit `9a145c4`.

## Gate build Sprint 2

- [x] RE-01..10 verdes (job de recordatorios, con dobles)
- [x] GC-01..05 verdes (sincronización de calendario, con dobles)
- [x] Job re-ejecutable e idempotente; fallo de envío no aborta
- [x] Suite PHPUnit verde (203/203)
- [ ] **Corrida real de humo** (correo recibido + evento en calendario) — PENDIENTE de credenciales
      Google (service account + domain-wide delegation, cuenta central, GOOGLE_CALENDAR_ID). Paso operativo del usuario.

**Estado:** ✅ Build completo (100% del código, probado con dobles). Humo real pendiente de config manual.
Por autorización del usuario (2026-07-09), se avanza al Sprint 3 con ese único pendiente operativo.

S2.2 (+17 casos): PlantillaCorreo por tipo 1:1 con EmailModal.tsx (asuntos verificados), 5 de escape XSS
(`<script>` en accion sale escapado — OW-02), `GmailService::construirRaw` base64url + headers MIME sin red,
y `Services::mailer()` → NoopMailer sin credenciales. Envío real con service account: pendiente (operativo). Commit `a5d62c8`.

S2.1 (`RecordatorioJobTest`, 12 casos): RE-01..08/10 + marcado de vencidos, con dobles `FakeMailer`/
`FakeCalendarSync` (sin credenciales). Interfaces `Mailer`/`CalendarSync` + Noop; impl reales pendientes
(GmailService en S2.2, GoogleCalendarService en S2.3). Commit `7bc521e`.

## Corridas reales de humo

| Verificación | Resultado |
|---|---|
| Correo real recibido con esquema `[7,3,1]` | ⏳ |
| Evento visible en el calendario compartido | ⏳ |

## Gate DoD Sprint 2

- [ ] RE-01..10 y GC-01..05 verdes (con clientes Google simulados)
- [ ] Correo real recibido y evento en calendario (humo con credenciales)
- [ ] Job re-ejecutable e idempotente

**Estado:** ⏳ Pendiente.

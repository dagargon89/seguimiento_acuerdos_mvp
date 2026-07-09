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
| GC-01 | Captura crea `google_sync` pendiente; job crea evento | PHPUnit | ⏳ (S2.3) |
| GC-02 | Reprogramación mueve el evento (patch, sin duplicar) | PHPUnit | ⏳ |
| GC-03 | Conclusión renombra evento `[Concluido]` | PHPUnit | ⏳ |
| GC-04 | Error de API → estado `error`, reintenta hasta 3 | PHPUnit | ⏳ |
| GC-05 | Idempotencia: job sin cambios no llama a la API | PHPUnit | ⏳ |

## Suites ejecutadas

| Fecha | Tarea | Comando | Resultado |
|---|---|---|---|
| 2026-07-09 | S2.1 Job recordatorios + RecordatorioService | `cd apps/api && vendor/bin/phpunit` | ✅ 180/180 (766 aserciones) |

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

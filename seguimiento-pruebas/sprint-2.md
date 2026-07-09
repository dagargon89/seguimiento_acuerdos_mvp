# Sprint 2 — Recordatorios + Google · Bitácora de pruebas

Objetivo: comando `spark recordatorios:procesar`, `GmailService`, `GoogleCalendarService`,
idempotencia y plantillas de correo.

## Casos planificados (doc 06)

| ID | Caso | Suite | Resultado |
|---|---|---|---|
| RE-01 | Config `[7,3,1]` + día D genera 4 envíos por destinatario | PHPUnit | ⏳ |
| RE-02 | Override `[5,1]` ignora el global | PHPUnit | ⏳ |
| RE-03 | Destinatarios = responsable + corresponsables | PHPUnit | ⏳ |
| RE-04 | Re-ejecutar el job el mismo día NO duplica (UNIQUE natural) | PHPUnit | ⏳ |
| RE-05 | Acuerdo concluido NO genera envíos | PHPUnit | ⏳ |
| RE-06 | Reprogramación regenera futuros y cancela obsoletos | PHPUnit | ⏳ |
| RE-07 | Fallo de Gmail → registro `fallido`, el job continúa | PHPUnit | ⏳ |
| RE-08 | Seguimiento de vencido respeta `cada_dias`/`max_repeticiones` | PHPUnit | ⏳ |
| RE-09 | Cambiar default global no altera overrides | PHPUnit | ⏳ |
| RE-10 | Resumen periódico agrupa por ámbito del rol | PHPUnit | ⏳ |
| GC-01 | Captura crea `google_sync` pendiente; job crea evento | PHPUnit | ⏳ |
| GC-02 | Reprogramación mueve el evento (patch, sin duplicar) | PHPUnit | ⏳ |
| GC-03 | Conclusión renombra evento `[Concluido]` | PHPUnit | ⏳ |
| GC-04 | Error de API → estado `error`, reintenta hasta 3 | PHPUnit | ⏳ |
| GC-05 | Idempotencia: job sin cambios no llama a la API | PHPUnit | ⏳ |

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

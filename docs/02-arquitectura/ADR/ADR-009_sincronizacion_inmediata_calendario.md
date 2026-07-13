# ADR-009 — Sincronización inmediata con Google Calendar en cada escritura

| Campo | Valor |
|---|---|
| Documento | ADR-009 |
| Versión | 1.0 |
| Fecha | 2026-07-13 |
| Estado | Aceptada |
| Depende de | ADR-003 (integración Google), doc 02 (job diario) |

## 1. Contexto

ADR-003 estableció que el job diario (`spark recordatorios:procesar`) procesa la cola `google_sync` pendiente. Consecuencia aceptada entonces: el evento de calendario de un acuerdo capturado hoy aparecía hasta la corrida del día siguiente (~24 h de retraso). Al activar la integración real (2026-07-13), Dirección pidió que el calendario refleje los acuerdos al momento.

## 2. Decisión

Cada escritura de acuerdo dispara la sincronización **inmediatamente después del commit** de su transacción (best-effort), además de seguir marcando `google_sync` pendiente:

- **Puntos**: captura de lote (cada acuerdo creado), edición, corresponsables, avance **con** reprogramación (sin `nueva_fecha` no hay llamada: el evento no cambia), concluir y reabrir.
- **Fuera de la transacción**: la llamada a la API ocurre tras `transComplete()` exitoso — nunca dentro (una API externa no debe alargar ni abortar una transacción de BD).
- **Best-effort**: `GoogleCalendarService::sincronizar()` no propaga fallos de API (deja la fila `pendiente/error` con el mensaje); un try/catch defensivo en el controlador garantiza que la respuesta HTTP de una escritura ya confirmada jamás falle por Google.
- **El job diario no cambia**: sigue procesando `pendiente`/`error` con `intentos < 3` — ahora como **red de reintentos** de lo que la sincronización inmediata no logró, además de sus demás responsabilidades (vencidos, correos, resumen).

### Guardia de entorno de pruebas

Al activar credenciales reales en el `.env` de desarrollo, la suite de tests empezó a resolver los servicios reales y creó eventos reales durante los tests de escritura (40 eventos basura, ya limpiados). La corrección: `Config\Services::mailer()` y `::calendarSync()` tienen guardia dura `ENVIRONMENT !== 'testing'` — en tests SIEMPRE resuelven Noop, sin importar el `.env` (un `<env value="">` en `phpunit.dist.xml` no sirve: el DotEnv de CI4 trata la cadena vacía como variable no definida). Cubierto por `ServicesMailerTest`.

## 3. Consecuencias

- El calendario compartido refleja capturas/reprogramaciones/conclusiones en segundos, no al día siguiente.
- La captura de lote hace N llamadas síncronas a la API (~0.3–1 s por acuerdo); aceptable a la escala del MVP (pocos acuerdos por reunión). Si creciera, el mismo diseño admite moverlo a una cola.
- Pruebas SI-01..SI-04 (`SincronizacionInmediataTest`): lote sincroniza cada id creado; concluir sincroniza; avance simple NO llama y reprogramación sí; un 403 no dispara nada.

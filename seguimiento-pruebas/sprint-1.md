# Sprint 1 — API núcleo + auth · Bitácora de pruebas

Objetivo: Filters de borde, visibilidad server-side, endpoints del contrato congelado,
administración de usuarios/áreas, y conmutación del frontend a API real.

## Casos planificados (doc 06)

| ID | Caso | Suite | Resultado |
|---|---|---|---|
| AU-01 | Responsable lista solo sus acuerdos (resp. o corresp.) | PHPUnit feature | ⏳ |
| AU-02 | Responsable pide acuerdo ajeno → 404 | PHPUnit feature | ⏳ |
| AU-03 | Coordinador lista su área + participaciones | PHPUnit feature | ⏳ |
| AU-04 | Coordinador edita acuerdo de otra área → 403 | PHPUnit feature | ⏳ |
| AU-05 | Corresponsable registra avance → 200 | PHPUnit feature | ⏳ |
| AU-06 | Corresponsable edita responsable/área → 403 | PHPUnit feature | ⏳ |
| AU-07 | No-Dirección a `/checklist`, `POST /usuarios`, `PUT config` → 403 | PHPUnit feature | ⏳ |
| AU-08 | Token faltante/expirado/firma inválida → 401 | PHPUnit feature | ⏳ |
| AU-09 | Token válido de email no registrado → 403 | PHPUnit feature | ⏳ |
| AU-10 | Usuario desactivado → 403 (≤60 s, tras invalidar cache) | PHPUnit feature | ⏳ |
| ME-01 | Captura nace `en_proceso` | PHPUnit | ⏳ |
| ME-04..06 | Reprogramación vencido→en_proceso (`>= hoy`), fecha `< hoy` → 422 | PHPUnit | ⏳ |
| ME-07..10 | Concluir/reabrir solo Dirección; concluir concluido → 409 | PHPUnit | ⏳ |
| ME-11 | Cliente envía `estado` → 422 `campo_no_permitido` | PHPUnit | ⏳ |
| ME-12 | No-Dirección concluye → 403 + auditoría | PHPUnit | ⏳ |
| LT-01..05 | Captura de lote todo-o-nada, validaciones | PHPUnit | ⏳ |
| PA-01..05 | Panel: oculta concluidos, filtros, búsqueda, vencido derivado, TZ | PHPUnit | ⏳ |
| AD-01..04 | Usuarios: email único, último Dirección, baja lógica, selects | PHPUnit | ⏳ |
| AR-01..04 | Áreas: alta válida, duplicado 422, edición, no-Dirección 403 (ADR-004) | PHPUnit | ⏳ |
| OW-01..08 | Negativos OWASP (SQLi, XSS, IDOR, CORS, rate limit, aud, campos extra) | PHPUnit | ⏳ |
| N+1 | Listado con nº de queries constante | PHPUnit + query counter | ⏳ |

## Suites ejecutadas

| Fecha | Tarea | Comando | Resultado |
|---|---|---|---|
| 2026-07-09 | S1.3 Filters de borde | `cd apps/api && vendor/bin/phpunit` | ✅ 43/43 (116 aserciones) |
| 2026-07-09 | S1.4 Endpoints de lectura | `cd apps/api && vendor/bin/phpunit` | ✅ 64/64 (193 aserciones) |
| 2026-07-09 | S1.5 Endpoints de escritura | `cd apps/api && vendor/bin/phpunit` | ✅ 107/107 (316 aserciones) |
| 2026-07-09 | S1.6 Conclusión/checklist/admin | `cd apps/api && vendor/bin/phpunit` | ✅ 145/145 (492 aserciones) |
| 2026-07-09 | S1.7 Calendario/resumen/recordatorios/config | `cd apps/api && vendor/bin/phpunit` | ✅ 168/168 (727 aserciones) |

S1.7 (23 casos nuevos): PA-05 (calendario agrupado por día en TZ Juárez, caso frontera fin de mes),
resumen por ámbito de rol (general/área), visibilidad de recordatorios próximos/historial,
config GET/PUT (PUT solo Dirección, dias_antes⊆[0..30] ordenado→422) y RE-09 (cambiar global no toca overrides).
Envolturas verificadas: calendario/resumen/config SIN `data`, recordatorios CON `data` (== api.real.ts).
**Backend del Sprint 1 completo.**

S1.6 (38 casos nuevos): ME-07/08/09/10/12 (incl. reabrir-no-concluido→409), AU-07, checklist (solo
abiertos, vencidos primero, sin N+1), AD-01..05 (AD-05: desactivar → 403 en ≤1 request por AuthCache
invalidado), AR-01..04 (áreas v1.2). **ME-12 verifica la fila `auditoria` del intento 403 de concluir.**
Nota: `concluir` con nota opcional (SRS RF-06.2 "opcionalmente nota" + contrato); reabrir la exige.

S1.5 (`AcuerdosEscrituraTest`, 43 casos nuevos): LT-01..05 (lote todo-o-nada, cero persistido en LT-02
verificado en 5 tablas), ME-01/04/04b/05/06/11, avance-en-concluido→409, AU-04/05/06, OW-01/02/06/08.
2 bugs reales corregidos (insertBatch con PK compuesta; JSON-encode de recordatorio_dias en PATCH).
Fix de consistencia: `api.mock.ts` alineado a la regla `>= hoy` (antes exigía `> hoy`); frontend 6/6 + typecheck verdes.

S1.4 (`AcuerdosLecturaTest`, commit pendiente): AU-01/02/03 (visibilidad + 404 ajeno), PA-01..05b
(oculta concluidos, filtro estado, búsqueda q, stats, vencido derivado en TZ), `/me` sin envoltura,
detalle acuerdo 4 (corresponsables + override + recordatorios), y **cero N+1** (3 queries constantes
verificadas con carga creciente). Formas de respuesta contrastadas contra `api.real.ts`: OK.

Cubierto por `FiltersDeBordeTest` + `KreaitTokenVerifierTest`: AU-08a/b/c (401), OW-07 (aud ajeno→401),
AU-09/09b (email no registrado / inactivo →403), RF-01.3 (primer login enlaza uid), AU-10 (desactivado
tras invalidar cache→403), OW-05 (throttle→429+Retry-After), OW-04 (CORS preflight origen no listado sin headers).
Commit `314b5ac`. Concerns: JWKS cache in-memory de kreait; mapeo de motivo por mensaje (no afecta el 401).

## Smoke real (S1.8)

| Fecha | Verificación | Resultado |
|---|---|---|
| 2026-07-09 | Login real con Google (dgarcia@planjuarez.org) | ✅ entra como Dirección |
| 2026-07-09 | Fix: `/me` 500 → verificación con IdTokenVerifier (sin service account) | ✅ commit 5475317 |
| 2026-07-09 | Token inválido → 401 `token_invalido` (no 500) | ✅ |
| 2026-07-09 | Flujo end-to-end: checklist → concluir acuerdo (regla central) | ✅ contra API real |
| 2026-07-09 | Frontend build+typecheck+lint+test(mock 6/6) | ✅ commit 1f36902 |

## Gate DoD Sprint 1

- [x] Dirección opera el flujo completo contra API real (login Google + concluir desde checklist)
- [x] 100% de casos ME/AU/LT/PA/AD/AR/OW verdes (168/168 PHPUnit)
- [x] Cero N+1 verificado (3 queries constantes en el listado)
- [x] Suite PHPUnit verde (168/168)

**Estado:** ✅ Completo. Gate cumplido — se habilita el Sprint 2 (recordatorios + Google).

> Nota: el smoke validó el rol Dirección end-to-end. Los flujos de coordinación/responsable con
> cuentas reales quedan para el piloto (Sprint 3); su lógica está cubierta por los tests AU-01..06.

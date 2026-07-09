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

(se registran conforme se implementan)

## Gate DoD Sprint 1

- [ ] Los 3 roles operan el flujo completo contra API real (frontend `VITE_USE_MOCK=false`)
- [ ] 100% de casos ME/AU/LT/PA/AD/AR/OW verdes
- [ ] Cero N+1 verificado
- [ ] Suite PHPUnit verde

**Estado:** ⏳ Pendiente.

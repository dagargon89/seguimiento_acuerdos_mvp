# Sprint 3 — Piloto + cierre · Bitácora de pruebas

Objetivo: piloto con datos reales, rendimiento, hardening, promoción y cierre del DoD Fase 2.

## Rendimiento (doc 06 §4)

Tarea 13 (S3.1). k6 NO está instalado en este entorno — las 3 métricas de
carga p95 quedan ⏳ con el script/README listos como artefacto (ver
`apps/api/tests/perf/k6-acuerdos.js` + `apps/api/tests/perf/README.md` y el
seeder opcional `PerfSeeder`/`php spark perf:seed` para generar el volumen de
~5,000 acuerdos). Lo medible sin esa herramienta (N+1 con datos escalados y
bundle gzip) se corrió y se documenta con valores concretos abajo.

| Métrica | Umbral | Herramienta | Resultado |
|---|---|---|---|
| `GET /acuerdos` (5,000 filas, per_page 200) | p95 < 500 ms | k6 | ⏳ requiere k6 — script listo (`k6-acuerdos.js`, escenario `listado_acuerdos`) |
| `GET /acuerdos/{id}` (50 avances) | p95 < 300 ms | k6 | ⏳ requiere k6 — script listo (escenario `detalle_acuerdo`) |
| `POST /acuerdos/lote` (20 acuerdos) | p95 < 800 ms | k6 | ⏳ requiere k6 — script listo (escenario `captura_lote`) |
| Job diario (500 abiertos, 100 envíos) | < 5 min | cronometrado | ⏳ requiere volumen (`PerfSeeder`) + BD real; nota de cómo medirlo (`time php spark recordatorios:procesar`) en el README de perf |
| Consultas por request en listado | Sin N+1 (constante) | contador CI4 (evento `DBQuery`) | ✅ **3 queries constantes** con 10, 30 y ~200 filas visibles (`AcuerdosLecturaTest::testListadoDireccionEjecutaUnNumeroConstanteDeQueriesIndependienteDeLasFilas`) |
| Consultas por request en detalle | Sin N+1 (constante) | contador CI4 (evento `DBQuery`) | ✅ **7 queries constantes** con 1 y 51 avances (`AcuerdosLecturaTest::testDetalleEjecutaUnNumeroConstanteDeQueriesIndependienteDelNumeroDeAvances`) |
| Bundle inicial SPA | < 350 KB gzip | `vite build` (reporte de gzip) + verificación manual `gzip -c` | ✅ **~135.5 KB gzip** (138,714 B; Vite reporta 139.02 kB) del único `.js` de `dist/assets` — muy por debajo del umbral |

## Seguridad y auditorías

| Verificación | Resultado |
|---|---|
| `composer audit` sin críticos | ⏳ |
| `npm audit` sin críticos | ⏳ |
| OWASP A01–A10 re-verificado (checklist doc 04) | ⏳ |
| Cobertura de Services ≥ 80% (`phpunit --coverage-text`) | ⏳ |

## Cierre

| Verificación | Resultado |
|---|---|
| Promoción `apps/web` sin mock (typecheck/lint/build verdes) | ⏳ |
| Suite completa Fase 2 verde | ⏳ |
| Checklist DoD Fase 2 firmada en README | ⏳ |

## Gate DoD Fase 2 (Gobernanza v3 §3)

- [ ] Cero N+1 auditado · Policies con negativos · seeder desde `db.json` sin transformación
- [ ] Cobertura ≥80% · OWASP re-verificado · transacciones confirmadas · `api.ts` ≡ doc 05

**Estado:** ⏳ Pendiente.

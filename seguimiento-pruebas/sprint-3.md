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

Tarea 14 (S3.2). Detalle completo en `docs/04-seguridad/04b_verificacion_owasp_fase2.md` y
`docs/04-seguridad/checklist_despliegue.md`. Cambio de código (mínimo, según alcance de la
tarea): HSTS condicional en `SecurityHeadersFilter` — se agrega `Strict-Transport-Security`
solo cuando la request es HTTPS real (`$request->isSecure()`), nunca en HTTP plano (dev/CI).
Cubierto por 2 tests nuevos en `FiltersDeBordeTest`
(`testHSTSAusenteEnHttpAunEnProduccion`, `testHSTSPresenteCuandoLaRequestEsHttps`).

| Verificación | Resultado |
|---|---|
| `composer audit` sin críticos | ✅ "No security vulnerability advisories found." (2026-07-09) |
| `npm audit` sin críticos | ✅ "found 0 vulnerabilities" (2026-07-09) — no fue necesario `npm audit fix` |
| OWASP A01–A10 re-verificado (checklist doc 04) | ✅ A01/A03/A06/A07/A09 y A02(HSTS)/A05(código)/A10(backend) verificados con evidencia en código+tests; A02(HTTPS forzado)/A05(`CI_ENVIRONMENT`)/A08(procedimiento operativo)/A10(`rel="noopener"` en frontend) marcados 🔶 como config de despliegue pendiente o mejora de defensa en profundidad de bajo riesgo — ninguno bloqueante. Ver `04b_verificacion_owasp_fase2.md`. |
| Cobertura de Services ≥ 80% (`phpunit --coverage-text`) | ⏳ **gate no medible en este entorno** — sin driver de cobertura (pcov/Xdebug no instalables, sin `pecl`/`phpize`). Comando para medirlo cuando haya driver: `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text` (Xdebug) o `vendor/bin/phpunit --coverage-text` con `pcov` instalado. Suite actual: **206/206 tests verde** (204 previos + 2 de HSTS de esta tarea), 842 assertions, cubriendo dominio/permisos/estados/job/plantillas/calendario/filtros de borde. |

## Cierre

| Verificación | Resultado |
|---|---|
| Promoción `apps/web` sin mock (typecheck/lint/build verdes) | ✅ S3.3 commit b07d525 (vitest 4/4, espejo 11/11, db.json conservado) |
| Suite completa Fase 2 verde | ✅ backend 206/206 + frontend 4/4 (2026-07-09) |
| Checklist DoD Fase 2 firmada en README | ✅ S3.4 (2026-07-09) |
| Revisión final de rama (whole-branch, Opus) | ✅ APTO sin críticos; 4 hallazgos corregidos (commit 797c35b) |
| Suite tras correcciones | ✅ 212/212 (858 aserciones) |

## Gate DoD Fase 2 (Gobernanza v3 §3)

- [x] Cero N+1 auditado · Policies con negativos · seeder desde `db.json` sin transformación
- [x] OWASP re-verificado (con notas) · transacciones confirmadas · `api.ts` ≡ doc 05
- [ ] Cobertura ≥80% — ⏳ pendiente de driver (pcov/xdebug); 212 tests como red actual

**Estado:** ✅ Desarrollo cerrado. Pendientes operativos del usuario: humo real Gmail/Calendar
(credenciales), métricas k6, cobertura con driver, y deploy. Detalle en README (checklist DoD).

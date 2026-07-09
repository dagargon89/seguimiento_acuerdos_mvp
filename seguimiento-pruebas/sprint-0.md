# Sprint 0 — Cimientos · Bitácora de pruebas

Objetivo del sprint: reestructura del monorepo, mejoras de documentación (contrato v1.2) y
scaffold del backend con migraciones espejo del DDL + seeder.

## Suites y verificaciones ejecutadas

| # | Verificación | Comando | Resultado | Fecha |
|---|---|---|---|---|
| 1 | Espejo `db.json ↔ DDL` tras reestructura | `node scripts/verificar_espejo.mjs` | ✅ 11/11 tablas, sin discrepancias | 2026-07-09 |
| 2 | `docker compose` válido desde la raíz | `docker compose config --quiet` | ✅ OK | 2026-07-09 |
| 3 | Contenedores infra healthy | `docker compose ps` | ✅ mysql + redis healthy | 2026-07-09 |
| 4 | DDL aplicado en BD dev | `docker exec … SHOW TABLES` | ✅ 11 tablas | 2026-07-09 |
| 5 | Frontend typecheck (tras contrato v1.2) | `cd apps/web && npm run typecheck` | ✅ exit 0 | 2026-07-09 |
| 6 | Frontend tests (mock, reglas de dominio) | `cd apps/web && npm test` | ✅ 6/6 | 2026-07-09 |
| 7 | Frontend lint | `cd apps/web && npm run lint` | ✅ sin hallazgos | 2026-07-09 |
| 8 | Espejo `db.json ↔ DDL` tras `enlace VARCHAR(2048)` | `node scripts/verificar_espejo.mjs` | ✅ sin discrepancias | 2026-07-09 |

## Backend (apps/api) — pendiente

| # | Verificación | Comando | Resultado |
|---|---|---|---|
| 9 | Scaffold CI4 + smoke `/api/v1/ping` | `cd apps/api && vendor/bin/phpunit` | ✅ 6/6 (PingTest JSON exacto + Content-Type) | 2026-07-09 |
| 9b | TZ efectiva del backend | `php spark db:table --show` (Server Time) | ✅ UTC-06:00 (Ciudad Juárez) | 2026-07-09 |
| 9c | Conexión MySQL a `panel_acuerdos` | `php spark db:table --show` | ✅ 11 tablas | 2026-07-09 |
| 9d | Redis auth/ping | `redis-cli -a … ping` | ✅ PONG | 2026-07-09 |
| 10 | Migración crea las 11 tablas idénticas al DDL | `EsquemaEspejoTest` (columnas + FKs vs BD ref del DDL) | ✅ | 2026-07-09 |
| 11 | `InitialSeeder` puebla desde `db.json` sin transformación | `InitialSeederTest` (conteos + acuerdo 4 + config) | ✅ 10 tablas + muestreo | 2026-07-09 |
| 12 | CHECK de conclusión y UNIQUE de idempotencia vivos | `InitialSeederTest` | ✅ ambas lanzan excepción | 2026-07-09 |
| 13 | Suite backend completa | `vendor/bin/phpunit` | ✅ 21/21 (50 aserciones) | 2026-07-09 |
| 14 | Recreación BD dev vía migración+seed | `php spark migrate && db:seed` | ✅ 11 tablas, conteos == db.json | 2026-07-09 |

> Nota: `phpunit` avisa "No code coverage driver available" (falta pcov/xdebug). Informativo;
> se instalará para el gate de cobertura ≥80% del Sprint 3.

## Gate DoD Sprint 0

- [x] Reestructura monorepo + git baseline
- [x] Contrato v1.2 + docs (frontend verde, espejo verde)
- [x] Scaffold apps/api + migraciones espejo + seeder (PHPUnit 21/21 verde)

**Estado:** ✅ Completo. Gate cumplido — se habilita el Sprint 1.

# Sprint 3 — Piloto + cierre · Bitácora de pruebas

Objetivo: piloto con datos reales, rendimiento, hardening, promoción y cierre del DoD Fase 2.

## Rendimiento (doc 06 §4)

| Métrica | Umbral | Herramienta | Resultado |
|---|---|---|---|
| `GET /acuerdos` (5,000 filas, per_page 200) | p95 < 500 ms | k6 | ⏳ |
| `GET /acuerdos/{id}` (50 avances) | p95 < 300 ms | k6 | ⏳ |
| `POST /acuerdos/lote` (20 acuerdos) | p95 < 800 ms | k6 | ⏳ |
| Job diario (500 abiertos, 100 envíos) | < 5 min | cronometrado | ⏳ |
| Consultas por request en listado | Sin N+1 (constante) | contador CI4 | ⏳ |
| Bundle inicial SPA | < 350 KB gzip | `vite build --report` | ⏳ |

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

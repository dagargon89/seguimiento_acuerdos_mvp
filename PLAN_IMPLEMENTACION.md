# Plan de implementación — Panel de Acuerdos: del demo al producto (Fase 2)

## Estado de ejecución (actualizado 2026-07-09 ~12:30)

**Modo de ejecución (decisiones del usuario):** implementación **sprint por sprint con gate al
100%** — no se avanza al siguiente sprint hasta que el anterior cumple su *Definition of Done*
(tests verdes + criterios de aceptación + verificación ejecutable). Ritmo directo (yo implemento,
sin ciclos de subagente por tarea); revisión completa al final de cada sprint. Repo git **nuevo**
en la raíz `~/seguimiento_acuerdos_mvp/` (rama de trabajo `fase-2-backend`; baseline en `master`).

**Reestructura (hecha):** el código quedó en la raíz — `apps/web` (frontend), `apps/api`
(backend, a reconstruir), `docs/` (documentación 00-07 + ADRs + guía 09), `scripts/`,
`docker-compose.yml`, `CLAUDE.md`, `README.md` en la raíz. Rutas actualizadas y espejo verde.
El scaffold previo de `apps/api` fue borrado por el usuario; se reconstruye por sprints.

**Decisiones de producto (2026-07-09):**
- Áreas: SÍ se administran en el MVP → se extiende el contrato congelado (`crearArea`/`editarArea`)
  con ADR-004; el doc 05 pasa a v1.2 y se re-congela.
- Reprogramar con `nueva_fecha == hoy` regresa a `en_proceso` (regla `>= hoy`).
- Un corresponsable no puede ser el responsable del mismo acuerdo (422).
- Recordatorios siempre prospectivos (nunca retroactivos).
- `acuerdos.enlace` pasa a `VARCHAR(2048)`.
- Búsqueda de texto con `LIKE` (sin FULLTEXT) por volumen <5000 filas.

| Sprint | Contenido | Estado |
|---|---|---|
| 0 — Cimientos | Reestructura + git baseline | ✅ Hecho |
| 0 — Cimientos | Mejoras de doc: áreas v1.2 + ADR-004 + doc 05/06 + SRS + DDL enlace 2048 | ⏳ En curso |
| 0 — Cimientos | Scaffold `apps/api` (CI4 4.7) + migraciones espejo DDL + InitialSeeder | ⏳ Pendiente |
| 1 — API núcleo + auth | Filters, visibilidad, endpoints del contrato, conmutación frontend | ⏳ Pendiente |
| 2 — Recordatorios + Google | Job spark, Gmail, Calendar, idempotencia | ⏳ Pendiente |
| 3 — Piloto + cierre | Rendimiento, hardening, promoción, DoD Fase 2 firmada | ⏳ Pendiente |

Ledger detallado del avance: `.superpowers/sdd/progress.md`.

## Contexto

Plan Juárez sustituye minutas narradas por un sistema de seguimiento de acuerdos de reuniones
de dirección. El proyecto sigue Gobernanza v3 (gates por fase): la Fase 0 (docs 00–08) está
completa y la Fase 1 (demo React + mock) está construida con checks verdes y espejo
`db.json`↔DDL verificado. El demo ya es funcional; el objetivo ahora es **generar el producto**:
backend CodeIgniter 4.7 (PHP 8.3) + MySQL 8.4 + Redis 7 + Firebase Auth + Gmail/Calendar API,
y conmutar el frontend de mock a API real **sin tocar las pantallas** (regla №2: todo pasa por
la interfaz `ApiClient`).

**Decisiones confirmadas por el usuario:**
- El freeze del contrato es el Paso 0 del plan (la validación con stakeholder corre en paralelo; hallazgos entran por bitácora + re-freeze vía ADR corto).
- Alcance: Fase 2 completa (Sprints 1, 2 y 3 del doc 07).
- Credenciales Firebase y Google (service account + domain-wide delegation) **ya existen**; solo se cablean en `.env`.
- Repo git: `git init` en `SeguimientoAcuerdosDocs/`; el backend nace en `SeguimientoAcuerdosDocs/apps/api`.

**Hallazgos clave de la exploración:**
- `demo-ux/app/src/lib/api.real.ts` ya está **completamente implementada** (fetch, `qs()`, manejo de errores `{error,mensaje,campos}`, `setTokenProvider()` para inyectar el ID token). El backend debe replicar ese contrato exacto.
- `api.mock.ts` contiene la lógica de dominio de referencia (visibilidad por rol, máquina de estados, validaciones, recordatorios) que los Services de CI4 deben replicar.
- El doc 05 §3 sigue marcado "borrador": el freeze (interfaz literal + CONGELADA) está pendiente.
- No existe repositorio git (el Sprint 0 lo exige con `.gitignore` desde el primer commit).
- `docker-compose.yml` ya aplica el DDL (`03-datos/panel_acuerdos_ddl.sql`) al primer arranque.

Todas las rutas de abajo son relativas a `/home/dagargon89/seguimiento_acuerdos_mvp/SeguimientoAcuerdosDocs/`.

---

## Etapa 0 — Cimientos y freeze del contrato (½ día)

1. **Repo git:** `git init` en `SeguimientoAcuerdosDocs/`; `.gitignore` (`.env`, `node_modules/`, `vendor/`, `writable/`, claves `*.json` de service account, `dist/`); primer commit con el estado actual.
2. **Freeze del contrato (Gobernanza v3 §4):** reemplazar el §3 del `05-api/05_especificacion_api.md` por el bloque **literal** de `demo-ux/app/src/lib/api.ts` (interfaz `ApiClient`, 22 métodos) y marcar el doc como **CONGELADA** (versión 1.1).
3. **Actualizar gates:** en `README.md` y `CLAUDE.md`, Fase 1 → "✅ DoD verificada: Sí (freeze 2026-07-09; sesión de stakeholder agendada en paralelo, hallazgos vía bitácora doc 09 §9 + ADR corto)".
4. **Infraestructura:** `docker compose up -d` y `node scripts/verificar_espejo.mjs` en verde.
5. **Credenciales:** capturar en `.env` (nunca en repo) el project id de Firebase y la ruta de la clave del service account (`GOOGLE_APPLICATION_CREDENTIALS`, permisos 600).

## Etapa 1 — Sprint 1: API núcleo + auth (≈1 semana)

**Objetivo:** los 3 roles operan el flujo completo contra API real con `VITE_USE_MOCK=false`.

### 1.1 Scaffold `apps/api`
- `composer create-project codeigniter4/appstarter apps/api` (CI4 4.7, PHP 8.3).
- Config: `app.timezone = America/Ciudad_Juarez`, baseURL, DB (`panel_acuerdos`, usuario `panel_app`), Redis como cache handler, CORS con lista de orígenes desde `.env`.
- Dependencias: `kreait/firebase-php` (verificación de ID token; alternativa documentada: JWKS manual con cache Redis), `google/apiclient` (se usa en Etapa 2).

### 1.2 Migraciones y seeder
- Migraciones CI4 espejo 1:1 del DDL `03-datos/panel_acuerdos_ddl.sql` (11 tablas, ENUMs, FKs, CHECKs, índices, UNIQUE de idempotencia en `recordatorios_enviados`).
- `InitialSeeder` que lee `demo-ux/app/src/lib/mock/db.json` **sin transformación** (DoD Fase 2: "seeder desde db.json sin transformación").

### 1.3 Borde (Filters)
- `FirebaseAuthFilter`: Bearer token → verificar firma/`exp`/`aud`/`iss` → resolver usuario local por `firebase_uid` o email verificado (primer login enlaza `firebase_uid`) → 401/403 `usuario_no_registrado` → cache Redis `usr:{uid}` TTL 60 s (desactivación efectiva ≤60 s).
- `CorsFilter`, `ThrottleFilter` (60 req/min usuario, 10 req/min IP, Redis, 429 + `Retry-After`), `SecurityHeadersFilter` (nosniff, DENY, HSTS, CSP, Referrer-Policy).

### 1.4 Dominio (Policies + Services + Models)
Estructura según doc 02 (informe de arquitectura):
- `app/Policies/`: `AcuerdoPolicy` (puedeVer/puedeEditar/puedeAvanzar/puedeConcluir), `UsuarioPolicy`, `ConfiguracionPolicy`. **Fuente de verdad: matriz SRS §2.2** y la lógica ya validada en `api.mock.ts` (`puedeVer`, `puedeEditar`, `puedeAvanzar`).
- `app/Services/`: `AcuerdoService` (capturarLote transaccional todo-o-nada, editar, corresponsables, avances/reprogramación `vencido→en_proceso`, concluir/reabrir con auditoría), `UsuarioService`, `ConfiguracionService`, `AuditoriaService`, `ResumenService`.
- `app/Models/` + Entities por tabla; Query Builder siempre; transacciones `transException(true)->transStart()…transComplete()` en toda operación multi-tabla; listados con joins/`whereIn` agrupado (**cero N+1**).
- Reglas no negociables: `estado` jamás se acepta del cliente (422 `campo_no_permitido`); `vencido` solo lo asigna el sistema (job + salvaguarda derivada en lectura); `concluido` solo Dirección.

### 1.5 Endpoints (contrato congelado, doc 05)
Los 22 métodos de `ApiClient` ≈ 23 rutas bajo `/api/v1`: `/me`, `/acuerdos` (listado con filtros
`estado|responsable_id|q|desde|hasta` + paginación, detalle, `POST /lote`, `PATCH`, `PUT /corresponsables`,
`POST /avances`, `PATCH /concluir`, `PATCH /reabrir`), `/recordatorios/proximos|historial`,
`/configuracion/recordatorios` (GET/PUT), `/checklist`, `/calendario?mes=`, `/usuarios` (GET/POST/PATCH),
`/areas` (GET/POST/PATCH), `/resumen`. Formato de error y envoltura `{data, meta}` exactos a lo que
`api.real.ts` ya espera. Default del listado: solo abiertos (concluidos exigen filtro explícito).

### 1.6 Pruebas (doc 06)
- PHPUnit + `DatabaseTestTrait`: máquina de estados **ME-01..12**, autorización **AU-01..10** (negativos 403/401/404 obligatorios), lote **LT-01..05**, panel **PA-01..05**, administración **AD-01..04**, OWASP **OW-01..08**.
- Objetivo de cobertura de Services ≥80 % (se audita al cierre en Etapa 3).

### 1.7 Conmutación del frontend
- Integrar Firebase SDK en `demo-ux/app`: `Login.tsx` pasa de selector demo a login real Google + email/password (único cambio de pantalla, previsto en ADR-002); `App.tsx` llama `setTokenProvider(() => user.getIdToken())`.
- `.env`: `VITE_USE_MOCK=false`, `VITE_API_BASE_URL=http://localhost:8080/api/v1`.
- Smoke manual por rol (protocolo doc 09 §8): dirección (checklist/concluir/config/usuarios), coordinación (captura lote + corresponsables + reprogramación), responsable (panel/calendario/recordatorios).

**Checkpoint:** los 3 roles completan sus flujos contra la API real; suite PHPUnit verde.

## Etapa 2 — Sprint 2: Recordatorios + Google (≈1 semana)

1. **`GmailService`**: service account + domain-wide delegation impersonando la cuenta central; scope `gmail.send`; retorna `gmail_message_id` que se persiste en `recordatorios_enviados`.
2. **`GoogleCalendarService`**: eventos all-day en el calendario compartido "Acuerdos · Plan Juárez"; crear/mover (reprogramación)/renombrar `[Concluido]`; estado en `google_sync` con reintentos <3; idempotente (re-ejecutar sin cambios no llama a la API).
3. **`RecordatorioService` + comando `spark recordatorios:procesar`** (`app/Commands/RecordatoriosProcesar.php`), pasos en orden: marcar vencidos → materializar envíos de hoy (config global `configuracion.recordatorios_default` + override `acuerdos.recordatorio_dias`; destinatarios = responsable + corresponsables; idempotencia por UNIQUE `(acuerdo_id, usuario_id, tipo, programado_para)`) → enviar por Gmail (fallo → `estado='fallido'` + error, el job continúa) → sincronizar Calendar → resumen periódico según frecuencia.
4. **Plantillas de correo** 1:1 con la vista previa del demo (`Recordatorios.tsx` / `EmailModal.tsx`), con escape de todo contenido.
5. **Cron diario** (07:00 TZ Juárez) documentado en README; el comando es re-ejecutable e idempotente.
6. **Pruebas:** RE-01..10 y GC-01..05 (doc 06), con clientes Google simulados (fakes) + una corrida real de humo (correo recibido con esquema `[7,3,1]`, evento visible en el calendario compartido).

**Checkpoint:** correo real recibido y evento en calendario; RE/GC verdes.

## Etapa 3 — Sprint 3: Piloto, hardening y promoción (≈1 semana)

1. **Piloto:** carga de acuerdos de 2–3 reuniones reales vía `POST /acuerdos/lote`; ajustes de la bitácora de validación (doc 09 §9).
2. **Rendimiento (doc 06 §4):** k6 sobre listado 5,000 filas (p95<500 ms), detalle (<300 ms), lote 20 (<800 ms); job <5 min; auditoría de N+1 con contador de queries en test de integración; bundle SPA <350 KB gzip.
3. **Hardening:** checklist doc 04 (HTTPS+HSTS, puertos MySQL/Redis no publicados, `CI_ENVIRONMENT=production`, secretos 600, backups); `composer audit` / `npm audit` sin críticos; re-verificación OWASP A01–A10.
4. **Promoción:** `demo-ux/app` → `apps/web`; eliminar `api.mock.ts`, `mock/` y el selector de cuentas demo; `lib/index.ts` queda solo con `realClient`; capacitación breve a capturistas.
5. **Cierre DoD Fase 2 (Gobernanza v3 §3):** checklist firmada en README — cero N+1 auditado, Policies con negativos, seeder desde `db.json` sin transformación, cobertura ≥80 %, OWASP re-verificado, transacciones confirmadas, `api.ts` ≡ doc 05.

---

## Archivos críticos

| Qué | Ruta |
|---|---|
| Contrato congelado (fuente de verdad) | `demo-ux/app/src/lib/api.ts` ↔ `05-api/05_especificacion_api.md` §3 |
| Cliente HTTP ya listo (no tocar) | `demo-ux/app/src/lib/api.real.ts` |
| Lógica de dominio de referencia | `demo-ux/app/src/lib/api.mock.ts` (visibilidad, estados, validaciones) |
| DDL fuente de verdad / seeder | `03-datos/panel_acuerdos_ddl.sql` + `demo-ux/app/src/lib/mock/db.json` |
| Verificación ejecutable | `scripts/verificar_espejo.mjs` |
| Backend nuevo | `apps/api/` (Filters, Policies, Services, Models, Commands) |
| Frontend a conmutar | `demo-ux/app/.env`, `Login.tsx`, `App.tsx` (`setTokenProvider`) |

## Verificación end-to-end

1. `docker compose up -d` + `node scripts/verificar_espejo.mjs` → verde.
2. `cd apps/api && php spark migrate && php spark db:seed InitialSeeder && vendor/bin/phpunit` → ME/AU/LT/PA/AD/OW/RE/GC verdes, cobertura ≥80 %.
3. `php spark serve` + `cd demo-ux/app && npm run dev` con `VITE_USE_MOCK=false` → recorrido por rol (protocolo doc 09 §8), incluida la prueba negativa: coordinador intenta concluir → 403 auditado.
4. `php spark recordatorios:procesar` dos veces el mismo día → sin duplicados; correo real recibido; evento en calendario compartido.
5. k6 + contador de queries → umbrales doc 06 §4; `composer audit`/`npm audit` limpios.
6. Checklist DoD Fase 2 firmada en README; `npm run typecheck && npm run lint && npm run build` verdes en `apps/web`.

## Riesgos vigilados (doc 07)

- Piloto revela cambios de contrato tras el freeze → ADR corto + actualización simultánea `api.ts`↔doc 05 (misma sesión).
- Aunque las credenciales existen, verificar al inicio de la Etapa 2 que la delegación cubre `gmail.send` y Calendar para la cuenta central (plan B: refresh token OAuth de la cuenta central).

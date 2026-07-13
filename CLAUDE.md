# CLAUDE.md — Guía operativa · Panel de Acuerdos

Aplicación de seguimiento de acuerdos de reuniones de dirección de Plan Juárez: captura multiusuario, paneles por rol, recordatorios automáticos por correo y sincronización con Google Calendar. Sustituye minutas narradas por registros estructurados de acuerdos.

## Núcleo de dominio

**Acuerdo**: compromiso pactado en una reunión — tema, acción, responsable (1), corresponsables (0..N), área, fecha compromiso, estado, enlace a productos, observaciones, avances. **Estados**: `en_proceso` (default) → `vencido` (automático al pasar fecha compromiso; regresa a `en_proceso` al reprogramar) → `concluido` (SOLO Dirección, vía checklist de validación). Concluidos ocultos por defecto en el panel.

**Acceso (RF-01, ADR-006):** el acceso ya no depende exclusivamente del alta manual de Dirección — cualquier portador de un ID token Firebase válido puede autorregistrarse (`POST /registro`), pero nace con rol `pendiente`: existe en `usuarios` sin ningún acceso funcional (403 `cuenta_pendiente` fuera de `GET/PATCH /me`) hasta que Dirección le asigna uno de los tres roles operativos (`direccion`/`coordinador`/`responsable`) vía `PATCH /usuarios/{id}`.

**Visibilidad de acuerdos (ADR-007, temporal):** la LECTURA es abierta — dirección/coordinador/responsable ven TODOS los acuerdos, sin filtrar por área/participación ("trabajamos en conjunto"). La ESCRITURA es por rol: editar/avances exige área o participación (o Dirección); **conclusión (ADR-012): Dirección concluye cualquier acuerdo y la coordinación concluye los de su área (`acuerdo.area_id`); reabrir/eliminar siguen siendo solo Dirección**. El rol `pendiente` sigue sin ver nada.

## Estado de fase (gate)

| Fase | Estado | DoD verificada |
|---|---|---|
| 0 — Documentación (00–08) | ✅ Completa (2026-07-08) | Sí |
| 1 — Demo UI/UX (React, `apps/web/`) | ✅ Completa | Sí (freeze del contrato 2026-07-09; sesión de validación con stakeholder en paralelo — hallazgos vía bitácora doc 09 §9 + ADR corto) |
| 2 — Backend (CI4) | ✅ Desarrollo completo (2026-07-09) | Sí, con pendientes operativos (ver checklist DoD en README) |

**Regla de gate:** no generes código o docs de la Fase N+1 si la Fase N tiene "DoD verificada: No", salvo excepción justificada por escrito en esta tabla (aquí y en README).

## Stack

| Capa | Tecnología |
|---|---|
| Frontend | React 19 + TypeScript 5 + Vite 7 + Tailwind 4 (tokens PJ en `@theme`) + TanStack Query 5 |
| Backend | CodeIgniter 4.7 (PHP 8.3), API REST |
| BD / Cache | MySQL 8.4 (Docker) / Redis 7 (Docker) |
| Auth | Firebase Authentication (Google restringido a dominio + email/password), verificación de ID token en Filter CI4 |
| Google | Gmail API (correo, cuenta central), Calendar API (calendario compartido "Acuerdos"), Tasks API (post-MVP, OAuth por usuario) |
| Jobs | Comando `spark recordatorios:procesar` vía cron diario, TZ `America/Ciudad_Juarez` |

## Reglas no negociables

1. **`db.json` es espejo del DDL del doc 03** — mismas tablas, columnas, enums, FKs. *Por qué:* la forma de datos validada en el demo debe ser idéntica a la que producirá el backend; se verifica ejecutablemente antes de cerrar el Sprint D (Gobernanza v3 §5).
2. **Las pantallas nunca leen `db.json` directo** — todo pasa por la interfaz `ApiClient` de `lib/api.ts`. *Por qué:* cambiar mock→API real debe ser cambio de implementación, no de pantallas.
3. **`api.ts` congelada = doc 05** — tras el freeze del Sprint D, el doc 05 contiene literalmente la interfaz TypeScript; cualquier cambio en Fase 2 actualiza ambos en la misma sesión. *Por qué:* una sola fuente de verdad del contrato (Gobernanza v3 §4).
4. **La conclusión es de Dirección y de la coordinación en su área (ADR-012).** Dirección concluye cualquier acuerdo; un coordinador solo los de su área (`acuerdo.area_id == su área`). **Reabrir y eliminar siguen siendo exclusivos de Dirección.** Todo intento denegado responde 403 y se audita (`intento_concluir`); toda Policy conserva su prueba negativa. *Por qué:* regla de negocio aprobada por Dirección (antes "solo Dirección concluye"; ampliada a coordinación por área el 2026-07-13).
5. **`vencido` lo asigna solo el sistema** (job diario o cálculo derivado); nunca se acepta del cliente. *Por qué:* es un estado derivado del tiempo, no una decisión humana.
6. **Toda fecha se maneja en `America/Ciudad_Juarez`** — BD, PHP (`app.timezone`), jobs y formateo en front. *Por qué:* bug real de zona horaria detectado en Portal BQS; los recordatorios dependen de "hoy" correcto.
7. **Prepared statements / Query Builder siempre**; nunca concatenar variables en SQL. **Escape de salida** en todo render (React ya escapa; prohibido `dangerouslySetInnerHTML`). *Por qué:* OWASP A03/A07 (doc 04).
8. **Transacciones en toda operación multi-tabla** (captura de lote, acuerdo+corresponsables, conclusión+auditoría): `$db->transException(true)->transStart()…transComplete()`. *Por qué:* integridad ACID (doc 03 §justificaciones).
9. **Cero N+1**: listados de acuerdos cargan corresponsables/avances con joins o `whereIn` agrupado; auditado antes de cerrar Fase 2. *Por qué:* DoD de Fase 2 (Gobernanza v3 §3).
10. **Secretos solo en `.env` / secret manager** — claves de service account de Google, config de Firebase Admin. Nunca en el repo ni en docs. *Por qué:* doc 04 §procedimientos.
11. **Conversión 1:1 del demo aprobado**: la UI React replica pixel-perfect el demo vanilla (tokens, componentes, 4 vistas) y solo añade lo aprobado (calendario, checklist, corresponsables, estados v2, recordatorios configurables). *Por qué:* el diseño ya fue validado por dirección; cambios visuales no autorizados invalidan la aprobación.

## Estructura del repositorio

```
seguimiento_acuerdos_mvp/          ← raíz del repo git
├── apps/
│   ├── api/                       ← backend CodeIgniter 4 (Fase 2)
│   └── web/                       ← frontend React (ex demo-ux/app; mock hasta Etapa 3)
├── docs/                          ← documentación (00-fuentes … 07-roadmap, ADRs, demo-ux/09)
├── scripts/verificar_espejo.mjs   ← verificación db.json ↔ DDL
├── docker-compose.yml · CLAUDE.md · README.md · .gitignore · .env.example
└── PLAN_IMPLEMENTACION.md         ← plan y estado de ejecución de la Fase 2
```

El DDL fuente de verdad vive en `docs/03-datos/panel_acuerdos_ddl.sql`; el espejo `db.json` en `apps/web/src/lib/mock/db.json`.

## Arquitectura en capas

```
[SPA React (Vite)] → HTTPS → [CI4: Filters (CORS, FirebaseAuth, Throttle)]
                                → [Controllers (validación Form Request-style)]
                                → [Services (dominio: Acuerdos, Recordatorios, GoogleSync)]
                                → [Models/Entities (Query Builder, transacciones)]
                                → [MySQL 8.4]        [Redis 7: cache tokens/resumen, rate limit]
[cron diario] → spark recordatorios:procesar → [Gmail API] [Calendar API]
```

## Comandos de arranque

```bash
# Infraestructura (raíz del proyecto; el DDL se aplica solo al primer arranque)
docker compose up -d            # mysql:8.4 + redis:7

# Verificación ejecutable db.json ↔ DDL (Gobernanza v3 §5)
node scripts/verificar_espejo.mjs

# Backend (Fase 2)
cd apps/api && composer install && cp env .env
php spark migrate && php spark db:seed InitialSeeder
php spark serve                 # http://localhost:8080

# Frontend / demo
cd apps/web && npm install
npm run dev                     # http://localhost:5173 (VITE_USE_MOCK=true)
npm run typecheck && npm run lint && npm run build

# Job de recordatorios (manual)
php spark recordatorios:procesar
```

## Identidad visual (resumen — completo en doc 08)

- Morado marca `--pj-purple-700: #53155a` (primario), lima `--pj-lime-400: #dbec57` (acento).
- Texto sobre morado: blanco; texto sobre lima: morado 700. Nunca lima sobre blanco para texto.
- Fuentes: Fredoka (display), Montserrat (cuerpo). Estados: éxito `#2e7d50`, advertencia `#b45309`, error `#c0392b`.
- Tokens CSS en `apps/web/src/styles/tokens/*.css` (base, colors, spacing, typography); mapeados a Tailwind 4 vía `@theme`.

## Orden de lectura

Todos los documentos viven bajo `docs/`:
1. `docs/00-fuentes/00_auditoria_fuentes.md` → 2. `docs/01-vision/01_SRS_*.md` + ADRs → 3. `docs/02-arquitectura/02_*.md` → 4. `docs/03-datos/03_*.md` (DDL = fuente de verdad) → 5. `docs/04-seguridad/04_*.md` → 6. `docs/05-api/05_*.md` → 7. `docs/demo-ux/09_demo_ux_guia.md` + doc 08 → 8. `docs/07-roadmap/07_*.md`.

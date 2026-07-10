# Panel de Acuerdos · Participa Juárez

| Campo | Valor |
|---|---|
| Proyecto | Panel de seguimiento de acuerdos y recordatorios automáticos |
| Organización | Plan Estratégico de Juárez (Participa Juárez) |
| Versión de la documentación | 1.0 |
| Fecha | 2026-07-08 |
| Proceso | Gobernanza v3 (v1 docs + v2 Demo-First + v3 gates) |
| Estado | Fase 2 en curso |

Aplicación a la medida para capturar acuerdos de reuniones de dirección, dar seguimiento por roles (Dirección, Coordinación, Responsable + corresponsables), y enviar recordatorios automáticos por correo con sincronización a Google Calendar. Sustituye las minutas narradas por el Formato de Reunión Operativa.

## Estado de fase

| Fase | Estado | DoD verificada |
|---|---|---|
| 0 — Documentación (00–08) | ✅ Completa (2026-07-08) | Sí |
| 1 — Demo UI/UX (React, `apps/web/`) | ✅ Completa | Sí (freeze del contrato 2026-07-09; sesión de validación con stakeholder en paralelo — hallazgos vía bitácora doc 09 §9 + ADR corto) |
| 2 — Backend (CI4 + MySQL + Redis) | ✅ Desarrollo completo (2026-07-09) | Sí, con pendientes operativos (ver checklist abajo) |

> Regla de gate: no se genera trabajo de la Fase N+1 si la Fase N tiene "DoD verificada: No", salvo excepción justificada por escrito en esta tabla.
>

## Checklist DoD Fase 2 (Gobernanza v3 §3) — firmada 2026-07-09

Backend REST completo (CI4 4.7) con auth Firebase, job de recordatorios y Gmail/Calendar (tras interfaces),
frontend real-only. Suite **212 pruebas PHPUnit verdes** + 4 vitest; revisión final de rama: APTO sin críticos.

| Criterio DoD | Estado | Evidencia |
|---|---|---|
| Cero N+1 auditado | ✅ | Listado 3 queries / detalle 7, constantes al escalar (S3.1) |
| Policies con pruebas negativas (403/401/404) | ✅ | AU-01..10, ME-12 (403 auditado), OW-* |
| Seeder desde `db.json` sin transformación | ✅ | `InitialSeeder` + `InitialSeederTest`; espejo `verificar_espejo.mjs` 11/11 |
| Transacciones en operaciones multi-tabla | ✅ | lote/concluir/reabrir/avances/config; confirmado en revisión final |
| OWASP A01–A10 re-verificado | ✅ (con notas) | `docs/04-seguridad/04b_verificacion_owasp_fase2.md`; brechas menores documentadas (rel=noopener, config de prod) |
| `api.ts` ≡ doc 05 (contrato congelado) | ✅ | doc 05 v1.3 CONGELADA (ADR-004, ADR-005) |
| Solo Dirección concluye/reabre + auditoría del 403 | ✅ | revisión final: regla OK |
| Cobertura de Services ≥ 80% | ⏳ **pendiente de herramienta** | sin driver pcov/xdebug en el entorno; comando en `docs/04-seguridad/checklist_despliegue.md`; 212 tests como red actual |

**Pendientes operativos (requieren tu intervención, no bloquean el código):**
> - **Humo real Gmail/Calendar**: configurar `GOOGLE_APPLICATION_CREDENTIALS` (service account con domain-wide
>   delegation), `GOOGLE_IMPERSONATED_USER` y `GOOGLE_CALENDAR_ID` en `apps/api/.env` y validar correo+evento reales.
> - **Métricas de carga k6**: instalar k6 y correr `apps/api/tests/perf/k6-acuerdos.js` (+ `php spark perf:seed`).
> - **Cobertura ≥80%**: instalar pcov/xdebug y correr `vendor/bin/phpunit --coverage-text`.
> - **Deploy**: hardening de `docs/04-seguridad/checklist_despliegue.md` (HTTPS/HSTS, `docker-compose.prod.yml`,
>   `CI_ENVIRONMENT=production`, CORS de producción, backups).
>
> **Nota:** existe un demo vanilla JS ya aprobado por dirección (fuente F2, ver [00_auditoria_fuentes](docs/00-fuentes/00_auditoria_fuentes.md)). La Fase 1 de este proyecto es su **conversión 1:1 a React + Vite + Tailwind** más las funciones nuevas aprobadas; la validación visual del diseño ya ocurrió.

## Stack

| Capa | Tecnología | Versión |
|---|---|---|
| Frontend | React + TypeScript | 19.x / 5.x |
| Build | Vite | 7.x |
| Estilos | Tailwind CSS (tokens Participa Juárez vía `@theme`) | 4.x |
| Datos remotos (front) | TanStack Query + interfaz `lib/api.ts` | 5.x |
| Backend | CodeIgniter 4 (PHP 8.3) | 4.7.x |
| Base de datos | MySQL (Docker) | 8.4 |
| Cache / colas ligeras / rate limit | Redis (Docker) | 7.x |
| Autenticación | Firebase Authentication (Google restringido a dominio + email/password) | — |
| Correo | Gmail API (cuenta central de planjuarez.org) | v1 |
| Calendario | Google Calendar API — calendario compartido "Acuerdos" | v3 |
| Tareas personales | Google Tasks API (OAuth por usuario, fase posterior al MVP) | v1 |

## Arquitectura en un párrafo

SPA React servida estáticamente que consume una API REST de CodeIgniter 4. El cliente obtiene un ID token de Firebase Auth y lo envía en `Authorization: Bearer`; un Filter de CI4 lo verifica contra las claves públicas de Google y resuelve al usuario local (rol, área). MySQL persiste el dominio (acuerdos, corresponsables, avances, recordatorios); Redis cachea la verificación de tokens, el resumen del panel y sirve de rate limiter. Un comando `spark` programado (cron diario, TZ America/Ciudad_Juarez) marca vencidos, materializa recordatorios según la configuración global o el override por acuerdo, envía correos vía Gmail API y sincroniza el calendario compartido de Google.

## Índice de documentos

| # | Documento | Ruta |
|---|---|---|
| 00 | Auditoría de fuentes | [docs/00-fuentes/00_auditoria_fuentes.md](docs/00-fuentes/00_auditoria_fuentes.md) |
| — | Guía operativa para agentes | [CLAUDE.md](CLAUDE.md) |
| ADR-001 | Stack del proyecto | [docs/02-arquitectura/ADR/ADR-001_stack_ci4_react.md](docs/02-arquitectura/ADR/ADR-001_stack_ci4_react.md) |
| ADR-002 | Firebase Authentication | [docs/02-arquitectura/ADR/ADR-002_firebase_auth.md](docs/02-arquitectura/ADR/ADR-002_firebase_auth.md) |
| ADR-003 | Integración Google (Gmail/Calendar/Tasks) | [docs/02-arquitectura/ADR/ADR-003_integracion_google.md](docs/02-arquitectura/ADR/ADR-003_integracion_google.md) |
| ADR-004 | Administración de áreas en el MVP (contrato v1.2) | [docs/02-arquitectura/ADR/ADR-004_administracion_areas.md](docs/02-arquitectura/ADR/ADR-004_administracion_areas.md) |
| ADR-005 | Perfil self-service (contrato v1.3) | [docs/02-arquitectura/ADR/ADR-005_perfil_usuario.md](docs/02-arquitectura/ADR/ADR-005_perfil_usuario.md) |
| 01 | SRS — Especificación de requisitos | [docs/01-vision/01_SRS_especificacion_requisitos.md](docs/01-vision/01_SRS_especificacion_requisitos.md) |
| 02 | Arquitectura del sistema | [docs/02-arquitectura/02_arquitectura_sistema.md](docs/02-arquitectura/02_arquitectura_sistema.md) |
| 03 | Modelo de datos | [docs/03-datos/03_modelo_de_datos.md](docs/03-datos/03_modelo_de_datos.md) |
| 04 | Plan de seguridad | [docs/04-seguridad/04_plan_de_seguridad.md](docs/04-seguridad/04_plan_de_seguridad.md) |
| 05 | Especificación de API (CONGELADA v1.3, 2026-07-10) | [docs/05-api/05_especificacion_api.md](docs/05-api/05_especificacion_api.md) |
| 06 | Plan de pruebas | [docs/06-pruebas/06_plan_de_pruebas.md](docs/06-pruebas/06_plan_de_pruebas.md) |
| 07 | Roadmap por sprints | [docs/07-roadmap/07_roadmap_sprints.md](docs/07-roadmap/07_roadmap_sprints.md) |
| 08 | Identidad visual y design system | [docs/01-vision/08_identidad_visual_design_system.md](docs/01-vision/08_identidad_visual_design_system.md) |
| 09 | Guía del demo UX | [docs/demo-ux/09_demo_ux_guia.md](docs/demo-ux/09_demo_ux_guia.md) |

## Decisiones clave del MVP

| Decisión | Valor | Justificación / ADR |
|---|---|---|
| Backend | CodeIgniter 4 | Estándar de la organización; equipo lo domina (ADR-001) |
| Estados de acuerdo | En proceso (default) → Vencido (automático) → Concluido | Regla de dirección; Vencido regresa a En proceso al reprogramar (SRS §7) |
| Quién concluye | Solo Dirección, desde el checklist de validación | Decisión de dirección 08-jul-2026 (H-04) |
| Corresponsables | N:M con seguimiento completo (sin concluir) | Requisito nuevo (H-02) |
| Recordatorios | Default global configurable + override por acuerdo | H-03 |
| Autenticación | Firebase Auth: Google (dominio) + email/password | ADR-002 |
| Correo | Gmail API con cuenta central | ADR-003 |
| Google Calendar | Calendario compartido central desde el día 1 | ADR-003 (modelo híbrido) |
| Google Tasks | OAuth por usuario — **post-MVP** | ADR-003 |
| Acuerdos concluidos | Ocultos por defecto; visibles solo filtrando por Concluido | Requisito de dirección |
| Vista calendario | Vista mensual dentro del panel (además de tabla/kanban/reunión/gantt) | Requisito nuevo |
| Zona horaria | America/Ciudad_Juarez en BD, backend y jobs | Lección Portal BQS |

## Cómo leer esta documentación

1. Lee [00_auditoria_fuentes](docs/00-fuentes/00_auditoria_fuentes.md) para entender qué cambió entre la propuesta aprobada, el demo y las reglas finales.
2. Sigue con el [SRS](docs/01-vision/01_SRS_especificacion_requisitos.md) (qué hace el sistema y para quién) y los tres ADRs (por qué el stack es este).
3. Con el dominio claro, revisa [arquitectura](docs/02-arquitectura/02_arquitectura_sistema.md) y [modelo de datos](docs/03-datos/03_modelo_de_datos.md) — el DDL es la fuente de verdad que `db.json` espeja.
4. Antes de escribir código de backend, lee [plan de seguridad](docs/04-seguridad/04_plan_de_seguridad.md) y [especificación de API](docs/05-api/05_especificacion_api.md).
5. Para trabajar en el frontend, usa [design system](docs/01-vision/08_identidad_visual_design_system.md) + [guía del demo](docs/demo-ux/09_demo_ux_guia.md); el orden de construcción vive en el [roadmap](docs/07-roadmap/07_roadmap_sprints.md).

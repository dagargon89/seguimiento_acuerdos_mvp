# ADR-001 — Stack del proyecto: CI4 + React + MySQL + Redis

| Campo | Valor |
|---|---|
| Documento | ADR-001 |
| Versión | 1.0 |
| Fecha | 2026-07-08 |
| Estado | Aceptada |
| Depende de | 00_auditoria_fuentes |

## 1. Contexto

La propuesta v5 fue aceptada por dirección y el demo vanilla JS aprobado. Hay que elegir el stack de producción. Restricciones: el hosting ya disponible corre PHP/MySQL; el equipo mantiene otros sistemas de Plan Juárez en CodeIgniter 4 (Portal Ejecutivo BQS, Sistema MEL); la metodología Demo-First exige que el demo React se promueva a producción sin retrabajo; se requieren jobs diarios (recordatorios), correo (Gmail API), Firebase Auth y sincronización con Google Calendar.

## 2. Decisión

Frontend **React 19 + TypeScript + Vite 7 + Tailwind 4 + TanStack Query 5**. Backend **CodeIgniter 4.7 (PHP 8.3)** como API REST. **MySQL 8.4** y **Redis 7** en Docker. Jobs con comandos `spark` + cron.

| Criterio | CodeIgniter 4 (elegido) | Laravel 11/12 | Node (Fastify) |
|---|---|---|---|
| Conocimiento del equipo | ✅ Alto (BQS, MEL) | Medio | Medio |
| Compatibilidad hosting actual | ✅ Total | Total | Requiere runtime Node |
| Verificación Firebase ID token | Librería `kreait/firebase-php` o JWT manual | `kreait/laravel-firebase` | SDK Admin oficial |
| Google APIs | `google/apiclient` oficial PHP | Igual | SDK oficial JS |
| Colas | No nativas → cron + Redis como buffer | Nativas (Horizon) | BullMQ |
| Peso / superficie | ✅ Mínima | Mayor | Media |
| Consistencia con estándares PJ | ✅ Estándar vigente | — | — |

El volumen del sistema (decenas de acuerdos por semana, ~20 usuarios) no justifica colas robustas: un job diario secuencial vía `spark` cubre recordatorios y sincronización; Redis se usa para cache (verificación de tokens, resumen de panel) y rate limiting con el `RateLimiter`/cache handler de CI4.

## 3. Mapeo de conceptos (demo vanilla → producción)

| Demo vanilla | Producción |
|---|---|
| `js/app.js` render por template strings | Componentes React + páginas por ruta |
| `state` global + `setState()` | Estado de servidor: TanStack Query; estado de UI: `useState`/`useReducer` |
| `js/usuarios.js` permisos en cliente | Fuente de verdad en backend (Policies en Services CI4); el front solo oculta UI |
| `CONFIG.diasRecordatorio` | Tabla `configuracion` + override `acuerdos.recordatorio_dias` |
| Datos en memoria | MySQL + `db.json` espejo en el demo |
| Simulación de correo | Gmail API real + registro en `recordatorios_enviados` |

## 4. Consecuencias

**Positivas:** un solo patrón de backend en toda la organización; despliegue en hosting existente sin costos nuevos; demo React promovible 1:1 a `apps/web`; Docker iguala entornos de desarrollo.

**Negativas:** CI4 no trae colas ni scheduler nativos — se documenta el cron y el comando `spark` como parte del despliegue (doc 02 §despliegue); la verificación de Firebase requiere librería externa o verificación JWT manual con cache de claves públicas (ADR-002).

**Neutrales:** TanStack Query introduce una dependencia más en el front, pero es la prevista por la metodología Demo-First v2.

## 5. Impacto en documentos

Define el stack de README, CLAUDE.md, y condiciona docs 02 (capas), 03 (DDL MySQL 8.4), 04 (controles por capa), 05 (convenciones REST) y 07 (sprints de Fase 2).

## 6. Implicaciones de seguridad

PHP 8.3 con tipado estricto; Query Builder con binding obligatorio; Filters de CI4 para CORS/auth/throttle; Redis sin exposición pública (red interna de Docker); `.env` fuera del control de versiones.

## 7. Plan de migración

No aplica (proyecto nuevo). La "migración" interna es la promoción `demo-ux/app/` → `apps/web/` definida en la metodología Demo-First v2.

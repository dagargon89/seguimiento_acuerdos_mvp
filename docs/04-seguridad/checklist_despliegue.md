# Checklist de hardening / despliegue — Panel de Acuerdos

| Campo | Valor |
|---|---|
| Documento | Checklist de hardening de despliegue (S3.2), derivado de `04_plan_de_seguridad.md` §4 "Procedimientos operativos" |
| Versión | 1.0 |
| Fecha | 2026-07-09 |
| Alcance | Este entorno es de **desarrollo** (`apps/api/env` sin `CI_ENVIRONMENT` activo, sin TLS, `docker-compose.yml` con puertos publicados). No hay entorno de producción desplegado todavía — este checklist se ejecuta y firma en el momento del primer despliegue real. |

Convención: ✅ hecho y verificado en este entorno · ⏳ pendiente-deploy (requiere el entorno de producción real para aplicarse/verificarse) · 🔶 parcialmente hecho (código listo, falta activarlo en producción).

## 1. Entorno

| Ítem | Estado | Detalle |
|---|---|---|
| `CI_ENVIRONMENT=production` en prod | ⏳ pendiente-deploy | Hoy `development` en este entorno (`apps/api/env` línea 17, comentada). Debe fijarse en el `.env` real del servidor de producción — sin esto, CI4 puede mostrar stack traces al cliente. |
| `display_errors=0` / sin stack traces al cliente | 🔶 | Cubierto automáticamente por CI4 cuando `CI_ENVIRONMENT=production` (`app/Config/Boot/production.php`); no verificable sin ese entorno real. |

## 2. HTTPS / HSTS

| Ítem | Estado | Detalle |
|---|---|---|
| HTTPS forzado (`app.forceGlobalSecureRequests = true`) | ⏳ pendiente-deploy | Hoy `false` en `app/Config/App.php` (dev sin TLS). Activar en producción junto con el certificado real (o el proxy/balanceador que termina TLS). |
| Header `Strict-Transport-Security` | ✅ (código) | **Implementado en esta tarea (S3.2):** `app/Filters/SecurityHeadersFilter.php` agrega `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload` **solo cuando `$request->isSecure()` es verdadero** (HTTPS real). En HTTP (dev/CI) no se envía — verificado por `FiltersDeBordeTest::testHSTSAusenteEnHttpAunEnProduccion`. En HTTPS real, se envía — verificado por `testHSTSPresenteCuandoLaRequestEsHttps` (simulando `$_SERVER['HTTPS']` vía el servicio `superglobals`). Si el TLS lo termina un proxy/balanceador reenviando por HTTP interno, el proxy debe fijar la variable que CI4 lee en `Request::isSecure()` (`$_SERVER['HTTPS']` o cabecera de confianza) para que el header se siga enviando. |

## 3. CORS

| Ítem | Estado | Detalle |
|---|---|---|
| Lista blanca de orígenes desde `.env` | ✅ | `CORS_ALLOWED_ORIGINS` en `app/Config/Cors.php`, sin wildcard `*`. Dev: `http://localhost:5173`. |
| **Poner el dominio real de producción en `CORS_ALLOWED_ORIGINS`** | ⏳ pendiente-deploy | Recordatorio explícito: al desplegar, cambiar la variable en el `.env` del servidor al dominio HTTPS real de la SPA (ej. `https://panel.planjuarez.org`) — de lo contrario el frontend en producción no podrá llamar a la API. |

## 4. Puertos de infraestructura (MySQL / Redis)

| Ítem | Estado | Detalle |
|---|---|---|
| Puertos de MySQL/Redis no publicados en prod | ✅ (override listo) | `docker-compose.prod.yml` (nuevo, esta tarea) quita `ports:` de `mysql` y `redis` con la sintaxis `!reset []` de la Compose Specification — verificado con `docker compose -f docker-compose.yml -f docker-compose.prod.yml config` (sin él, un `ports: []` simple NO limpia la lista del compose base). Uso: `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d`. |
| Redis con `requirepass` | ✅ | Ya en `docker-compose.yml` línea 31 (`--requirepass ${REDIS_PASSWORD:-dev_redis_local}`); en prod, definir `REDIS_PASSWORD` real en el `.env` del servidor (nunca el default `_local`). |
| MySQL usuario de app con permisos mínimos | 🔶 | `MYSQL_USER=panel_app` ya separado de `root` en el compose; falta verificar en el servidor real que ese usuario no tenga `DROP`/`GRANT` (pendiente-deploy, no verificable desde este entorno). |

## 5. Secretos

| Ítem | Estado | Detalle |
|---|---|---|
| `.env` fuera de git | ✅ | Confirmado con `git check-ignore -v apps/api/.env apps/web/.env` → ambos matcheados por `apps/api/.gitignore:44` y `apps/web/.gitignore:4` respectivamente. |
| Patrón `*service-account*.json` gitignored | ✅ | Confirmado en `.gitignore` raíz línea 5 (`*service-account*.json`) + `credentials/` línea 6. No se encontró ningún archivo `*service-account*.json` en el árbol del repo (`find` sin resultados). |
| Clave de service account fuera del repo, ruta vía variable | ⏳ pendiente-deploy | `GOOGLE_APPLICATION_CREDENTIALS` en `apps/api/env` línea 89 (comentada, sin valor real en este entorno). En producción debe apuntar a una ruta privada del servidor (permisos 600), nunca dentro del árbol del repo. |
| `.env` con permisos 600 fuera del webroot | ⏳ pendiente-deploy | No verificable en este entorno (no hay servidor real); recordatorio operativo para el despliegue. |
| Rotación de secretos (service account cada 6 meses, `encryption.key` con migración documentada) | ⏳ pendiente-deploy | Procedimiento operativo (doc 04 §4), no aplica hasta que exista un secreto real desplegado que rotar. |

## 6. Backups

| Ítem | Estado | Detalle |
|---|---|---|
| Backups automáticos del volumen `mysql_data` | ⏳ pendiente-deploy | Nota operativa: el volumen nombrado `mysql_data` (`docker-compose.yml` línea 43) persiste los datos fuera del ciclo de vida del contenedor, pero **no implica backup**. En producción, programar `mysqldump` (o snapshot del volumen) diario con retención acorde a la política de auditoría (24 meses, doc 04 §4) y **probar la restauración** al menos una vez antes de considerar el backup confiable. |

## 7. Dependencias (gate de cada sprint)

| Ítem | Estado | Detalle |
|---|---|---|
| `composer audit` sin advisories | ✅ | Ejecutado 2026-07-09: **"No security vulnerability advisories found."** |
| `npm audit` sin vulnerabilidades | ✅ | Ejecutado 2026-07-09: **"found 0 vulnerabilities"**. No fue necesario `npm audit fix` (0 hallazgos). |

## 8. Cobertura de pruebas (gate ≥80%, DoD Fase 2)

| Ítem | Estado | Detalle |
|---|---|---|
| Cobertura de Services ≥80% (`phpunit --coverage-text`) | ⏳ **requiere driver de cobertura** | Este entorno **no tiene** pcov ni Xdebug instalados (sin `pecl`/`phpize` disponibles para instalarlos) — `vendor/bin/phpunit` reporta el warning `No code coverage driver available` en cada corrida. **No se puede medir el porcentaje real aquí.** Comando para medirlo en una máquina/CI con el driver instalado: `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text` (con Xdebug) o instalando `pcov` (más rápido, solo cobertura) y corriendo `vendor/bin/phpunit --coverage-text` directo. **Lo que sí se verificó en este entorno:** la suite completa tiene **206 tests / 842 assertions, 206/206 verde** (204 pre-existentes + 2 nuevos de HSTS agregados en esta tarea), cubriendo dominio (estados, transiciones), permisos (AU-*, ME-12, AU-07), job de recordatorios (RE-*), plantillas de correo, sincronización de calendario (GC-*) y filtros de borde (CORS, throttle, headers). Se recomienda instalar `pcov` en el pipeline de CI (no en esta máquina de desarrollo) antes de declarar el gate cumplido. |

## 9. OWASP re-verificado

Ver `04b_verificacion_owasp_fase2.md` (documento hermano de este checklist) para el detalle A01–A10 con controles implementados y tests que los cubren.

## Resumen ejecutivo

| Categoría | Hecho hoy | Pendiente de entorno de producción real |
|---|---|---|
| Código de hardening | HSTS condicional, CORS lista blanca, headers de seguridad, throttle, override de Compose sin puertos publicados | — |
| Auditorías | `composer audit` / `npm audit` limpios, OWASP re-verificado | — |
| Config de despliegue | — | `CI_ENVIRONMENT=production`, `forceGlobalSecureRequests=true`, dominio real en `CORS_ALLOWED_ORIGINS`, permisos de `.env` 600, ruta real de la clave de service account, backups automáticos + restauración probada |
| Herramienta faltante | — | Driver de cobertura (pcov/Xdebug) para medir el gate ≥80% — la suite (206 tests) ya existe y está verde, falta el instrumento de medición |

**Ningún ítem pendiente bloquea el cierre de Fase 2 en este entorno de desarrollo**: son, por naturaleza, acciones que solo tienen sentido al desplegar sobre infraestructura real (dominio, TLS, servidor con `pecl`, etc.).

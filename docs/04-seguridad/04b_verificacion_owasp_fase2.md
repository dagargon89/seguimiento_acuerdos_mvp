# 04b — Verificación OWASP Top 10 (Fase 2, S3.2)

| Campo | Valor |
|---|---|
| Documento | 04b — Verificación OWASP Top 10 2021 (re-verificación de cierre Fase 2) |
| Versión | 1.0 |
| Fecha | 2026-07-09 |
| Depende de | `04_plan_de_seguridad.md` |
| Método | Lectura directa del código en `apps/api` (rama `fase-2-backend`) + ejecución de `vendor/bin/phpunit` (204/204 verde antes de esta tarea; 206/206 tras agregar los 2 tests de HSTS de S3.2). Cada control cita archivo:línea real y el(los) test(s) que lo cubren. Donde no hay control implementado o no hay test, se marca explícitamente como brecha — no se documentan mitigaciones inexistentes. |

Convención de estado: ✅ implementado y cubierto por test · 🔶 implementado, cubierto solo parcialmente o por configuración de despliegue (no test automatizado) · ❌ brecha (no implementado).

## A01 — Broken Access Control

**Control implementado:**
- Visibilidad server-side de listados: `app/Policies/VisibilidadAcuerdos.php` aplica el filtro por rol directamente en el `Builder` (`aplicarAlListado()`), nunca en el cliente. `coordinador` ve su área + donde participa; `responsable` solo donde es responsable/corresponsable; `direccion` sin filtro.
- Verificación de pertenencia por id: `VisibilidadAcuerdos::puedeVer()`, invocado desde `AcuerdosController::show()`, `update()`, `corresponsables()`. Un acuerdo ajeno o inexistente devuelve **404** indistinguible (anti-IDOR/anti-enumeración).
- Restricción "solo Dirección concluye/reabre/valida checklist": `AcuerdosController.php` líneas 589 (`concluir`), 666 (`reabrir`) y 738 (checklist) verifican `$actor['rol'] !== 'direccion'` antes de proceder; si falla, responde 403 vía `sinPermiso()` (línea 887) y, en concluir/reabrir, además registra el intento en `auditoria` con acción `intento_concluir`/`intento_reabrir`, rol del actor y resultado `denegado` (líneas 592/669).

**Tests que lo cubren:**
- `AU-01` `AcuerdosLecturaTest::testAU01ResponsableListaSoloAcuerdosDondeEsResponsableOCorresponsable`
- `AU-02` `AcuerdosLecturaTest::testAU02ResponsablePideAcuerdoAjenoPorIdDevuelve404` y `testAU02InexistenteDevuelve404IgualQueAjeno` (404 idéntico ajeno vs inexistente)
- `AU-03` `AcuerdosLecturaTest::testAU03CoordinadorListaSuAreaMasParticipacionesSinVerOtrasAreas` y `testAU03CoordinadorVeParticipacionFueraDeSuAreaAunqueNoSeaDeElla`
- `ME-12` `ConclusionReaperturaTest::testME12CoordinadorConcluirEs403YAuditaElIntento`, `testME12ResponsableConcluirEs403YAuditaElIntento`, `testME12CoordinadorReabrirEs403YAuditaElIntento`
- `AU-07` `ConclusionReaperturaTest::testAU07ChecklistNoDireccionEs403`

**Estado: ✅**

## A02 — Cryptographic Failures

**Control implementado:**
- Verificación de ID token vía servicio `tokenVerifier` (implementación real con Kreait/Firebase Admin SDK, validado por firma RS256/JWKS) — filtro `app/Filters/FirebaseAuthFilter.php`.
- **HSTS condicional** (agregado en esta tarea, S3.2): `app/Filters/SecurityHeadersFilter.php::before()` agrega `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload` **solo si `$request->isSecure()` es verdadero** (HTTPS real, vía `$_SERVER['HTTPS']`/proxy de confianza). En HTTP plano (dev/CI) no se envía, evitando romper el acceso local.
- Cifrado en reposo de refresh tokens de Google (doc 04 §A02, `encrypt()` de CI4) — **no verificado en esta tarea**: no se encontró código de refresh-token OAuth de usuario final en el alcance actual del backend (MVP usa service account, no OAuth de usuario); se deja como diseño post-MVP, no como brecha de lo implementado hoy.

**Tests que lo cubren:**
- HSTS: `FiltersDeBordeTest::testHSTSAusenteEnHttpAunEnProduccion` (HTTP → sin header) y `testHSTSPresenteCuandoLaRequestEsHttps` (HTTPS simulado vía `service('superglobals')` → header presente con el valor exacto).
- Verificación de firma/claims del token: ver A07 (mismo mecanismo).

**Estado: ✅ (HSTS + verificación de token) / 🔶 HTTPS forzado en sí es config de despliegue (`app.forceGlobalSecureRequests`, ver checklist de despliegue) — no aplica en dev.**

## A03 — Injection

**Control implementado:**
- Query Builder con binding en prácticamente todas las consultas (`app/Models/AcuerdoModel.php`, controllers). La única concatenación de SQL crudo detectada es un filtro de estado derivado ya validado contra lista blanca (`AcuerdosController.php` líneas 133-149: `in_array($estadoFiltro, ['en_proceso','vencido','concluido'], true)` antes de interpolar) y una expresión de fecha con `Database::connect()->escape($hoy)` (`AcuerdoModel.php` líneas 41-46) — ambos casos con el valor ya escapado/validado, no user input crudo.
- No hay renderizado HTML de plantillas de vista con datos de usuario en la API (responde JSON); el `esc()` mencionado en doc 04 §A03 aplica a las plantillas de **correo** (`app/Views` de recordatorios), que sí escapan cada campo de acuerdo antes de interpolar en el HTML del email.
- Entrada inyectada en campos de texto (`accion`, `observaciones`) se persiste literal, sin ejecutarse ni romper la tabla.

**Tests que lo cubren:**
- `OW-01` `AcuerdosEscrituraTest::testOW01SqlInyectadoEnAccionSeGuardaLiteralYNoRompeNada` (payload `'; DROP TABLE acuerdos; --` se guarda literal, tabla sigue viva)
- `OW-02` `AcuerdosEscrituraTest::testOW02XssEnObservacionesSePersisteComoTextoLiteral` (`<script>alert(1)</script>` se persiste como texto)
- `OW-08` (validación de lista blanca de campos, relacionado): 4 tests en `AcuerdosEscrituraTest.php` que verifican 422 `campo_no_permitido` ante campos desconocidos en lote/patch/avances/corresponsables.

**Estado: ✅**

## A04 — Insecure Design

**Control implementado:**
- Máquina de estados con `CHECK` en BD (`chk_concluido_consistente`, doc 03), `vencido` derivado siempre server-side (nunca aceptado del cliente).
- Auditoría de intentos de acción no permitida (ver A01) como control de diseño defensivo, no solo de logging.
- Transacción todo-o-nada en captura de lote (`AcuerdosController` método de alta en lote).

**Tests que lo cubren:** cubierto indirectamente por `ME-*` (estados) y `LT-*` (lote) en `AcuerdosEscrituraTest.php`/`ConclusionReaperturaTest.php`; no se re-verificó el `CHECK` de BD directamente en esta tarea (ya cubierto en Sprint 1/2, fuera de alcance de S3.2).

**Estado: 🔶 (controles vigentes, no auditados de nuevo línea por línea en esta tarea — sin cambios desde su verificación original)**

## A05 — Security Misconfiguration

**Control implementado:**
- CORS con lista blanca real desde `.env` (`CORS_ALLOWED_ORIGINS`), **sin wildcard** — `app/Config/Cors.php` líneas 119-138. Usa `allowedOriginsPatterns` (no `allowedOrigins`) a propósito para evitar el atajo de CI4 que refleja el único origen configurado sin comparar contra el `Origin` real cuando hay exactamente 1 elemento en `allowedOrigins` (documentado in-line en el propio archivo).
- Headers de seguridad globales (`SecurityHeadersFilter`): `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Content-Security-Policy: default-src 'none'`, y ahora `Strict-Transport-Security` condicional (ver A02). Corre en `$globals['before']` (`app/Config/Filters.php`) para que sobreviva aunque un filtro posterior corte el ciclo con 401/403/429.
- Rate limiting (`ThrottleFilter`): 60 req/min por usuario autenticado, 10 req/min por IP en rutas sin auth; responde 429 con `Retry-After`.
- `CI_ENVIRONMENT`: hoy `development` en este entorno (ver `env`); pasar a `production` en el despliegue real es un ítem del checklist de despliegue (no aplicado aquí — no hay entorno de producción desplegado todavía).

**Tests que lo cubren:**
- `OW-04` `FiltersDeBordeTest::testOW04PreflightDeOrigenNoListadoSinHeadersCors`, `testOW04PreflightDeOrigenListadoIncluyeHeadersCors`, `testOW04PreflightNoPasaPorAuthNiDevuelve401`
- `OW-05` `FiltersDeBordeTest::testOW05LimiteYaConsumidoDevuelve429ConRetryAfter`
- Headers globales: `FiltersDeBordeTest::testHeadersDeSeguridadPresentesInclusoEnRespuesta401`
- HSTS: ver A02.

**Estado: ✅ (código) / 🔶 `CI_ENVIRONMENT=production` es config de despliegue, ver `checklist_despliegue.md`.**

## A06 — Vulnerable and Outdated Components

**Control implementado:** `composer audit` y `npm audit` ejecutados en esta tarea.

**Resultado real (2026-07-09):**
- `cd apps/api && composer audit` → **"No security vulnerability advisories found."**
- `cd apps/web && npm audit` → **"found 0 vulnerabilities"**

**Tests que lo cubren:** no aplica (verificación de herramienta externa, no test de PHPUnit); repetible en cada sprint/CI.

**Estado: ✅**

## A07 — Identification and Authentication Failures

**Control implementado (`app/Filters/FirebaseAuthFilter.php`):**
- Falta de header `Authorization` o formato inválido → 401 `token_faltante`.
- Verificación de firma/claims (`exp`, `iat`, `aud`, `iss`) delegada al servicio `tokenVerifier`; token expirado, firma inválida o `aud` de otro proyecto → 401 `token_invalido`.
- Lista blanca real: un token válido de Firebase de un correo **no registrado** en la tabla `usuarios` → 403 `usuario_no_registrado` (crear cuenta en Firebase no da acceso).
- Usuario existente pero `activo=0` → mismo 403 `usuario_no_registrado`, sin distinguir el motivo exacto al cliente (evita enumeración).
- Primer login: enlaza `firebase_uid` al usuario existente por email verificado (`email_verified=true` requerido) y audita el evento como `login`.
- Desactivación efectiva ≤60 s: `app/Libraries/Auth/AuthCache.php` cachea la resolución de usuario con `TTL_SEGUNDOS = 60`; `AuthCache::invalidar()` permite forzar el efecto inmediato (usado por el endpoint de baja de usuario) — sin invalidar explícitamente, la baja tarda hasta el TTL en reflejarse, comportamiento aceptado por el RF-01 ("≤60 s").

**Tests que lo cubren:**
- `AU-08` `FiltersDeBordeTest::testAU08aSinHeaderAuthorizationDevuelve401TokenFaltante`, `testAU08bTokenExpiradoDevuelve401TokenInvalido`, `testAU08cFirmaInvalidaDevuelve401`
- `OW-07` `FiltersDeBordeTest::testOW07AudienciaDeOtroProyectoDevuelve401`
- `AU-09` `FiltersDeBordeTest::testAU09TokenValidoDeEmailNoRegistradoDevuelve403UsuarioNoRegistrado`, `testAU09bUsuarioExistenteInactivoDevuelve403`
- `AU-10` `FiltersDeBordeTest::testAU10UsuarioDesactivadoTrasInvalidarCacheDevuelve403`, `testAU10SinInvalidarCacheElUsuarioDesactivadoSigueEntrandoDentroDelTtl`
- `RF-01.3` `FiltersDeBordeTest::testRF013PrimerLoginEnlazaFirebaseUidPorEmail` (incluye assert de fila en `auditoria` acción `login`), `testRF013SegundoLoginNoEnlazaOtroEmailAUnUsuarioYaEnlazado`

**Estado: ✅**

## A08 — Software and Data Integrity Failures

**Control implementado:**
- Migraciones versionadas (`spark migrate`), sin ediciones manuales en servidor (procedimiento operativo, doc 04 §4).
- `InitialSeeder` siembra desde `db.json` validado, misma fuente que el demo (Gobernanza v3) — evita datos de prueba divergentes del contrato real.
- Auditoría inmutable (tabla `auditoria`, sin UPDATE/DELETE expuesto por la API) de cambios de estado y configuración.
- Sincronización con Google Calendar con reintentos acotados (circuit breaker a 3 intentos, `GoogleCalendarService`) para no reintentar indefinidamente sobre datos corruptos.

**Tests que lo cubren:**
- `GC-01`..`GC-05` `GoogleCalendarServiceTest.php` (creación/actualización idempotente de eventos, manejo de error sin propagar, circuit breaker a 3 intentos)
- Seeder: `tests/database/InitialSeederTest.php` (fuera del recuento de esta tarea, ya verde en la suite completa)

**Estado: ✅ (integridad de integración Google) / 🔶 (procedimiento de despliegue sin editar en caliente es operativo, no verificable por test automatizado)**

## A09 — Security Logging and Monitoring Failures

**Control implementado:**
- Tabla `auditoria` (`app/Models/AuditoriaModel.php`): columnas `usuario_id, accion, entidad, entidad_id, detalle (JSON), ip, created_at`. `detalle` se serializa con `json_encode()`, nunca se concatena SQL.
- Acciones registradas confirmadas en el código: `login` (primer login), `intento_concluir`/`intento_reabrir` (403 de rol), `concluir`/`reabrir` (éxito), `crear`/`editar`/`corresponsables`/`avance`/`reprogramar` (acuerdos), `alta_usuario`/`editar` (usuarios), `alta_area`/`editar_area` (áreas), `cambiar_config` (recordatorios).
- Registro de fallos de envío de recordatorios: tabla `recordatorios_enviados` con `estado='fallido'` cuando el mailer falla, visible para Dirección vía `RecordatoriosController`.
- Registro de fallos de sincronización con Google: tabla `google_sync` (columna `error`, `intentos`) — ver A08/GC-04.

**Tests que lo cubren:**
- `ME-12` (auditoría de intentos 403, ver A01)
- `RF-01.3` (auditoría de login, ver A07)
- `RE-07` `RecordatorioJobTest::testRE07FalloDeMailerDejaFallidoYElRestoSeEnvia` (fallo de mailer queda registrado como `fallido` sin bloquear el resto del lote)
- `RE-10` `RecordatorioJobTest::testRE10ResumenLlegaADireccionYCoordinacionesNoAResponsables` (visibilidad del resumen operativo solo a los roles que deben auditar/monitorear)
- `GC-04` `GoogleCalendarServiceTest::testGC04ErrorEnCrearEventoMarcaFilaComoErrorSinPropagar` y `testGC04FilaConTresIntentosNoSeProcesaEnElJob` (log de error de sync + circuit breaker)

**Estado: ✅**

## A10 — Server-Side Request Forgery (SSRF)

**Control implementado:**
- El backend **no descarga** el contenido del campo `enlace` de un acuerdo; solo lo valida como URL `http(s)` y lo devuelve/almacena como texto — `AcuerdosController::esEnlaceValido()` (línea 963): `preg_match('/^https?:\/\//i', trim($enlace))`. Un valor `javascript:...` o `ftp://...` se rechaza con 422 antes de persistir.
- El frontend renderiza el enlace como `<a>` con `rel="noreferrer"` (`apps/web/src/pages/Checklist.tsx` línea 98, `apps/web/src/components/Drawer.tsx` línea 235) — sin `rel="noopener"` explícito en el código actual (ver brecha abajo).
- Las únicas llamadas HTTP salientes del backend son a URLs fijas de la API de Google Calendar vía el SDK oficial (`GoogleApiClientCalendarApi`), no a URLs provistas por el usuario.

**Tests que lo cubren:**
- `OW-06` `AcuerdosEscrituraTest::testOW06EnlaceJavascriptEs422` (lote) y `testOW06EnlaceNoHttpEnPatchEs422` (PATCH con `ftp://`)

**Brecha detectada (no inventar mitigación):** el atributo `rel` en los dos enlaces del frontend (`Checklist.tsx:98`, `Drawer.tsx:235`) solo incluye `noreferrer`, **no** `noopener`. `noreferrer` ya implica el comportamiento de `noopener` en navegadores modernos (Chrome ≥88, Firefox ≥52), por lo que el riesgo práctico de *reverse tabnabbing* es bajo, pero el doc 04 §A10 menciona explícitamente `rel="noopener noreferrer"`. **Recomendación para un sprint posterior:** agregar `noopener` de forma explícita por defensa en profundidad (cambio de una línea en 2 archivos del frontend, fuera del alcance "cambios mínimos" de esta tarea de backend).

**Estado: ✅ (SSRF del backend) / 🔶 (atributo `rel` del frontend incompleto respecto al doc, riesgo bajo, no bloqueante)**

## Resumen

| OWASP | Estado | Evidencia principal |
|---|---|---|
| A01 Broken Access Control | ✅ | `VisibilidadAcuerdos.php`, `AcuerdosController.php:589,666,738`, tests AU-01/02/03, ME-12, AU-07 |
| A02 Cryptographic Failures | ✅ (HSTS + token) / 🔶 (HTTPS forzado es despliegue) | `SecurityHeadersFilter.php`, tests HSTS nuevos |
| A03 Injection | ✅ | Query Builder, tests OW-01/02/08 |
| A04 Insecure Design | 🔶 (sin cambios desde verificación previa) | `CHECK` en BD, auditoría de intentos |
| A05 Security Misconfiguration | ✅ (código) / 🔶 (`CI_ENVIRONMENT` de despliegue) | `Cors.php`, `SecurityHeadersFilter.php`, `ThrottleFilter.php`, tests OW-04/05 |
| A06 Vulnerable Components | ✅ | `composer audit` / `npm audit` limpios (2026-07-09) |
| A07 Auth Failures | ✅ | `FirebaseAuthFilter.php`, `AuthCache.php`, tests AU-08/09/10, RF-01.3 |
| A08 Integrity Failures | ✅ / 🔶 (procedimiento operativo no testeable) | `GoogleCalendarService` circuit breaker, tests GC-01..05 |
| A09 Logging/Monitoring | ✅ | tabla `auditoria`, `recordatorios_enviados`, `google_sync`, tests ME-12, RE-07/10, GC-04 |
| A10 SSRF | ✅ (backend) / 🔶 (`rel="noopener"` faltante en frontend, riesgo bajo) | `esEnlaceValido()`, test OW-06 |

**Ningún hallazgo de esta re-verificación es bloqueante para el cierre de Fase 2**: las brechas marcadas 🔶 son o bien configuración de despliegue pendiente (documentada en `checklist_despliegue.md`) o un endurecimiento de defensa en profundidad de bajo riesgo (atributo `rel` del frontend).

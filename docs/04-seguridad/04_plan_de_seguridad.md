# 04 — Plan de Seguridad

| Campo | Valor |
|---|---|
| Documento | 04 — Plan de Seguridad (OWASP Top 10 2021 + ASVS) |
| Versión | 1.0 |
| Fecha | 2026-07-08 |
| Depende de | 01_SRS, 02_arquitectura, 03_modelo_de_datos, ADR-002/003 |

## 1. Activos a proteger y actores de amenaza

**Activos:** acuerdos y avances (información operativa interna de dirección), datos personales mínimos del equipo (nombre, correo), credenciales de service account de Google (permiten enviar correo y escribir calendario del dominio), configuración de Firebase, tokens OAuth de usuarios (post-MVP), registros de auditoría.

**Actores:** (a) atacante externo oportunista (bots, scraping, credential stuffing sobre email/password); (b) usuario interno que intenta exceder su rol (ver/concluir acuerdos ajenos); (c) exempleado con token aún válido; (d) atacante con acceso al repositorio o al hosting buscando secretos.

## 2. Riesgos OWASP y controles

### A01 — Broken Access Control (riesgo principal)
Amenaza: responsable que lee/edita acuerdos de otros; coordinador que concluye; IDOR por id secuencial.
Control: toda consulta de listado se filtra server-side por visibilidad del actor, y todo acceso por id re-verifica pertenencia; `concluir/reabrir` exige rol `direccion` (regla №4 CLAUDE.md). Pruebas negativas 403 obligatorias (doc 06).

```php
// AcuerdoModel — la visibilidad ES la consulta (nunca filtrado en cliente)
public function visiblesPara(Usuario $u): array
{
    $b = $this->builder('acuerdos a')
        ->select('a.*')
        ->distinct()
        ->join('acuerdo_corresponsables ac', 'ac.acuerdo_id = a.id', 'left');

    if ($u->rol === 'coordinador') {
        $b->groupStart()
            ->where('a.area_id', $u->area_id)
            ->orWhere('a.responsable_id', $u->id)
            ->orWhere('ac.usuario_id', $u->id)
          ->groupEnd();
    } elseif ($u->rol === 'responsable') {
        $b->groupStart()
            ->where('a.responsable_id', $u->id)
            ->orWhere('ac.usuario_id', $u->id)
          ->groupEnd();
    } // direccion: sin filtro
    return $b->get()->getResult();
}
```

### A02 — Cryptographic Failures
Control: HTTPS forzado (`forceGlobalSecureRequests = true` + HSTS); refresh tokens de Google cifrados con `encrypt()` de CI4 (AES-256, clave en `.env`); no se almacenan contraseñas propias (delegadas a Firebase); backups de MySQL cifrados en reposo.

```php
// GoogleTokenService — cifrado en reposo del refresh token (ADR-003)
public function guardar(int $usuarioId, string $refreshToken, string $scopes): void
{
    $this->db->table('usuario_google_tokens')->replace([
        'usuario_id'            => $usuarioId,
        'refresh_token_cifrado' => base64_encode(service('encrypter')->encrypt($refreshToken)),
        'scopes'                => $scopes,
    ]);
}
```

### A03 — Injection (SQL / correo)
Control: Query Builder con binding en el 100% de las consultas (prohibido `query()` con concatenación); validación de entrada tipada en Controllers; el HTML de los correos se construye con plantilla escapando cada campo del acuerdo (el contenido lo escriben usuarios).

```php
// Correcto: binding automático                     // PROHIBIDO:
$this->db->table('acuerdos')                        // $db->query("SELECT ... WHERE tema = '$tema'");
    ->where('estado', $estado)
    ->like('accion', $q)
    ->get();

// Plantilla de correo: todo campo pasa por esc() de CI4 antes de interpolarse
$html = view('emails/recordatorio', [
    'accion' => esc($acuerdo->accion),   // neutraliza <script> u HTML inyectado en la captura
    'tema'   => esc($acuerdo->tema ?? ''),
]);
```

### A04 — Insecure Design
Control: máquina de estados blindada también en BD (CHECK `chk_concluido_consistente`); `vencido` no aceptado del cliente (422); avances inmutables como evidencia; idempotencia del job (UNIQUE natural en `recordatorios_enviados`); todo-o-nada en captura de lote (transacción).

### A05 — Security Misconfiguration
Control: CORS con lista explícita de orígenes (nunca `*` con credenciales); `CI_ENVIRONMENT=production` (sin stack traces al cliente); Docker: MySQL y Redis solo en red interna, sin puertos publicados en producción; headers `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy` vía Filter global; `.env` con permisos 600 fuera del webroot.

### A06 — Vulnerable and Outdated Components
Control: `composer audit` y `npm audit` en cada sprint y como gate del checklist de Fase 2; dependencias fijadas por lockfiles; actualizaciones de seguridad de CI4/React aplicadas en ventanas mensuales.

### A07 — Identification and Authentication Failures
Control (ADR-002): verificación completa del ID token (firma RS256 contra JWKS cacheado, `exp`, `iat`, `aud`, `iss`); lista blanca en `usuarios` — crear cuenta Firebase no da acceso; `email_verified` requerido; desactivación efectiva ≤60 s (cache corto de usuario); rate limit de 60 req/min por usuario y 10 req/min por IP en endpoints sin auth.

```php
// FirebaseTokenVerifier (núcleo) — validación de claims tras verificar firma
private function validarClaims(object $claims): void
{
    $projectId = env('FIREBASE_PROJECT_ID');
    $ahora     = time();
    if (($claims->exp ?? 0) < $ahora)                                   throw new TokenInvalidoException('expirado');
    if (($claims->iat ?? PHP_INT_MAX) > $ahora + 60)                    throw new TokenInvalidoException('iat_futuro');
    if (($claims->aud ?? '') !== $projectId)                            throw new TokenInvalidoException('aud');
    if (($claims->iss ?? '') !== "https://securetoken.google.com/{$projectId}") throw new TokenInvalidoException('iss');
    if (empty($claims->sub))                                            throw new TokenInvalidoException('sub');
}
```

### A08 — Software and Data Integrity Failures
Control: despliegues desde el repositorio con revisión (nada editado a mano en el servidor); migraciones versionadas (`spark migrate`); el `InitialSeeder` siembra desde el `db.json` validado (misma fuente que el demo — Gobernanza v3); auditoría inmutable de cambios de estado y configuración.

### A09 — Security Logging and Monitoring Failures
Control: tabla `auditoria` (quién/qué/cuándo/IP) para login, cambios de estado, configuración y usuarios; log de fallos de envío (`recordatorios_enviados.estado='fallido'`) y de sync (`google_sync.error`) visibles para Dirección; logs de CI4 sin PII (nunca tokens ni correos completos en mensajes de error).

### A10 — Server-Side Request Forgery
Control: el backend solo consume URLs fijas de Google APIs; el campo `enlace` de acuerdos se valida como URL http(s) y **nunca se descarga server-side** — solo se renderiza como link con `rel="noopener noreferrer"`.

## 3. Seguridad por capa

| Capa | Controles |
|---|---|
| SPA | React escapa por defecto; prohibido `dangerouslySetInnerHTML`; tokens solo en memoria del SDK Firebase (no localStorage manual); build sin sourcemaps públicos |
| Borde (Filters) | CORS lista blanca, FirebaseAuth, Throttle (Redis), headers de seguridad |
| Controllers | Validación de tipos/formatos; DTOs explícitos; 422 con detalle por campo |
| Services/Policies | RBAC de SRS §2.2; menor privilegio; excepciones → códigos HTTP correctos |
| Persistencia | Binding 100%; transacciones; CHECKs; sin DELETE físico de usuarios |
| Infraestructura | Docker red interna; MySQL usuario de app con permisos mínimos (sin DROP/GRANT); Redis con `requirepass` y sin exposición |
| Integraciones | Service account con scopes mínimos e impersonación única; clave JSON fuera del repo con permisos 600; rotación semestral |

## 4. Procedimientos operativos

**Gestión de secretos:** `.env` nunca en git (`.gitignore` desde el Sprint 0); plantilla `env.example` sin valores reales; clave de service account en ruta privada referenciada por `GOOGLE_APPLICATION_CREDENTIALS`; rotación: service account cada 6 meses, `encryption.key` solo con migración de re-cifrado documentada.

**Hardening de despliegue:** checklist antes de cada release — HTTPS+HSTS activo, `CI_ENVIRONMENT=production`, headers de seguridad presentes, puertos de BD/Redis cerrados desde fuera, `composer audit`/`npm audit` limpios, backups automáticos y restauración probada.

**Respuesta a incidentes:** (1) contener — desactivar usuario comprometido (`activo=0`, efecto ≤60 s) o revocar clave de service account desde Google Cloud Console; (2) evaluar con `auditoria` y logs; (3) erradicar y rotar secretos afectados; (4) registrar el incidente y su resolución; (5) notificar a Dirección y, si hay datos personales comprometidos, seguir el procedimiento LFPDPPP.

**Privacidad (LFPDPPP):** datos mínimos (nombre, correo institucional, rol); sin datos sensibles; aviso de privacidad interno visible en el login; derechos ARCO atendidos por Dirección (la baja lógica y la corrección de nombre/correo los cubre); retención: auditoría 24 meses, después purga programada; los correos de recordatorio solo contienen datos del acuerdo del propio destinatario.

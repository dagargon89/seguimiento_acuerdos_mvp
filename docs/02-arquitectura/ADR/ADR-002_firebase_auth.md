# ADR-002 — Autenticación con Firebase Authentication

| Campo | Valor |
|---|---|
| Documento | ADR-002 |
| Versión | 1.0 |
| Fecha | 2026-07-08 |
| Estado | Aceptada |
| Depende de | ADR-001 |

## 1. Contexto

La propuesta exige cuentas individuales con roles. Dirección decidió (08-jul-2026) usar **Firebase Auth con ambos proveedores**: *Sign in with Google* para el equipo del dominio `planjuarez.org` y *email/password* como respaldo para personas externas invitadas. El backend es CI4 (ADR-001), por lo que la sesión no vive en Firebase: Firebase autentica, CI4 autoriza.

## 2. Decisión

| Aspecto | Anterior (demo) | Nuevo (producción) |
|---|---|---|
| Identidad | Selección de cuenta demo sin contraseña | Firebase Auth (Google + email/password) |
| Sesión | Objeto en memoria | ID token de Firebase (JWT RS256, 1 h) renovado por el SDK |
| Autorización | `puedeVer/puedeActualizar` en JS | Filter `FirebaseAuthFilter` + Policies en Services CI4 |
| Alta de usuarios | Botón en vista Usuarios | Igual, pero el alta crea el registro local; el acceso se activa al primer login que haga match por email |

Flujo: (1) el SPA autentica con el SDK de Firebase; (2) envía `Authorization: Bearer <idToken>` en cada request; (3) `FirebaseAuthFilter` verifica firma contra las claves públicas de Google (cacheadas en Redis con el TTL del header `Cache-Control`), valida `aud`, `iss`, `exp`; (4) resuelve el usuario local por `firebase_uid` o, en primer login, por `email` verificado — si no existe usuario local **activo**, responde 403 (`usuario_no_registrado`); (5) inyecta el usuario (id, rol, area_id) al request.

Reglas de aprovisionamiento: **la lista blanca es la tabla `usuarios`** administrada por Dirección. Crear cuenta en Firebase NO otorga acceso. Para Google se exige además `email_verified=true`; para email/password se exige verificación de correo antes del primer acceso.

## 3. Consecuencias

**Positivas:** cero manejo de contraseñas para el equipo del dominio; MFA y recuperación delegadas a Google/Firebase; tokens estándar verificables sin llamada de red (JWKS cacheado); el SDK maneja el refresh.

**Negativas:** dependencia de un servicio externo para el login (mitigada: el plan gratuito de Firebase Auth cubre este volumen; si Firebase cae, el panel es inaccesible pero los datos y recordatorios siguen operando server-side); email/password reintroduce flujo de reset — delegado íntegramente a Firebase.

**Neutrales:** el rol NO vive en Firebase (ni custom claims): vive en MySQL. Evita sincronizar claims y mantiene una sola fuente de verdad de autorización.

## 4. Implicaciones de seguridad

Verificación completa del JWT (firma, `exp`, `iat`, `aud` = project id, `iss`); rechazo de tokens de otros proyectos; rate limit sobre endpoints autenticados; auditoría de logins en tabla `auditoria`; bloqueo inmediato desactivando `usuarios.activo` (efecto en ≤1 request, sin esperar expiración del token: el Filter consulta al usuario local en cada request con cache Redis de 60 s). Detalle en doc 04 §A07.

## 5. Impacto en documentos

Doc 02 (flujo de secuencia auth), doc 03 (`usuarios.firebase_uid`), doc 04 (A01/A07), doc 05 (401/403 y endpoint `GET /me`), doc 06 (pruebas negativas de token).

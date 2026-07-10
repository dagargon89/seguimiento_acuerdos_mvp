# ADR-006 — Autorregistro con rol `pendiente` (cambio de contrato post-freeze)

| Campo | Valor |
|---|---|
| Documento | ADR-006 |
| Versión | 1.0 |
| Fecha | 2026-07-10 |
| Estado | Aceptada |
| Depende de | ADR-001, ADR-002, ADR-005, doc 05 (contrato) |

## 1. Contexto

RF-01.2 exige lista blanca estricta: solo usuarios ya existentes en `usuarios` (`activo=1`) acceden al panel; cualquier otra cuenta Firebase válida recibe 403 `usuario_no_registrado`. Esa alta hoy es 100% manual — solo Dirección la hace vía `POST /usuarios`.

En la práctica esto crea friction operativa: alguien con una cuenta de Google del dominio (o email/password ya creada en Firebase) no puede empezar a usar Participa Juárez sin que Dirección abra primero la pantalla de usuarios y le dé de alta a mano, a menudo antes de tener claro qué rol/área le corresponde. Se necesita una vía de autorregistro client-side que no comprometa el control de acceso: la cuenta debe existir sin permisos hasta que alguien con autoridad decide su rol.

## 2. Decisión

Se añade `POST /registro` (self-service, sin lista blanca) y un 4º valor de rol, `pendiente`, que no otorga ningún acceso funcional. Se extiende el contrato congelado en la misma sesión (regla №3 de CLAUDE.md), quedando el doc 05 como **v1.4 CONGELADA**:

- `registrarme(datos: RegistroCuenta): Promise<Usuario>` — cualquier portador de un ID token Firebase válido, sin necesidad de existir previamente en `usuarios`.
- Tipo nuevo: `RegistroCuenta { nombre: string }`.
- `Rol` += `'pendiente'` (4º valor del ENUM `usuarios.rol`, migración `AgregarRolPendiente`).

Mecanismo:
- **Filtro `firebaseauth:sin_lista`** (argumento de `FirebaseAuthFilter`): verifica el token igual que el modo normal (401 `token_faltante`/`token_invalido`) pero NO exige que el usuario exista — publica los claims en `Services::tokenVerificado()` (uid, email, emailVerified). Si el usuario ya existe (reintento), también se resuelve en `Services::usuarioActual()` para que el controller pueda responder 409.
- **`RegistroController::crear()`**: toma `uid`/`email` del token verificado (nunca del body); body solo acepta `nombre` (cualquier otra clave, incluidas `rol`/`estado`/`email`, → 422 `campo_no_permitido`); 409 `cuenta_ya_existe` si el `uid` o el `email` ya están en `usuarios`; inserta en transacción con `rol: 'pendiente'`, `area_id: null`, `activo: 1`; audita `registro_usuario`.
- **Guardia central `cuenta_pendiente`** (en `FirebaseAuthFilter`, modo normal, tras resolver el usuario): si `rol === 'pendiente'` y la ruta no es `GET/PATCH /me` → 403 `{"error":"cuenta_pendiente","mensaje":"Tu cuenta está pendiente de aprobación."}`. Así una cuenta pendiente puede ver su propio estado (`GET /me`) y corregir su nombre (`PATCH /me`), pero no toca ningún dato del panel.
- **Aprobación**: Dirección ve las cuentas `pendiente` en la pantalla de Usuarios (mismo `GET /usuarios` — ninguna vista nueva) y les asigna rol/área vía `PATCH /usuarios/{id}` existente (`ROLES` del controller += `pendiente`, para poder asignarlo o dejarlo explícitamente).

| Aspecto | Antes (v1.3) | Ahora (v1.4) |
|---|---|---|
| Alta de cuenta | Solo Dirección, `POST /usuarios` | + autorregistro client-side, `POST /registro` |
| `usuarios.rol` | 3 valores | + `pendiente` (4º valor) |
| Acceso de una cuenta nueva | Ninguno hasta el alta manual | Existe de inmediato como `pendiente`, sin acceso funcional, visible para Dirección |
| Contrato | Sin `registrarme` | + `registrarme(datos): Promise<Usuario>` |
| doc 05 §2.1 | Solo `GET/PATCH /me` | + `POST /registro` con specs de request/response y nota de la guardia `cuenta_pendiente` |
| Guardia de acceso | "Existe y activo" → acceso según rol | + "rol ≠ pendiente" para cualquier ruta que no sea `/me` |

## 3. Consecuencias

**Positivas:** cualquier persona con cuenta Firebase válida puede autorregistrarse sin esperar una acción manual previa de Dirección; el modelo de aprovisionamiento se vuelve más matizado (existencia de cuenta ≠ acceso funcional) sin abrir la puerta a acceso no autorizado — `pendiente` es, en la práctica, un rol sin permisos; Dirección conserva control total sobre quién obtiene acceso real, solo cambia el momento en que decide (tras el registro, no antes); se reutiliza toda la infraestructura de aprobación existente (`PATCH /usuarios/{id}`, `AuthCache::invalidar`).

**Negativas / mitigación:** se toca una interfaz recién congelada (v1.3) y el ENUM de rol en BD — mitigado con este ADR + migración dedicada + actualización simultánea de `types.ts`/`api.ts`/`api.real.ts` ↔ doc 05 v1.4. Un endpoint sin lista blanca es superficie nueva de ataque potencial (cualquiera con cuenta Firebase puede llenar la tabla `usuarios` de filas `pendiente`) — mitigado con Throttle (cae a límite por IP cuando no hay usuario resuelto), auditoría de cada alta, y el hecho de que `pendiente` no otorga ningún acceso a datos del panel. La guardia central vive en el filtro (un solo punto), no repartida en cada controller, para minimizar el riesgo de un endpoint nuevo que la olvide.

## 4. Alternativa descartada

Mantener el alta 100% manual y solo agregar una "solicitud de acceso" (tabla o email a Dirección) sin crear la fila en `usuarios` hasta la aprobación. Descartada porque duplica el modelo de datos de una solicitud (con su propio ciclo de vida) en vez de reutilizar `usuarios` con un rol sin permisos, y porque retrasa la asociación `firebase_uid` ↔ persona hasta la aprobación, complicando el primer login (RF-01.3) frente al enfoque actual donde la cuenta ya existe y solo cambia de rol.

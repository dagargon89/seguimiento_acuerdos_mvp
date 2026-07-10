# ADR-005 — Perfil self-service (cambio de contrato post-freeze)

| Campo | Valor |
|---|---|
| Documento | ADR-005 |
| Versión | 1.0 |
| Fecha | 2026-07-10 |
| Estado | Aceptada |
| Depende de | ADR-001, ADR-002, doc 05 (contrato) |

## 1. Contexto

El contrato del cliente (`apps/web/src/lib/api.ts`) fue congelado en v1.2 (ADR-004). Ese contrato no ofrecía ninguna vía para que un usuario autenticado edite su propio perfil: la única edición de `usuarios` era `PATCH /usuarios/{id}`, exclusiva de Dirección (nombre, email, rol, área, activo).

La nueva feature de "perfil de usuario" requiere que cualquier persona autenticada pueda corregir su propio nombre de pantalla sin depender de Dirección. La contraseña de acceso la gestiona Firebase Authentication (email/password) directamente en el cliente (ADR-002); no hay ni debe haber un endpoint de contraseña en esta API — Firebase no expone esa operación al backend.

## 2. Decisión

Se añade un endpoint self-service **`PATCH /me`** y se extiende el contrato congelado en la misma sesión (regla №3 de CLAUDE.md), quedando el doc 05 como **v1.3 CONGELADA**:

- `editarMiPerfil(cambios: ActualizacionPerfil): Promise<Usuario>` — cualquier usuario activo, sobre su propia cuenta.
- Tipo nuevo: `ActualizacionPerfil { nombre?: string }`.

Reglas:
- El actor (`service('usuarioActual')->obtener()`) solo puede modificar su propio `nombre`. Cualquier otra clave en el body (`email`, `rol`, `area_id`, `activo`, etc.) → 422 `campo_no_permitido` (mismo helper de campos desconocidos que usa `UsuariosController`).
- `nombre` requerido, no vacío tras `trim`, máximo 120 caracteres → 422 `validacion` con `campos` si falla.
- Actualiza `usuarios.nombre` en transacción; invalida `AuthCache::invalidar((int) $actor['id'])`; audita `accion='editar_perfil'`, `entidad='usuario'`, `entidad_id=actor.id`.
- Respuesta `{ "data": Usuario }` (mismo envoltorio que `editarUsuario`).
- Sin cambios de esquema/DDL: reutiliza la columna `usuarios.nombre` existente.
- La contraseña **no** se gestiona por esta vía; sigue siendo responsabilidad exclusiva de Firebase client-side.

| Aspecto | Antes (v1.2) | Ahora (v1.3) |
|---|---|---|
| Edición de `nombre` propio | Solo Dirección vía `PATCH /usuarios/{id}` | + self-service vía `PATCH /me` |
| Contrato | Sin `editarMiPerfil` | + `editarMiPerfil(cambios): Promise<Usuario>` |
| doc 05 §2.1 | Solo `GET /me` | + `PATCH /me` con specs de request/response |
| Frontend | Sin pantalla de perfil | Pantalla de perfil (tarea separada, "Perfil B") |
| Pruebas (doc 06) | Sin casos de perfil self-service | `PerfilTest` (nombre propio, campo no permitido, nombre vacío, reflejo en `GET /me`) |

## 3. Consecuencias

**Positivas:** cualquier usuario corrige su nombre sin fricción operativa (no depende de que Dirección lo edite); el endpoint reutiliza el patrón de validación/auditoría/AuthCache ya probado en `UsuariosController::editar`, minimizando superficie nueva; rol/área/estado/email permanecen gobernados exclusivamente por Dirección, preservando la matriz de permisos existente (RF-10).

**Negativas / mitigación:** se toca una interfaz recién congelada (v1.2). Se acepta como cambio gobernado (este ADR + actualización simultánea `api.ts`/`api.real.ts`/`types.ts` ↔ doc 05 v1.3). Dos endpoints de escritura distintos (`PATCH /me`, `PATCH /usuarios/{id}`) ahora pueden tocar `usuarios.nombre`; no hay conflicto porque el primero solo opera sobre el propio actor.

## 4. Alternativa descartada

Reutilizar `PATCH /usuarios/{id}` permitiendo que el propio actor lo invoque sobre su `id`. Descartada porque ese endpoint expone campos (rol, área, activo) que un usuario no-Dirección nunca debe poder tocar sobre sí mismo, y separar el permiso por endpoint es más simple y auditable que añadir lógica condicional de "campos permitidos según si el actor edita su propia fila".

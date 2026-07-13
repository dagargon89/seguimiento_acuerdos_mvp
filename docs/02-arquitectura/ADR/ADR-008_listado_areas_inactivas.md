# ADR-008 — Listado de áreas inactivas para administración (cambio de contrato post-freeze)

| Campo | Valor |
|---|---|
| Documento | ADR-008 |
| Versión | 1.0 |
| Fecha | 2026-07-13 |
| Estado | Aceptada |
| Depende de | ADR-004 (administración de áreas), doc 05 (contrato) |

## 1. Contexto

ADR-004 añadió la administración de áreas (`POST /areas`, `PATCH /areas/{id}`, solo Dirección) con baja lógica vía la columna `areas.activa`. Sin embargo, `GET /areas` siempre devolvió **solo las activas** — correcto para los selects de Captura/Usuarios, pero insuficiente para la nueva sección de administración de áreas del frontend: un área desactivada desaparecía de todo listado y no había forma de **reactivarla** desde la UI.

## 2. Decisión

Se extiende `GET /areas` con el parámetro opcional **`?todas=1`** y el contrato congelado se actualiza en la misma sesión (regla №3 de CLAUDE.md), quedando el doc 05 como **v1.5 CONGELADA**:

- `GET /areas` (sin parámetro): sin cambios — áreas **activas**, cualquier usuario autenticado.
- `GET /areas?todas=1`: devuelve activas **e inactivas**, ordenadas por nombre. **Solo Dirección**; cualquier otro rol recibe 403 `sin_permiso`.
- Cliente: `listAreas(todas?: boolean): Promise<Area[]>` — el parámetro es opcional y por defecto `false`, por lo que ningún consumidor existente cambia.

Sin cambios de esquema/DDL: reutiliza `areas.activa` (doc 03). Sin endpoint `DELETE`: la baja sigue siendo lógica (`PATCH {activa:false}`), porque `areas` es referenciada por `acuerdos.area_id` y `usuarios.area_id` y el historial debe conservarse.

| Aspecto | Antes (v1.4) | Ahora (v1.5) |
|---|---|---|
| `GET /areas` | Solo activas, sin variantes | + `?todas=1` (solo Dirección) incluye inactivas |
| Contrato | `listAreas(): Promise<Area[]>` | `listAreas(todas?: boolean): Promise<Area[]>` |
| Frontend | Sin sección de áreas | Página "Áreas" en Administración (solo Dirección): crear, renombrar, desactivar/reactivar |
| Pruebas (doc 06) | AR-01..AR-04 | + AR-05: Dirección ve inactivas con `todas=1`; otro rol 403; default sigue solo-activas |

## 3. Consecuencias

- La sección de administración puede desactivar y reactivar áreas sin tocar la BD.
- Los selects existentes (Captura, Usuarios, filtros) siguen mostrando solo áreas activas sin ningún cambio.
- El 403 explícito (en lugar de ignorar el parámetro) hace observable el intento de un rol sin permiso y mantiene la política "solo Dirección administra catálogos" verificable con prueba negativa (regla №4 de CLAUDE.md).

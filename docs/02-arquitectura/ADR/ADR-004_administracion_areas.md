# ADR-004 — Administración de áreas en el MVP (cambio de contrato post-freeze)

| Campo | Valor |
|---|---|
| Documento | ADR-004 |
| Versión | 1.0 |
| Fecha | 2026-07-09 |
| Estado | Aceptada |
| Depende de | ADR-001, doc 05 (contrato) |

## 1. Contexto

El contrato del cliente (`apps/web/src/lib/api.ts`) fue **congelado** el 2026-07-09 como doc 05 v1.1, reflejando el demo validado. Ese demo administra usuarios pero trata las **áreas** como catálogo de solo lectura: la interfaz congelada solo exponía `listAreas()`.

Sin embargo, el SRS **RF-10** establece que Dirección puede dar de alta y editar áreas, y el doc 05 §2.6 ya listaba los endpoints `POST /areas` y `PATCH /areas/{id}` sin especificación. Existía, por tanto, una contradicción entre el contrato congelado (solo lectura) y el requisito de negocio (administración).

## 2. Decisión

Se **incluye la administración de áreas en el MVP** y se extiende el contrato congelado en la misma sesión (regla №3 de CLAUDE.md), quedando el doc 05 como **v1.2 CONGELADA**:

- `crearArea(alta: AltaArea): Promise<Area>` — solo Dirección.
- `editarArea(id: number, cambios: EdicionArea): Promise<Area>` — solo Dirección.
- Tipos nuevos: `AltaArea { nombre }`, `EdicionArea { nombre?; activa? }`.

Reglas: `nombre` requerido y único (422 en duplicado); crear/editar solo Dirección (403 en otro rol); ambas operaciones auditan en `auditoria` (`alta_area` / `editar_area`, entidad `area`).

| Aspecto | Antes (v1.1) | Ahora (v1.2) |
|---|---|---|
| Contrato áreas | Solo `listAreas()` | + `crearArea` / `editarArea` |
| doc 05 §2.6 | Endpoints sin specs | Specs JSON de request/response |
| Frontend | Sin UI de áreas | Pantalla de administración de áreas (Sprint 1) |
| Pruebas (doc 06) | Sin casos de área | AR-01..04 |

## 3. Consecuencias

**Positivas:** el contrato deja de contradecir el RF-10; áreas se administran desde la app sin tocar la BD manualmente; el backend implementa `POST/PATCH /areas` con la misma matriz de permisos que usuarios.

**Negativas / mitigación:** se toca una interfaz recién congelada. Se acepta como cambio gobernado (este ADR + actualización simultánea `api.ts` ↔ doc 05 v1.2). La UI de áreas es nueva respecto al demo validado; se mantiene minimalista y consistente con la pantalla de usuarios para no alterar el diseño aprobado.

## 4. Alternativa descartada

Dejar áreas como catálogo de solo lectura gestionado por seed/BD. Descartada por decisión de producto (2026-07-09): Dirección debe poder administrar áreas desde la aplicación.

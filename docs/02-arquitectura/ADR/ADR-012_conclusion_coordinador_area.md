# ADR-012 — Conclusión de acuerdos por la coordinación en su área

| Campo | Valor |
|---|---|
| Documento | ADR-012 |
| Versión | 1.0 |
| Fecha | 2026-07-13 |
| Estado | Aceptada |
| Depende de | doc 05 (contrato), ADR-007 (visibilidad), ADR-011 (edición/borrado) |
| Modifica | regla №4 de CLAUDE.md ("solo Dirección concluye") |

## 1. Contexto

Hasta ahora **solo Dirección** podía concluir/reabrir acuerdos (regla №4 de CLAUDE.md, con pruebas negativas 403). La coordinación ya podía **editar** los acuerdos de su área (`puedeEditarEstructura`), pero no cerrarlos, lo que obligaba a Dirección a validar todo el flujo de todas las áreas. Dirección pidió que **cada coordinación pueda editar y concluir los acuerdos de su área**, delegando la validación operativa.

Para que "los acuerdos de su área" y "las personas de su área" tengan sentido, el **área debía poder asignarse a cualquier rol** (antes, por lógica de `UsuariosController`/frontend, solo la coordinación llevaba área — aunque la BD ya lo permitía: el CHECK `chk_coordinador_area` solo *obliga* a que el coordinador tenga una).

## 2. Decisión

### a) Conclusión: se suma la coordinación de área

`AcuerdosController::puedeConcluir(actor, acuerdo)` autoriza a concluir (`PATCH /acuerdos/{id}/concluir`) si:
- el actor es **Dirección** (cualquier acuerdo), o
- el actor es **coordinador** y `acuerdo.area_id === actor.area_id`.

El criterio es **por área del acuerdo** (`acuerdos.area_id`), coherente con la edición ya existente — no por el área del responsable. Todo intento denegado responde 403 y se **audita** (`intento_concluir`), igual que antes.

**Reabrir (`.../reabrir`) y eliminar (`DELETE`) siguen siendo exclusivos de Dirección.** Editar (`puedeEditarEstructura`) no cambia (ya incluía a la coordinación del área y al capturador).

`GET /checklist` deja de ser solo-Dirección: la coordinación lo ve **filtrado a su área** (mismo criterio).

### b) Área asignable a cualquier rol

- `UsuariosController::crear`/`editar`: el `area_id` se acepta para cualquier rol (validado contra áreas activas). La **coordinación sigue exigiendo** área (CHECK del DDL); los demás roles la tienen **opcional**. Al cambiar de rol ya no se borra el área sola; para quitarla se envía `area_id: null` explícito.
- Frontend (`Usuarios.tsx`): el selector de área se habilita para todos los roles (alta y aprobación) y se añade **edición inline de rol/área** de usuarios ya activos (botón "Editar" → Guardar/Cancelar).

### c) Frontend de conclusión

- `Drawer.tsx`: el botón "Marcar como concluido" se muestra a `puedeConcluir` (Dirección o coordinación del área); "Reabrir" queda solo para Dirección.
- Navegación (`App.tsx`): la coordinación ve la entrada **Checklist** (Usuarios/Áreas siguen solo para Dirección).

## 3. Consecuencias

- **Sin migración de esquema**: la BD ya admitía `area_id` en cualquier rol.
- Se actualizó CLAUDE.md (regla №4 y nota de escritura de ADR-007).
- Pruebas: se ajustan las negativas que asumían coordinador→403 al concluir (`ConclusionReaperturaTest`, `AcuerdosEscrituraTest`) y se añaden positivas (coordinador concluye en su área → 200; en otra área → 403 + auditoría; reabrir por coordinador → 403); `AdminUsuariosAreasTest` cubre área asignable a un responsable.
- **Reversible**: restaurar `puedeConcluir`/`checklist` a "solo Dirección", volver a forzar `area` solo en coordinación, y revertir el gating del frontend. La visibilidad de LECTURA (ADR-007) no se toca.

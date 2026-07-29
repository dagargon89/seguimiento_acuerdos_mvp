# Spec — Mejoras Panel de Acuerdos (revisión de conclusión, edición ampliada, WYSIWYG, tooltip, hora cron)

- **Fecha:** 2026-07-29
- **Autor:** dgarcia@planjuarez.org (con Claude Code)
- **Fase:** Post-Fase 2 (backend + web en producción de desarrollo)
- **Estado:** aprobado para pasar a plan de implementación

## Contexto

Se solicitaron 6 mejoras. Tras brainstorming quedaron **5 en este ciclo** y 1 diferida:

1. Estado/flujo "pendiente de revisión" para la conclusión de acuerdos.
2. Edición de acuerdos ampliada a participantes (texto/enlaces).
3. ~~Sección tipo organigrama~~ → **diferida a su propio spec** (subsistema independiente).
4. Editor WYSIWYG para el campo `accion`.
5. Hora fija de recordatorios (8:00 AM).
6. Truncado + tooltip en la columna de acción de las tablas.

Restricciones de dominio relevantes (CLAUDE.md): `db.json` es espejo del DDL (regla 1); las pantallas nunca leen `db.json` directo, todo pasa por `ApiClient` (regla 2); prohibido `dangerouslySetInnerHTML` (regla 7); transacciones en operaciones multi-tabla (regla 8); cero N+1 (regla 9); toda fecha en `America/Ciudad_Juarez` (regla 6); conversión 1:1 del demo aprobado (regla 11).

## Decisiones tomadas (brainstorming)

| Tema | Decisión |
|---|---|
| Modelado de "pendiente de revisión" | Flag aparte del `estado` del ciclo de vida (NO se toca el enum `estado`). |
| Disparador de la revisión | Es una **solicitud de conclusión**: el acuerdo vive normal y entra a revisión cuando alguien pide marcarlo concluido. |
| Quién solicita la conclusión | **Responsable y corresponsables** del acuerdo. |
| Conclusión directa (ADR-012) | Se **mantiene**: admin y coordinador de área siguen concluyendo directo. La solicitud es el camino para responsable/corresponsables. Dos caminos. |
| Quién aprueba/rechaza | **Admin y coordinador de área** (coordinador solo su área, como ADR-012). |
| Rechazo | El acuerdo queda **`rechazada`** (con motivo), sigue activo y editable; el responsable corrige y puede volver a solicitar. |
| Visibilidad en revisión | **Visible para todos**, marcado "en revisión"; solo se silencian correos/recordatorios. |
| Vencimiento durante revisión | **Se congela**: un acuerdo `pendiente` de revisión no se marca `vencido`. |
| Correos del flujo | Al solicitar → correo a **admins + coordinador de área**. Al aprobar/rechazar → correo a **responsable + corresponsables**. Todo se registra en bitácora. |
| Editar acuerdo — quién | admin, coordinador de área, capturador (hoy) **+ responsable/corresponsables** del acuerdo. Rol `pendiente` sigue sin acceso. |
| Editar acuerdo — qué campos | Solo **texto y enlaces** (`tema`, `accion`, `observaciones`, `enlace`/`enlaces`) para los participantes nuevos. Campos sensibles (`responsable_id`, `area_id`, fecha) siguen restringidos a admin/coordinador/capturador. |
| Editar ≠ revisión | Editar un acuerdo activo **no** lo regresa a revisión. |
| Editor `accion` | WYSIWYG **básico** (negrita, cursiva, listas, enlaces) → guarda **Markdown**. En captura por formulario y en el Drawer; la hoja de captura masiva sigue en texto plano. |
| Render de Markdown | Renderer Markdown→nodos React, **sin `dangerouslySetInnerHTML`**. |
| Truncado en tablas | Clamp a **2 líneas** con "…". |
| Tooltip | **Tooltip custom** (panel flotante amplio) con el Markdown renderizado. Click en fila → Drawer (ya existe). |
| Hora recordatorios | **Fija, 8:00 AM** `America/Ciudad_Juarez` → crontab `0 8 * * *`; unificar doc (hoy 07:00 vs 8:30). Sin cambio de código del job. |
| Rename Dirección→admin | **En pausa** hasta definir estructura. Se mantiene el valor `direccion` en BD y código. |
| Modelado de datos de la solicitud | **Columnas en `acuerdos`** (no tabla dedicada); historial vía `auditoria`. |

## A. Modelo de datos

Nuevas columnas en la tabla `acuerdos` (fuente de verdad: `docs/03-datos/panel_acuerdos_ddl.sql`; se replica en el snapshot de migración `apps/api/app/Database/sql/001_esquema_inicial.sql` vía nueva migración `ALTER`, y en el espejo `apps/web/src/lib/mock/db.json` — regla 1):

```sql
revision_estado      ENUM('sin_solicitud','pendiente','rechazada') NOT NULL DEFAULT 'sin_solicitud',
revision_solicitada_por_id  BIGINT UNSIGNED NULL,
revision_solicitada_at      DATETIME NULL,
revision_motivo_rechazo     TEXT NULL,
-- FK: revision_solicitada_por_id -> usuarios(id) ON DELETE SET NULL
-- Índice: idx_acuerdos_revision (revision_estado)
```

Transiciones del flag:

- `sin_solicitud` → `pendiente`: al **solicitar conclusión** (responsable/corresponsable).
- `pendiente` → `sin_solicitud`: al **aprobar** (el acuerdo pasa a `estado='concluido'` vía `concluir()`).
- `pendiente` → `rechazada`: al **rechazar** (guarda `revision_motivo_rechazo`).
- `rechazada` → `pendiente`: al **volver a solicitar** tras corregir.
- `rechazada` → `sin_solicitud`: (opcional) no requerido; puede quedar `rechazada` hasta nueva solicitud.

El enum `estado` (`en_proceso`/`vencido`/`concluido`) y la expresión derivada de `vencido` (`AcuerdoModel::estadoDerivadoExpr`) **no cambian**, salvo el congelamiento (sección C).

Entity/Model:
- `apps/api/app/Models/AcuerdoModel.php`: agregar las columnas a `allowedFields` y al SELECT de `builderConJoins`.
- `apps/api/app/Entities/Acuerdo.php`: nuevos campos readonly + serialización en `aArray()`.
- `apps/web/src/lib/types.ts`: `AcuerdoRow`, `Acuerdo` y `DbJson`.

## B. Flujo de solicitud de conclusión (backend)

Archivo principal: `apps/api/app/Controllers/AcuerdosController.php`. Rutas: `apps/api/app/Config/Routes.php` (grupo `firebaseauth`).

### B.1 Solicitar conclusión — `POST /acuerdos/{id}/solicitar-conclusion`
- **Permiso:** responsable o corresponsable del acuerdo (nueva policy `puedeSolicitarConclusion`). Denegado → 403 + auditoría `intento_solicitar_conclusion` (prueba negativa, regla 4).
- **Precondiciones:** acuerdo no `concluido`; `revision_estado` en `sin_solicitud` o `rechazada`.
- **Efecto (transacción):** `revision_estado='pendiente'`, `revision_solicitada_por_id`, `revision_solicitada_at=now()`, limpiar `revision_motivo_rechazo`. Auditoría `solicitar_conclusion`. Insertar avance `tipo='solicitud_conclusion'` (o registrar en bitácora unificada `construirActividad`). Se permite un **comentario opcional** del solicitante (guardado en el avance/detalle).
- **Correo inmediato:** a admins (rol `direccion`) + coordinador del área del acuerdo (plantilla `solicitud_conclusion`).

### B.2 Aprobar = concluir existente — `PATCH /acuerdos/{id}/concluir`
- Se **mantiene** `concluir()` (admin + coordinador de área, ADR-012). Ahora, si el acuerdo venía con `revision_estado='pendiente'`, además de concluir:
  - `revision_estado='sin_solicitud'`.
  - Notifica a **responsable + corresponsables** (plantilla `conclusion_aprobada`).
  - Auditoría `aprobar_conclusion` (además del `concluir` actual).
- La conclusión directa (sin solicitud previa) sigue funcionando igual; no notifica "aprobado" (no había solicitud) — se conserva el comportamiento actual.

### B.3 Rechazar conclusión — `POST /acuerdos/{id}/rechazar-conclusion`
- **Permiso:** admin + coordinador de área (misma policy que `puedeConcluir`). Denegado → 403 + auditoría `intento_rechazar_conclusion`.
- **Precondición:** `revision_estado='pendiente'` (409 si no).
- **Body:** `motivo` (requerido, no vacío).
- **Efecto (transacción):** `revision_estado='rechazada'`, `revision_motivo_rechazo=motivo`. Auditoría `rechazar_conclusion` (con motivo en `detalle`). Insertar avance/bitácora `tipo='rechazo_conclusion'`.
- **Correo inmediato:** a responsable + corresponsables (plantilla `conclusion_rechazada`, incluye motivo).

Todas las operaciones usan `transException(true)->transStart()…transComplete()` (regla 8) y no introducen N+1 (regla 9).

## C. Correos y recordatorios (silencio + congelamiento en revisión)

Archivo: `apps/api/app/Services/RecordatorioService.php`.

- `marcarVencidos()`: agregar `AND revision_estado <> 'pendiente'` al UPDATE, para **congelar** el vencimiento durante la revisión.
- Materialización/envío de recordatorios (`procesar()` y sus pasos de recordatorios `previo`/`dia`/`vencido` y `solicitud_avance`): **excluir** acuerdos con `revision_estado='pendiente'` (silencio a responsable/corresponsables durante la revisión).
- El resumen periódico a dirección/coordinación puede seguir contándolos (informativo); no se les envía recordatorio operativo. (Confirmar en implementación; por defecto: excluir también del "solicitud de avances".)

Nuevas plantillas en `apps/api/app/Libraries/Correo/PlantillaCorreo.php`:
- `solicitud_conclusion` — a admins + coordinador de área.
- `conclusion_aprobada` — a responsable + corresponsables.
- `conclusion_rechazada` — a responsable + corresponsables (incluye motivo).

Estos 3 correos son **inmediatos** (patrón `NotificadorAsignacion`, no cron). Destinatarios reutilizando `destinatariosDe()` / `NotificadorAsignacion::destinatarios()` y, para admins, consulta de usuarios con rol `direccion` activos + coordinador del área.

## D. Edición ampliada (texto/enlaces para participantes)

Archivo: `apps/api/app/Controllers/AcuerdosController.php::update()` (PATCH `/acuerdos/{id}`).

- Introducir **permiso por campo**:
  - Campos "seguros": `tema`, `accion`, `observaciones`, `enlace`, `enlaces`. Editables por admin, coordinador de área, capturador **y responsable/corresponsables** del acuerdo (nueva policy `puedeEditarContenido`).
  - Campos "sensibles": `responsable_id`, `area_id`, `recordatorio_dias` (y fecha compromiso donde aplique). Siguen restringidos a `puedeEditarEstructura` actual (admin/coordinador/capturador).
- Si un usuario con permiso solo de contenido intenta tocar un campo sensible → 422/403 según convención existente (`campo_no_permitido`).
- Editar **no** cambia `revision_estado` (editar ≠ revisión).
- Auditoría `editar` como hoy.

Front (`apps/web/src/components/Drawer.tsx`):
- `puedeEditar` se amplía para incluir responsable/corresponsables (permiso de contenido).
- El formulario de edición muestra solo los campos que el usuario puede tocar (participante sin permiso de estructura ve tema/accion/observaciones/enlaces; no ve responsable/área/fecha).

## E. Editor Markdown (WYSIWYG básico) para `accion`

Front. Alcance: `apps/web/src/pages/Captura.tsx` (vista formulario) y `apps/web/src/components/Drawer.tsx` (edición). La hoja de captura masiva (`Captura.tsx` vista 'hoja') **sigue con texto plano** (input de celda); su texto se guarda tal cual (Markdown válido trivialmente).

- **Editor**: componente WYSIWYG básico (negrita, cursiva, listas ordenadas/no ordenadas, enlaces) que serializa a **Markdown**. Preferir una librería ligera; evaluar en el plan (p. ej. un editor pequeño Markdown-first). Debe respetar tokens PJ y no romper el look 1:1 (regla 11).
- **Almacenamiento**: columna `accion` (TEXT) sin cambio de tipo; contenido = Markdown.
- **Render seguro** (regla 7): renderer Markdown → **nodos React** (sin `dangerouslySetInnerHTML`). Un solo componente `<Markdown>` reutilizable (Drawer lectura, tooltip). Limitar los nodos permitidos a los del editor básico (negrita, cursiva, listas, enlaces, párrafos); los enlaces se abren con `rel="noopener noreferrer"`.
- **Compatibilidad**: el texto plano existente se renderiza correctamente como Markdown.
- **Derivar texto plano**: helper que convierte Markdown→texto plano para usos donde no se quiere formato (clamp de tabla, `truncar()`), en `components/EstadoHelpers.ts` o util nuevo.

## F. Truncado + tooltip en tablas

Front: `apps/web/src/pages/Panel.tsx` (VistaTabla, celda ~L447-449) y `apps/web/src/pages/MisAcuerdos.tsx` (TablaMisAcuerdos, ~L170-172).

- **Clamp a 2 líneas** con "…" (CSS `-webkit-line-clamp: 2`) sobre el **texto plano** derivado del Markdown. Altura de fila uniforme.
- **Tooltip custom**: al hover sobre la celda, panel flotante amplio que renderiza el Markdown completo con el componente `<Markdown>` (sección E). Componente reutilizable `<TooltipAccion>` (o similar), accesible (aparece también en focus por teclado; se cierra al salir). Respetar tokens y no depender de librerías externas si es evitable.
- **Click** en la fila → abre `Drawer` (comportamiento actual, sin cambio).

## G. Hora del cron

Sin cambio de código (el job `recordatorios:procesar` es agnóstico a la hora). Cambios de configuración/doc:

- Fijar crontab del SO a `0 8 * * *` con TZ `America/Ciudad_Juarez`.
- Unificar la documentación, que hoy está inconsistente:
  - `DEPLOY.md:17` y `:200-201` (dice 8:30 → cambiar a 8:00 / `0 8 * * *`).
  - `docs/04-seguridad/guia_activacion_google.md:71-76` (8:30 → 8:00).
  - `docs/02-arquitectura/02_arquitectura_sistema.md:45,130,286` (07:00 → 8:00).
- Confirmar TZ de app en `apps/api/app/Config/App.php:136` (ya `America/Ciudad_Juarez`).

## H. Fuera de alcance

- **Organigrama / jerarquías / filtros nuevos**: spec aparte (subsistema independiente).
- **Rename Dirección→admin**: en pausa; se conserva el valor `direccion` en BD y código. La UI mantiene su copy actual salvo indicación posterior.

## I. Pruebas / verificación

Backend (CI4):
- Policies positivas y negativas de `solicitar-conclusion`, `concluir` (aprobación), `rechazar-conclusion` (cada denegación audita su `intento_*`).
- Recordatorios: un acuerdo `pendiente` de revisión no se marca `vencido` ni recibe recordatorios/solicitud de avance.
- Aprobación de una solicitud concluye y notifica; rechazo deja `rechazada` + motivo y notifica.
- Edición por campo: participante puede editar contenido pero no campos sensibles.
- `node scripts/verificar_espejo.mjs` pasa (db.json ↔ DDL con las columnas nuevas).

Front (web):
- Render de Markdown sin `dangerouslySetInnerHTML` (grep en CI/lint).
- Clamp a 2 líneas y tooltip muestran el contenido; el click abre el Drawer.
- Estados de UI para `revision_estado` (badge "en revisión" / "rechazado" con motivo).
- `npm run typecheck && npm run lint && npm run build`.

## Documentación a sincronizar (obligatoria en la misma sesión)

- **Contrato API (regla 3):** los nuevos métodos (`solicitarConclusion`, `rechazarConclusion`) y las columnas de `revision_*` deben reflejarse en `docs/05-api/05_*.md` (la interfaz congelada) además de `api.ts`/`api.real.ts`.
- **ADR:** el flujo de conclusión amplía ADR-012 (aparece la vía "solicitud → aprobación/rechazo" para responsable/corresponsables). Registrar ADR corto (o addendum) que documente la decisión.
- **DDL/espejo (regla 1):** `panel_acuerdos_ddl.sql` + snapshot + `db.json` con las columnas nuevas; `scripts/verificar_espejo.mjs` debe pasar.
- **Hora del cron (sección G):** unificar `DEPLOY.md`, `guia_activacion_google.md`, `02_arquitectura_sistema.md` a `0 8 * * *`.

## Puntos de extensión (referencia rápida)

- DDL: `docs/03-datos/panel_acuerdos_ddl.sql` (tabla `acuerdos`); snapshot `apps/api/app/Database/sql/001_esquema_inicial.sql`; nueva migración `ALTER`; espejo `apps/web/src/lib/mock/db.json`.
- Backend acuerdos: `apps/api/app/Controllers/AcuerdosController.php` (update L352-460; concluir L685-756; policies L1508-1555), `AcuerdoModel.php` (L41-47 derivado, allowedFields), `Entities/Acuerdo.php`.
- Recordatorios/correo: `RecordatorioService.php` (marcarVencidos L84-92; destinatariosDe L249-267), `PlantillaCorreo.php`, `NotificadorAsignacion.php`.
- Rutas: `apps/api/app/Config/Routes.php`.
- Front tablas: `Panel.tsx` (VistaTabla L378+), `MisAcuerdos.tsx` (L136+).
- Front detalle/edición: `Drawer.tsx` (permisos L198-209; edición L269-369).
- Front captura: `Captura.tsx` (form L230-237; hoja L356-361).
- Contrato: `apps/web/src/lib/api.ts` + `api.real.ts` (nuevos métodos: `solicitarConclusion`, `rechazarConclusion`); tipos `lib/types.ts`.
- Estados UI: `components/EstadoHelpers.ts`, `Badge.tsx`, `styles/tokens/colors.css`.

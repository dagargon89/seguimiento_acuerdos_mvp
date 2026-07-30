# 05 — Especificación de API

| Campo | Valor |
|---|---|
| Documento | 05 — Especificación de API REST |
| Versión | 1.13 — **CONGELADA** (2026-07-30, Gobernanza v3 §4; interfaz literal de `apps/web/src/lib/api.ts`). v1.2 añadió `crearArea`/`editarArea` (ADR-004). v1.3 añade `editarMiPerfil` / `PATCH /me` (ADR-005). v1.4 añade `registrarme` / `POST /registro` y el rol `pendiente` (ADR-006). v1.5 añade `GET /areas?todas=1` / `listAreas(todas?)` (ADR-008). v1.6 añade el literal `'asignacion'` a `TipoRecordatorio` (ADR-010). v1.7 añade `DELETE /acuerdos/{id}` / `eliminarAcuerdo` y extiende la edición al capturador (ADR-011). v1.8 añade el filtro `mios=1` a `GET /acuerdos` / `FiltrosAcuerdos.mios` (ADR-013). v1.9 añade `solicitud_avances_activa` (bool) a `ConfigRecordatorios` y el literal `'solicitud_avance'` a `TipoRecordatorio`: el job envía periódicamente (misma frecuencia que el resumen) una solicitud de avances a responsables/corresponsables de acuerdos abiertos, condicionada por esa bandera global. v1.10 añade `invitaciones_calendario_activas` (bool) a `ConfigRecordatorios`: controla si Google Calendar manda la invitación nativa por correo al crear/actualizar el evento (la sincronización del acuerdo al calendario no cambia). v1.11 añade `avatar_color` (string hex `#RRGGBB` o null) a `Usuario`/`UsuarioRef` y a `ActualizacionPerfil`: color de identidad del avatar, editable por el propio usuario vía `PATCH /me` (nombre y/o avatar_color, ambos opcionales). v1.12 añade `GET /acuerdos/{id}/actividad` / `actividadAcuerdo(id)`: bitácora unificada de avances + auditoría de ciclo de vida del acuerdo (quick win #3, "Bitácora"). v1.13 añade el ciclo de revisión de conclusión (spec 2026-07-29): `revision_estado` (`'sin_solicitud'\|'pendiente'\|'rechazada'`) y `revision_motivo_rechazo` en `Acuerdo`; `POST /acuerdos/{id}/solicitar-conclusion` / `solicitarConclusion(id)` (responsable/corresponsable) y `POST /acuerdos/{id}/rechazar-conclusion` / `rechazarConclusion(id, motivo)` (dirección o coordinación del área, mismo permiso que `concluir`, ADR-012); `concluirAcuerdo` sigue siendo la aprobación (sin endpoint nuevo). |
| Fecha | 2026-07-30 |
| Depende de | 01_SRS, 02_arquitectura, 03_modelo_de_datos |

## 1. Convenciones

| Aspecto | Convención |
|---|---|
| Base | `/api/v1` — versionado por ruta |
| Auth | `Authorization: Bearer <Firebase idToken>` en todos los endpoints (ADR-002) |
| Códigos | 200 OK · 201 Created · 401 token inválido · 403 sin permiso · 404 no existe/no visible · 409 conflicto de estado · 422 validación · 429 rate limit |
| Errores | `{"error": "codigo_snake", "mensaje": "explicación", "campos": {"campo": "detalle"}}` — `campos` solo en 422 |
| Rate limit | 60 req/min por usuario (Redis); 429 con `Retry-After` |
| Paginación | `?page=1&per_page=50` (default 50, máx 200); respuesta `{"data": [...], "meta": {"page","per_page","total"}}`. Listados del panel (<5,000 filas) pueden pedir `per_page=200` |
| Fechas | `date` como `YYYY-MM-DD`; `datetime` como `YYYY-MM-DD HH:mm:ss` (TZ America/Ciudad_Juarez) |
| Filtrado de visibilidad | Siempre server-side por rol (doc 04 §A01, `App\Policies\VisibilidadAcuerdos`). **Nota de comportamiento (ADR-007, 2026-07-10, temporal/reversible — no es un cambio de contrato):** la LECTURA de acuerdos es actualmente **abierta** para los tres roles aprobados (dirección/coordinador/responsable) — todos ven todos los acuerdos, sin filtrar por área/participación. El 404 "existe pero no te es visible" hoy solo ocurre para ids inexistentes o para el rol `pendiente` (bloqueado antes de llegar aquí por la guardia `cuenta_pendiente`, ADR-006). La ESCRITURA (editar, avances) y la conclusión/reapertura **no** se abrieron: siguen devolviendo 403 `sin_permiso` sobre un recurso ya visible cuando el actor no cumple el permiso de escritura (antes ese mismo caso podía devolver 404 porque el recurso estaba oculto; ahora es visible y el guard de escritura responde su código normal) |

## 2. Recursos

### 2.1 Sesión

| Método/Ruta | Auth | Descripción |
|---|---|---|
| `POST /registro` | ID token verificado, **sin lista blanca** | Autorregistro (ADR-006): crea la cuenta del portador del token con rol `pendiente` |
| `GET /me` | Cualquier usuario activo | Identidad, rol, área, y configuración global de recordatorios visible |
| `PATCH /me` | Cualquier usuario activo (self) | Perfil self-service (ADR-005): edita únicamente su propio `nombre` |

```json
// 201 POST /registro  (request)  { "nombre": "Persona Nueva" }
// respuesta — uid/email SIEMPRE del token verificado, nunca del body
{ "data": { "id": 9, "nombre": "Persona Nueva", "email": "nueva@demo.test",
            "rol": "pendiente", "area_id": null, "activo": true, "avatar_color": null } }

// 409 — ya existe una cuenta con ese email o ese firebase_uid
{ "error": "cuenta_ya_existe", "mensaje": "Ya existe una cuenta para este correo. Inicia sesión." }

// 422 — body con cualquier campo distinto de `nombre` (p. ej. `rol`, `estado`, `email`)
{ "error": "campo_no_permitido", "mensaje": "El body contiene campos no permitidos.",
  "campos": { "rol": "Campo no permitido" } }
```
*Seguridad:* `POST /registro` corre detrás de `firebaseauth:sin_lista` — el token se verifica igual que en el resto de la API (401 `token_faltante`/`token_invalido` si falta o es inválido) pero **sin exigir que el usuario ya exista**; `uid`/`email` se toman del token verificado, jamás del body; `rol`/`estado` no son campos aceptados (siempre nace `rol: "pendiente"`). Un usuario `pendiente` **no tiene acceso funcional**: la guardia central `cuenta_pendiente` (en `FirebaseAuthFilter`, modo normal) responde 403 `{"error":"cuenta_pendiente","mensaje":"Tu cuenta está pendiente de aprobación."}` en cualquier ruta del grupo protegido salvo `GET/PATCH /me` — hasta que Dirección le asigna un rol operativo vía `PATCH /usuarios/{id}` (ADR-006).

```json
// 200 GET /me
{
  "usuario": { "id": 2, "nombre": "Coordinadora Demo Uno", "email": "coord1@demo.test",
               "rol": "coordinador", "area_id": 1, "activo": true, "avatar_color": null },
  "config_recordatorios": { "dias_antes": [7,3,1], "dia_compromiso": true,
                            "vencido_cada_dias": 3, "vencido_max_repeticiones": 5,
                            "resumen_frecuencia": "semanal", "solicitud_avances_activa": true,
                            "invitaciones_calendario_activas": false }
}
```
*Seguridad:* si el token es válido pero el email no está en la lista blanca ni tiene cuenta autorregistrada → 403 `usuario_no_registrado`. Una cuenta `rol: "pendiente"` (ADR-006) SÍ puede llamar `GET/PATCH /me` (para ver su estado y corregir su nombre) pero recibe 403 `cuenta_pendiente` en cualquier otro endpoint del panel.

```json
// PATCH /me  (request)  { "nombre": "Nuevo Nombre", "avatar_color": "#5b9df5" }
//   ambos opcionales pero al menos uno; avatar_color = color hex #RRGGBB o null (default)
// respuesta 200
{ "data": { "id": 2, "nombre": "Nuevo Nombre", "email": "coord1@demo.test",
            "rol": "coordinador", "area_id": 1, "activo": true, "avatar_color": "#5b9df5" } }

// 422 — cualquier campo distinto de `nombre`/`avatar_color` (email/rol/area_id/activo/...) es rechazado
{ "error": "campo_no_permitido", "mensaje": "El body contiene campos no permitidos.",
  "campos": { "rol": "Campo no permitido" } }

// 422 — nombre vacío o ausente
{ "error": "validacion", "mensaje": "Revisa los campos de tu perfil.",
  "campos": { "nombre": "Requerido" } }
```
*Seguridad:* `PATCH /me` solo modifica el registro del propio actor (`service('usuarioActual')`); rol/área/estado/email son inmutables por esta vía — su edición sigue exclusiva de `PATCH /usuarios/{id}` (solo Dirección). Invalida el `AuthCache` del actor y audita `editar_perfil` (entidad `usuario`). La contraseña se gestiona vía Firebase (email/password) client-side; no hay endpoint de contraseña en esta API (ADR-005).

### 2.2 Acuerdos

| Método/Ruta | Roles | Descripción |
|---|---|---|
| `GET /acuerdos` | Todos | Listado visible para el actor. Query: `estado` (`en_proceso\|vencido\|concluido\|todos_abiertos`), `responsable_id`, `mios=1` (solo acuerdos donde el actor es responsable o corresponsable; únicamente el literal `1` activa el filtro — ADR-013, v1.8), `q` (busca en tema+acción+responsable), `desde`/`hasta` (rango de `fecha_compromiso`, para la vista calendario), `page`, `per_page`. **Default sin `estado`: solo abiertos (`en_proceso`+`vencido`) — los concluidos exigen filtro explícito** (RF-03.3) |
| `GET /acuerdos/{id}` | Visibilidad | Detalle con corresponsables, avances y recordatorios del acuerdo |
| `POST /acuerdos/lote` | Todos | Captura transaccional de 1..N acuerdos (RF-02) |
| `PATCH /acuerdos/{id}` | Dirección, coordinación del área o quien lo capturó (ADR-011) | Editar tema/acción/área/responsable/`enlaces`/observaciones/`recordatorio_dias` |
| `DELETE /acuerdos/{id}` | **Solo Dirección** (403 auditado) | Borrado definitivo con cascada (avances, corresponsables, recordatorios, sync) + eliminación del evento de calendario; auditado con la ficha del acuerdo (ADR-011, v1.7). Respuesta 204 |
| `PUT /acuerdos/{id}/corresponsables` | Dirección, coordinación del área | Reemplaza el conjunto de corresponsables |
| `POST /acuerdos/{id}/avances` | Responsable, corresponsables, coordinación, dirección | Avance; con `nueva_fecha` = reprogramación (vencido→en_proceso) |
| `GET /acuerdos/{id}/actividad` | Visibilidad (ADR-007; `pendiente` → 403 `cuenta_pendiente`) | Bitácora unificada del acuerdo: fusiona `avances` (avance/reprogramación/validación/reapertura) y eventos de auditoría de ciclo de vida (crear/editar/corresponsables), orden descendente por `created_at` (v1.12) |
| `PATCH /acuerdos/{id}/concluir` | Dirección; coordinación del área (ADR-012) | Concluir con nota (RF-06). Si el acuerdo tenía una solicitud pendiente, concluir funciona además como **aprobación**: limpia `revision_estado` → `sin_solicitud` y avisa a responsable+corresponsables |
| `POST /acuerdos/{id}/solicitar-conclusion` | Responsable o corresponsable del acuerdo (v1.13) | Solicita marcarlo concluido: `revision_estado` → `pendiente` (congela vencimiento y silencia recordatorios); body opcional `{ "comentario": string }`; avisa a dirección + coordinación del área |
| `POST /acuerdos/{id}/rechazar-conclusion` | Dirección; coordinación del área (mismo permiso que `concluir`, v1.13) | Rechaza una solicitud pendiente con motivo obligatorio: `revision_estado` → `rechazada` (el acuerdo sigue `en_proceso`/`vencido`, editable); body `{ "motivo": string }`; avisa a responsable+corresponsables |
| `PATCH /acuerdos/{id}/reabrir` | **Solo Dirección** | Reabrir con nota obligatoria |

```json
// 201 POST /acuerdos/lote  (request)
{
  "reunion": { "nombre": "Reunión de dirección · 8 de julio", "fecha": "2026-07-08" },
  "acuerdos": [
    { "tema": "Panel de seguimiento", "accion": "Cargar acuerdos históricos de junio",
      "responsable_id": 5, "corresponsables_ids": [4, 6], "area_id": 1,
      "fecha_compromiso": "2026-07-20", "enlaces": ["https://drive.example/minuta", "https://fotos.example/jornada"], "observaciones": null,
      "recordatorio_dias": [5, 1] }
  ]
}
// respuesta
{ "data": [ { "id": 11, "estado": "en_proceso", "...": "acuerdo completo" } ] }
```

```json
// 422 — un renglón inválido anula el lote completo (todo-o-nada)
{ "error": "validacion", "mensaje": "El lote no se guardó: hay acuerdos incompletos.",
  "campos": { "acuerdos.0.fecha_compromiso": "Debe ser hoy o futura",
              "acuerdos.2.responsable_id": "Requerido" } }
```
*Seguridad:* `estado` NO es aceptado en ningún request de creación/edición (422 `campo_no_permitido`); `concluir`/`rechazar-conclusion` desde un rol o área sin permiso (ADR-012) → 403 `sin_permiso` con registro en auditoría (`intento_concluir`/`intento_rechazar_conclusion`); `solicitar-conclusion` desde alguien que no es responsable ni corresponsable → 403 `sin_permiso` (`intento_solicitar_conclusion`).

```json
// 200 GET /acuerdos/11 (detalle)
{
  "data": {
    "id": 11, "reunion": {"id": 3, "nombre": "Reunión de dirección · 8 de julio", "fecha": "2026-07-08"},
    "area": {"id": 1, "nombre": "Coordinación operativa"},
    "tema": "Panel de seguimiento", "accion": "Cargar acuerdos históricos de junio",
    "responsable": {"id": 5, "nombre": "Responsable Demo Dos", "email": "resp2@demo.test"},
    "corresponsables": [{"id": 4, "nombre": "Responsable Demo Uno", "email": "resp1@demo.test"}],
    "capturado_por": {"id": 2, "nombre": "Coordinadora Demo Uno"},
    "fecha_compromiso": "2026-07-20", "estado": "en_proceso",
    "enlaces": ["https://drive.example/minuta"], "observaciones": null, "recordatorio_dias": [5, 1],
    "concluido_por": null, "concluido_at": null,
    "revision_estado": "sin_solicitud", "revision_motivo_rechazo": null,
    "avances": [ {"id": 7, "usuario": {"id": 5, "nombre": "Responsable Demo Dos"},
                  "tipo": "avance", "descripcion": "Se cargó junio semana 1", "nueva_fecha": null,
                  "created_at": "2026-07-10 10:12:00"} ],
    "recordatorios": [ {"tipo": "previo", "programado_para": "2026-07-15", "estado": "programado"},
                       {"tipo": "previo", "programado_para": "2026-07-19", "estado": "programado"},
                       {"tipo": "dia",    "programado_para": "2026-07-20", "estado": "programado"} ],
    "created_at": "2026-07-08 09:30:00", "updated_at": null
  }
}
```

```json
// 200 GET /acuerdos/11/actividad
{
  "data": [
    { "id": "auditoria:45", "fuente": "auditoria", "tipo": "editar",
      "usuario": {"id": 2, "nombre": "Coordinadora Demo Uno"},
      "descripcion": "Editó fecha_compromiso y observaciones", "nueva_fecha": null,
      "created_at": "2026-07-12 09:05:00" },
    { "id": "avance:7", "fuente": "avance", "tipo": "avance",
      "usuario": {"id": 5, "nombre": "Responsable Demo Dos"},
      "descripcion": "Se cargó junio semana 1", "nueva_fecha": null,
      "created_at": "2026-07-10 10:12:00" },
    { "id": "auditoria:11", "fuente": "auditoria", "tipo": "crear",
      "usuario": {"id": 2, "nombre": "Coordinadora Demo Uno"},
      "descripcion": "Creó el acuerdo", "nueva_fecha": null,
      "created_at": "2026-07-08 09:30:00" }
  ]
}
```
*Notas:* `usuario` es `null` cuando el evento lo genera el sistema (p. ej. el job de `vencido`, que no se audita como evento de bitácora). Sin duplicados: `concluir`/`reabrir` **no** aparecen como eventos de `auditoria` porque ya llegan representados como avance (`tipo: 'validacion'`/`'reapertura'`) — la fusión evita contar dos veces la misma acción. `id` es una key compuesta (`"avance:N"` / `"auditoria:N"`) única entre ambas fuentes, pensada para `key` de listas en React, no para referenciar el registro por separado.

```json
// 200 POST /acuerdos/11/solicitar-conclusion  (request opcional) { "comentario": "Ya se entregó todo" }
// respuesta: detalle completo del acuerdo (mismo shape que GET /acuerdos/{id})
{ "data": { "id": 11, "...": "acuerdo completo", "revision_estado": "pendiente", "revision_motivo_rechazo": null } }

// 409 — ya concluido o ya con una solicitud pendiente
{ "error": "conflicto_estado", "mensaje": "El acuerdo ya tiene una solicitud de conclusión pendiente." }

// 403 — actor no es responsable ni corresponsable (se audita intento_solicitar_conclusion)
{ "error": "sin_permiso", "mensaje": "No puedes solicitar la conclusión de este acuerdo." }
```

```json
// 200 POST /acuerdos/11/rechazar-conclusion  (request)  { "motivo": "Falta evidencia de la entrega" }
// respuesta: detalle completo del acuerdo
{ "data": { "id": 11, "...": "acuerdo completo", "revision_estado": "rechazada", "revision_motivo_rechazo": "Falta evidencia de la entrega" } }

// 422 — motivo vacío o ausente
{ "error": "validacion", "mensaje": "Indica el motivo del rechazo.", "campos": { "motivo": "Requerido" } }

// 409 — no hay una solicitud pendiente que rechazar
{ "error": "conflicto_estado", "mensaje": "El acuerdo no tiene una solicitud de conclusión pendiente." }

// 403 — actor no es dirección ni coordinación del área (se audita intento_rechazar_conclusion)
{ "error": "sin_permiso", "mensaje": "No tienes permiso para rechazar esta solicitud." }
```
*Notas:* la **aprobación** de una solicitud pendiente reutiliza `PATCH /acuerdos/{id}/concluir` (sin endpoint nuevo): si `revision_estado` era `'pendiente'`, concluir limpia el flag a `'sin_solicitud'` y avisa a responsable+corresponsables. `revision_solicitada_por_id`/`revision_solicitada_at` (columnas del DDL) no se exponen en el DTO `Acuerdo`; solo `revision_estado` y `revision_motivo_rechazo` viajan al cliente.

```typescript
export type TipoEventoActividad =
  | 'avance' | 'reprogramacion' | 'validacion' | 'reapertura'
  | 'crear' | 'editar' | 'corresponsables';

export interface EventoActividad {
  id: string;                    // "avance:12" | "auditoria:45" — key único cross-tabla
  fuente: 'avance' | 'auditoria';
  tipo: TipoEventoActividad;
  usuario: UsuarioRef | null;    // null = acción del sistema
  descripcion: string;
  nueva_fecha: string | null;    // solo reprogramación
  created_at: string;
}
```

### 2.3 Recordatorios y configuración

| Método/Ruta | Roles | Descripción |
|---|---|---|
| `GET /recordatorios/proximos` | Todos (ámbito propio) | Envíos futuros materializables de los acuerdos visibles |
| `GET /recordatorios/historial` | Todos (ámbito propio) | `recordatorios_enviados` de acuerdos visibles |
| `GET /configuracion/recordatorios` | Todos | Config global vigente |
| `PUT /configuracion/recordatorios` | **Solo Dirección** | Actualiza default global (no toca overrides) |

*Seguridad:* el ámbito se filtra con la misma visibilidad de acuerdos; `PUT` valida `dias_antes` ⊆ [0..30] y ordenado. `solicitud_avances_activa` (bool) es opcional en el `PUT` —habilita/deshabilita los correos de solicitud de avances a los responsables; si se omite, conserva el valor vigente. `invitaciones_calendario_activas` (bool) también es opcional —habilita/deshabilita que Google Calendar envíe la invitación nativa por correo al crear/actualizar el evento (`sendUpdates=all|none`); el acuerdo se sincroniza igual; si se omite, conserva el valor vigente.

### 2.4 Checklist de validación

| Método/Ruta | Roles | Descripción |
|---|---|---|
| `GET /checklist` | **Solo Dirección** | Acuerdos abiertos priorizados (vencidos primero, luego por fecha) con evidencia resumida (nº avances, último avance, `enlaces`) |

### 2.5 Calendario

| Método/Ruta | Roles | Descripción |
|---|---|---|
| `GET /calendario?mes=2026-07` | Todos (ámbito propio) | Acuerdos visibles del mes agrupados por día (usa `desde/hasta` internamente); incluye concluidos solo con `incluir_concluidos=true` |

### 2.6 Usuarios y áreas (administración)

| Método/Ruta | Roles | Descripción |
|---|---|---|
| `GET /usuarios` | Todos | Directorio activo (para selects de responsable/corresponsables): id, nombre, rol, área |
| `POST /usuarios` | **Solo Dirección** | Alta: nombre, email, rol, area_id |
| `PATCH /usuarios/{id}` | **Solo Dirección** | Editar / activar / desactivar (422 si es el último dirección activo) |
| `GET /areas` | Todos | Catálogo de áreas activas (id, nombre, activa) |
| `GET /areas?todas=1` | **Solo Dirección** | Catálogo completo, activas e inactivas (para la sección de administración; 403 en otro rol) |
| `POST /areas` | **Solo Dirección** | Alta de área: `{ "nombre": "..." }` |
| `PATCH /areas/{id}` | **Solo Dirección** | Editar nombre y/o activar/desactivar |

```json
// 201 POST /areas  (request)  { "nombre": "Coordinación de vinculación" }
// respuesta
{ "data": { "id": 3, "nombre": "Coordinación de vinculación", "activa": true } }

// 422 nombre duplicado
{ "error": "validacion", "mensaje": "Revisa los campos del área.",
  "campos": { "nombre": "Ya existe un área con ese nombre" } }

// PATCH /areas/{id}  (request)  { "nombre": "Nuevo nombre", "activa": false }
// respuesta
{ "data": { "id": 3, "nombre": "Nuevo nombre", "activa": false } }
```
*Seguridad:* `POST`/`PATCH /areas` y `GET /areas?todas=1` solo Dirección (403 en otro rol); `nombre` requerido y único (422). Añadido en v1.2 (ADR-004); `?todas=1` en v1.5 (ADR-008).

### 2.7 Resumen

| Método/Ruta | Roles | Descripción |
|---|---|---|
| `GET /resumen` | Dirección (general), coordinación (su área) | Totales por estado, vencidos, por vencer ≤7 días, detalle por responsable (RF-11) |

### 2.8 Reservados post-MVP (no implementar en Fase 2)

`POST /google/conectar` · `DELETE /google/conexion` — OAuth por usuario para Google Tasks (ADR-003). Documentados para que el contrato no los pise.

## 3. Interfaz del cliente (`lib/api.ts` — CONGELADA)

> **CONGELADA (v1.13, 2026-07-30).** Este bloque es copia literal de `apps/web/src/lib/api.ts`. Cualquier cambio posterior actualiza ambos archivos en la misma sesión (regla №3 de CLAUDE.md) vía ADR corto. v1.2 añadió `crearArea`/`editarArea` (ADR-004). v1.3 añade `editarMiPerfil` (ADR-005). v1.4 añade `registrarme` (ADR-006). v1.5 añade el parámetro `todas` a `listAreas` (ADR-008). v1.8 añade el filtro `mios` a `listAcuerdos` (ADR-013). v1.12 añade `actividadAcuerdo(id)` (bitácora unificada de avances + auditoría). v1.13 añade `solicitarConclusion(id)` y `rechazarConclusion(id, motivo)` (ciclo de revisión de conclusión).

```typescript
export interface ApiClient {
  // sesión
  getMe(): Promise<Sesion>;
  editarMiPerfil(cambios: ActualizacionPerfil): Promise<Usuario>;
  registrarme(datos: RegistroCuenta): Promise<Usuario>; // ADR-006: autorregistro, rol nace `pendiente`

  // acuerdos
  listAcuerdos(filtros: FiltrosAcuerdos): Promise<Paginado<Acuerdo>>; // filtro opcional `mios`: responsable o corresponsable = actor (ADR-013)
  getAcuerdo(id: number): Promise<AcuerdoDetalle>;
  capturarLote(lote: LoteCaptura): Promise<Acuerdo[]>;
  editarAcuerdo(id: number, cambios: EdicionAcuerdo): Promise<Acuerdo>; // dirección, coordinación del área o quien lo capturó (ADR-011)
  eliminarAcuerdo(id: number): Promise<void>; // solo dirección (ADR-011)
  setCorresponsables(id: number, usuarioIds: number[]): Promise<AcuerdoDetalle>;
  registrarAvance(id: number, avance: NuevoAvance): Promise<AcuerdoDetalle>;
  actividadAcuerdo(id: number): Promise<EventoActividad[]>; // bitácora unificada (avances + auditoría de ciclo de vida)
  concluirAcuerdo(id: number, nota: string): Promise<Acuerdo>; // dirección, coordinación del área (ADR-012)
  reabrirAcuerdo(id: number, nota: string): Promise<Acuerdo>; // solo dirección
  solicitarConclusion(id: number): Promise<AcuerdoDetalle>; // responsable/corresponsable pide concluir → 'pendiente'
  rechazarConclusion(id: number, motivo: string): Promise<Acuerdo>; // admin/coordinación del área

  // recordatorios
  listRecordatoriosProximos(): Promise<RecordatorioVista[]>;
  listRecordatoriosHistorial(): Promise<RecordatorioVista[]>;
  getConfigRecordatorios(): Promise<ConfigRecordatorios>;
  setConfigRecordatorios(config: ConfigRecordatorios): Promise<ConfigRecordatorios>; // solo dirección

  // checklist / calendario / resumen
  getChecklist(): Promise<ChecklistItem[]>; // solo dirección
  getCalendario(mes: string, incluirConcluidos: boolean): Promise<CalendarioMes>;
  getResumen(): Promise<Resumen>;

  // administración
  listUsuarios(): Promise<Usuario[]>;
  crearUsuario(alta: AltaUsuario): Promise<Usuario>; // solo dirección
  editarUsuario(id: number, cambios: EdicionUsuario): Promise<Usuario>; // solo dirección
  listAreas(todas?: boolean): Promise<Area[]>; // todas=true incluye inactivas — solo dirección (ADR-008)
  crearArea(alta: AltaArea): Promise<Area>; // solo dirección
  editarArea(id: number, cambios: EdicionArea): Promise<Area>; // solo dirección
}
```

## 4. Notas transversales de seguridad

Todos los endpoints pasan por `FirebaseAuthFilter` + Throttle; los de escritura auditan en `auditoria`; los 403 de `concluir`/`reabrir`/`solicitar-conclusion`/`rechazar-conclusion` también se auditan (intento de abuso); ningún endpoint acepta `estado` ni `revision_estado` del cliente; los ids de usuario en payloads se validan contra usuarios **activos**. `POST /registro` es la única excepción a "lista blanca": corre con `firebaseauth:sin_lista` (token verificado, usuario aún no exigido) — ver ADR-006. La guardia `cuenta_pendiente` aplica a **todo** el resto de la API (salvo `GET/PATCH /me`): un rol `pendiente` no tiene acceso funcional hasta que Dirección le asigna rol.

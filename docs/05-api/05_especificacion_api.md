# 05 — Especificación de API

| Campo | Valor |
|---|---|
| Documento | 05 — Especificación de API REST |
| Versión | 1.5 — **CONGELADA** (2026-07-13, Gobernanza v3 §4; interfaz literal de `apps/web/src/lib/api.ts`). v1.2 añadió `crearArea`/`editarArea` (ADR-004). v1.3 añade `editarMiPerfil` / `PATCH /me` (ADR-005). v1.4 añade `registrarme` / `POST /registro` y el rol `pendiente` (ADR-006). v1.5 añade `GET /areas?todas=1` / `listAreas(todas?)` (ADR-008). |
| Fecha | 2026-07-13 |
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
            "rol": "pendiente", "area_id": null, "activo": true } }

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
               "rol": "coordinador", "area_id": 1, "activo": true },
  "config_recordatorios": { "dias_antes": [7,3,1], "dia_compromiso": true,
                            "vencido_cada_dias": 3, "vencido_max_repeticiones": 5,
                            "resumen_frecuencia": "semanal" }
}
```
*Seguridad:* si el token es válido pero el email no está en la lista blanca ni tiene cuenta autorregistrada → 403 `usuario_no_registrado`. Una cuenta `rol: "pendiente"` (ADR-006) SÍ puede llamar `GET/PATCH /me` (para ver su estado y corregir su nombre) pero recibe 403 `cuenta_pendiente` en cualquier otro endpoint del panel.

```json
// PATCH /me  (request)  { "nombre": "Nuevo Nombre" }
// respuesta 200
{ "data": { "id": 2, "nombre": "Nuevo Nombre", "email": "coord1@demo.test",
            "rol": "coordinador", "area_id": 1, "activo": true } }

// 422 — cualquier campo distinto de `nombre` (email/rol/area_id/activo/...) es rechazado
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
| `GET /acuerdos` | Todos | Listado visible para el actor. Query: `estado` (`en_proceso\|vencido\|concluido\|todos_abiertos`), `responsable_id`, `q` (busca en tema+acción+responsable), `desde`/`hasta` (rango de `fecha_compromiso`, para la vista calendario), `page`, `per_page`. **Default sin `estado`: solo abiertos (`en_proceso`+`vencido`) — los concluidos exigen filtro explícito** (RF-03.3) |
| `GET /acuerdos/{id}` | Visibilidad | Detalle con corresponsables, avances y recordatorios del acuerdo |
| `POST /acuerdos/lote` | Todos | Captura transaccional de 1..N acuerdos (RF-02) |
| `PATCH /acuerdos/{id}` | Dirección, coordinación del área | Editar tema/acción/área/responsable/enlace/observaciones/`recordatorio_dias` |
| `PUT /acuerdos/{id}/corresponsables` | Dirección, coordinación del área | Reemplaza el conjunto de corresponsables |
| `POST /acuerdos/{id}/avances` | Responsable, corresponsables, coordinación, dirección | Avance; con `nueva_fecha` = reprogramación (vencido→en_proceso) |
| `PATCH /acuerdos/{id}/concluir` | **Solo Dirección** | Concluir con nota (RF-06) |
| `PATCH /acuerdos/{id}/reabrir` | **Solo Dirección** | Reabrir con nota obligatoria |

```json
// 201 POST /acuerdos/lote  (request)
{
  "reunion": { "nombre": "Reunión de dirección · 8 de julio", "fecha": "2026-07-08" },
  "acuerdos": [
    { "tema": "Panel de seguimiento", "accion": "Cargar acuerdos históricos de junio",
      "responsable_id": 5, "corresponsables_ids": [4, 6], "area_id": 1,
      "fecha_compromiso": "2026-07-20", "enlace": null, "observaciones": null,
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
*Seguridad:* `estado` NO es aceptado en ningún request de creación/edición (422 `campo_no_permitido`); `concluir` desde rol distinto a dirección → 403 con registro en auditoría.

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
    "enlace": null, "observaciones": null, "recordatorio_dias": [5, 1],
    "concluido_por": null, "concluido_at": null,
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

### 2.3 Recordatorios y configuración

| Método/Ruta | Roles | Descripción |
|---|---|---|
| `GET /recordatorios/proximos` | Todos (ámbito propio) | Envíos futuros materializables de los acuerdos visibles |
| `GET /recordatorios/historial` | Todos (ámbito propio) | `recordatorios_enviados` de acuerdos visibles |
| `GET /configuracion/recordatorios` | Todos | Config global vigente |
| `PUT /configuracion/recordatorios` | **Solo Dirección** | Actualiza default global (no toca overrides) |

*Seguridad:* el ámbito se filtra con la misma visibilidad de acuerdos; `PUT` valida `dias_antes` ⊆ [0..30] y ordenado.

### 2.4 Checklist de validación

| Método/Ruta | Roles | Descripción |
|---|---|---|
| `GET /checklist` | **Solo Dirección** | Acuerdos abiertos priorizados (vencidos primero, luego por fecha) con evidencia resumida (nº avances, último avance, enlace) |

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

> **CONGELADA (v1.5, 2026-07-13).** Este bloque es copia literal de `apps/web/src/lib/api.ts`. Cualquier cambio posterior actualiza ambos archivos en la misma sesión (regla №3 de CLAUDE.md) vía ADR corto. v1.2 añadió `crearArea`/`editarArea` (ADR-004). v1.3 añade `editarMiPerfil` (ADR-005). v1.4 añade `registrarme` (ADR-006). v1.5 añade el parámetro `todas` a `listAreas` (ADR-008).

```typescript
export interface ApiClient {
  // sesión
  getMe(): Promise<Sesion>;
  editarMiPerfil(cambios: ActualizacionPerfil): Promise<Usuario>;
  registrarme(datos: RegistroCuenta): Promise<Usuario>; // ADR-006: autorregistro, rol nace `pendiente`

  // acuerdos
  listAcuerdos(filtros: FiltrosAcuerdos): Promise<Paginado<Acuerdo>>;
  getAcuerdo(id: number): Promise<AcuerdoDetalle>;
  capturarLote(lote: LoteCaptura): Promise<Acuerdo[]>;
  editarAcuerdo(id: number, cambios: EdicionAcuerdo): Promise<Acuerdo>;
  setCorresponsables(id: number, usuarioIds: number[]): Promise<AcuerdoDetalle>;
  registrarAvance(id: number, avance: NuevoAvance): Promise<AcuerdoDetalle>;
  concluirAcuerdo(id: number, nota: string): Promise<Acuerdo>; // solo dirección
  reabrirAcuerdo(id: number, nota: string): Promise<Acuerdo>; // solo dirección

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

Todos los endpoints pasan por `FirebaseAuthFilter` + Throttle; los de escritura auditan en `auditoria`; los 403 de `concluir/reabrir` también se auditan (intento de abuso); ningún endpoint acepta `estado` del cliente; los ids de usuario en payloads se validan contra usuarios **activos**. `POST /registro` es la única excepción a "lista blanca": corre con `firebaseauth:sin_lista` (token verificado, usuario aún no exigido) — ver ADR-006. La guardia `cuenta_pendiente` aplica a **todo** el resto de la API (salvo `GET/PATCH /me`): un rol `pendiente` no tiene acceso funcional hasta que Dirección le asigna rol.

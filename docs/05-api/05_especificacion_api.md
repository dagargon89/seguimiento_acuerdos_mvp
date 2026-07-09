# 05 — Especificación de API

| Campo | Valor |
|---|---|
| Documento | 05 — Especificación de API REST |
| Versión | 1.1 — **CONGELADA** (2026-07-09, Gobernanza v3 §4; interfaz literal de `demo-ux/app/src/lib/api.ts`) |
| Fecha | 2026-07-08 |
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
| Filtrado de visibilidad | Siempre server-side por rol (doc 04 §A01); un 404 puede significar "existe pero no te es visible" |

## 2. Recursos

### 2.1 Sesión

| Método/Ruta | Auth | Descripción |
|---|---|---|
| `GET /me` | Cualquier usuario activo | Identidad, rol, área, y configuración global de recordatorios visible |

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
*Seguridad:* si el token es válido pero el email no está en la lista blanca → 403 `usuario_no_registrado`.

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
| `GET /areas` · `POST /areas` · `PATCH /areas/{id}` | GET todos · resto Dirección | Catálogo de áreas |

### 2.7 Resumen

| Método/Ruta | Roles | Descripción |
|---|---|---|
| `GET /resumen` | Dirección (general), coordinación (su área) | Totales por estado, vencidos, por vencer ≤7 días, detalle por responsable (RF-11) |

### 2.8 Reservados post-MVP (no implementar en Fase 2)

`POST /google/conectar` · `DELETE /google/conexion` — OAuth por usuario para Google Tasks (ADR-003). Documentados para que el contrato no los pise.

## 3. Interfaz del cliente (`lib/api.ts` — CONGELADA)

> **CONGELADA el 2026-07-09.** Este bloque es copia literal de `demo-ux/app/src/lib/api.ts`. Cualquier cambio posterior actualiza ambos archivos en la misma sesión (regla №3 de CLAUDE.md) vía ADR corto.

```typescript
export interface ApiClient {
  // sesión
  getMe(): Promise<Sesion>;

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
  listAreas(): Promise<Area[]>;
}
```

## 4. Notas transversales de seguridad

Todos los endpoints pasan por `FirebaseAuthFilter` + Throttle; los de escritura auditan en `auditoria`; los 403 de `concluir/reabrir` también se auditan (intento de abuso); ningún endpoint acepta `estado` del cliente; los ids de usuario en payloads se validan contra usuarios **activos**.

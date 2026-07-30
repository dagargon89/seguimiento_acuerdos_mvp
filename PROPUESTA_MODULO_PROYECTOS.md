# 10 — Propuesta de escalamiento: Módulo de Proyectos y Tareas

| Campo | Valor |
|---|---|
| Documento | 10 — Propuesta de escalamiento a gestión de proyectos ("Fase 3") |
| Versión | 1.0 (borrador para revisión de Dirección) |
| Fecha | 2026-07-30 |
| Autor | Equipo de desarrollo (asistido) |
| Estado | 🟨 Propuesta — pendiente de aprobación (gate CLAUDE.md: sin aprobación no se genera código) |
| Depende de | 01_SRS, 02_arquitectura, 03_modelo_de_datos, 05_API, ADR-002/006/007/011/012, 08_backlog (ítems 5, 6, 9, 11) |
| Rama | `claude/project-scalability-wzsydi` |

---

## Índice

1. [Resumen ejecutivo](#1-resumen-ejecutivo)
2. [Diagnóstico del estado actual](#2-diagnóstico-del-estado-actual)
3. [Decisión de arquitectura de dominio](#3-decisión-de-arquitectura-de-dominio)
4. [Modelo de datos propuesto (DDL)](#4-modelo-de-datos-propuesto-ddl)
5. [Modelo de permisos](#5-modelo-de-permisos)
6. [Contrato de API](#6-contrato-de-api)
7. [UI/UX: vistas y componentes](#7-uiux-vistas-y-componentes)
8. [Generalización del motor de notificaciones](#8-generalización-del-motor-de-notificaciones)
9. [Análisis riguroso de servicios Firebase](#9-análisis-riguroso-de-servicios-firebase)
10. [Catálogo de funcionalidades adicionales](#10-catálogo-de-funcionalidades-adicionales)
11. [Plan de implementación por fases](#11-plan-de-implementación-por-fases)
12. [Riesgos y mitigaciones](#12-riesgos-y-mitigaciones)
13. [Cumplimiento de las reglas no negociables](#13-cumplimiento-de-las-reglas-no-negociables)
14. [Anexo A — Borrador ADR-015](#14-anexo-a--borrador-adr-015)
15. [Anexo B — Preguntas abiertas para Dirección](#15-anexo-b--preguntas-abiertas-para-dirección)

---

## 1. Resumen ejecutivo

El Panel de Acuerdos cubre hoy el ciclo de vida completo del **acuerdo de reunión de
dirección**. Esta propuesta lo escala a una plataforma de **gestión de trabajo** estilo
Monday/Asana: alta de **proyectos**, incorporación de **miembros** con roles por
proyecto, y delegación de **tareas** (con asignado, colaboradores, fechas, estados,
subtareas, comentarios y tablero kanban/gantt), manteniendo intacto el dominio de
acuerdos ya aprobado y en producción.

**Tesis central:** no se construye un gestor de proyectos desde cero; se **generaliza un
objeto de trabajo que ya está bien modelado**. El acuerdo ya tiene responsable único,
corresponsables N:M, fecha compromiso, estados con transiciones auditadas, bitácora
unificada, motor de recordatorios por Gmail y sincronización con Google Calendar. Lo que
falta es el contenedor (`proyecto`) y la unidad de trabajo delegable fuera de reunión
(`tarea`).

**Decisión recomendada:** módulo paralelo que comparte cimientos (usuarios, áreas,
auditoría, notificaciones) sin fusionar dominios. El acuerdo gana una columna opcional
`proyecto_id`; nada del contrato congelado se rompe.

**Esfuerzo estimado (núcleo, sin campos personalizados):** 6–8 semanas en 6 fases, la
primera de las cuales es un refactor habilitador sin cambio funcional.

**Firebase:** además de Authentication (ya en uso), se recomienda adoptar **Cloud
Messaging (push web)** y **App Check** en el corto plazo, evaluar **Storage** para
evidencias y **Remote Config** para feature flags, y **descartar** Firestore/Realtime
Database como almacén de dominio (MySQL sigue siendo la única fuente de verdad). El
detalle servicio por servicio está en §9.

---

## 2. Diagnóstico del estado actual

### 2.1 Activos reutilizables (lo que ya está construido)

| Pieza de un gestor de proyectos | Equivalente ya existente | Reutilización |
|---|---|---|
| Personas y autenticación | `usuarios` + Firebase Auth (Google + email/password) + Filter CI4 con cache Redis 60 s | Directa, sin cambios |
| Unidades organizativas | `areas` + ADR-004 | Directa |
| Asignado + colaboradores | `responsable_id` + `acuerdo_corresponsables` (N:M con `tipo_asignacion`) | Patrón a calcar |
| Comentarios / updates | `avances` con `tipo` (avance, reprogramación, validación, reapertura) | Patrón a calcar |
| Activity log | `auditoria` polimórfica (`entidad` + `entidad_id`) + `GET /acuerdos/{id}/actividad` | **Directa** (ya es genérica) |
| Estados derivados del tiempo | `vencido` asignado solo por el sistema (regla 5) | Patrón a calcar |
| Flujo de aprobación | Solicitud de conclusión (ADR-014) + checklist de validación | Patrón a calcar |
| Motor de notificaciones | `RecordatorioService` + `recordatorios_enviados` + Gmail API + cron 08:00 | Generalizar (§8) |
| Calendario compartido | `google_sync` + Calendar API (ADR-009) | Generalizar |
| Vistas de trabajo | Panel con 5 vistas: tabla, tarjetas/kanban, por reunión, cronograma/gantt, calendario | Extraer a componentes genéricos |
| Permisos | `Policies/VisibilidadAcuerdos.php` + pruebas negativas + 403 auditado | Patrón a calcar |
| Contrato único | `ApiClient` (api.ts ≡ doc 05) + espejo `db.json` ↔ DDL verificable | Extender en la misma disciplina |
| PWA | `vite-plugin-pwa` + service worker + `ActualizacionSW.tsx` | Base para push (FCM) |

### 2.2 Deuda técnica que bloquea el escalamiento (pagar ANTES)

| Deuda | Evidencia | Riesgo si no se paga | Acción |
|---|---|---|---|
| Lógica de dominio en el controlador | `AcuerdosController.php` ≈ 1,750 líneas; `app/Services/` solo tiene `RecordatorioService` y `ResumenCorrida` | Duplicar el patrón para tareas produce ~4,000 líneas de reglas de negocio copiadas y divergentes | Extraer `AcuerdoService` (transiciones de estado, conclusión, avances, corresponsables) |
| Vistas monolíticas | `Panel.tsx` ≈ 1,200 líneas con las 5 vistas dentro | Kanban/gantt no reutilizables para tareas; se duplicaría UI validada | Extraer `<TablaTrabajo>`, `<Kanban>`, `<Gantt>` genéricos (reciben items tipados, no `Acuerdo[]`) |
| Notificaciones acopladas | `recordatorios_enviados.acuerdo_id` y `google_sync.acuerdo_id` con FK dura | Dos motores de correo y dos sync paralelos | Migrar a referencia polimórfica (`entidad` + `entidad_id`), como ya hace `auditoria` |

### 2.3 Alineación con el backlog vigente (doc 08)

Esta propuesta **absorbe** cuatro ítems del backlog en lugar de competir con ellos:

- **#5 Gestión de Reuniones** — la pantalla de proyecto es el mismo patrón contenedor→items.
- **#6 Dashboard de cumplimiento** — se entrega como dashboard por proyecto + global (Fase 6).
- **#9 Notificaciones in-app (campana)** — se entrega junto con FCM (§9.2, Fase 5).
- **#11 Integración tablero de metas estratégicas (H-10)** — el `proyecto` ES el modelo de metas que el ítem pedía definir.

---

## 3. Decisión de arquitectura de dominio

### 3.1 Opciones evaluadas

| | A. Unificar en `tareas` | **B. Módulo paralelo (recomendada)** | C. Proyectos como capa de reporte |
|---|---|---|---|
| Descripción | Una sola tabla de trabajo; el acuerdo es un tipo de tarea | Tablas nuevas `proyectos`/`tareas`; comparten usuarios, áreas, auditoría, notificaciones; `acuerdos.proyecto_id` opcional | Los proyectos solo agrupan acuerdos existentes; no hay tareas nuevas |
| Impacto en lo aprobado | Reescribe DDL doc 03, ADR-007/011/012, `AcuerdosController` | **Cero cambios de semántica en acuerdos** (una columna nullable) | Cero |
| ¿Permite delegar tareas fuera de reunión? | Sí | Sí | **No** (descarta el objetivo) |
| Riesgo sobre producción | Alto | Bajo | Nulo |
| Esfuerzo | 3–4 meses | 6–8 semanas | 1–2 semanas |
| Deuda conceptual futura | Baja | Media (dos objetos de trabajo similares) | Alta (el problema queda sin resolver) |

### 3.2 Justificación de B

1. **El sistema está en producción y validado por Dirección** (regla 11: el diseño
   aprobado no se altera sin autorización). A invalida esa aprobación; B la preserva.
2. **Acuerdo ≠ tarea en el negocio:** el acuerdo nace en reunión de dirección, exige
   validación de conclusión (ADR-012/014) y trazabilidad institucional. La tarea es
   operativa, la concluye su propio flujo de proyecto. Forzar un solo objeto obliga a
   llenar de condicionales el flujo institucional.
3. **El puente es aditivo:** `acuerdos.proyecto_id INT NULL` permite que un acuerdo de
   dirección "aterrice" en un proyecto y aparezca en su tablero (solo lectura desde el
   proyecto; su ciclo de vida sigue gobernado por sus ADR).
4. **Reversibilidad:** si Dirección descarta el módulo, se elimina sin tocar acuerdos.

---

## 4. Modelo de datos propuesto (DDL)

Nuevo archivo `docs/03-datos/panel_proyectos_ddl.sql` (el DDL de acuerdos no se edita,
salvo la migración aditiva del §4.3). Mismo estándar del doc 03: InnoDB, utf8mb4,
`America/Ciudad_Juarez`, CHECKs de consistencia, índices por patrón de consulta.

### 4.1 Tablas nuevas

```sql
-- ---------------------------------------------------------------------------
-- PROYECTOS: contenedor de trabajo. `clave` corta para referencias (PJ-OP-01).
-- ---------------------------------------------------------------------------
CREATE TABLE proyectos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave          VARCHAR(12)  NOT NULL,
  nombre         VARCHAR(160) NOT NULL,
  descripcion    TEXT         NULL,
  area_id        INT UNSIGNED NULL,               -- área "dueña" (opcional: hay proyectos transversales)
  lider_id       INT UNSIGNED NOT NULL,
  estado         ENUM('planeado','activo','pausado','cerrado','archivado')
                 NOT NULL DEFAULT 'planeado',
  color          CHAR(7)      NULL,               -- identidad visual del proyecto (#rrggbb)
  fecha_inicio   DATE         NULL,
  fecha_fin_plan DATE         NULL,
  fecha_fin_real DATE         NULL,
  creado_por_id  INT UNSIGNED NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_proyectos_clave (clave),
  KEY idx_proyectos_estado (estado),
  KEY idx_proyectos_area (area_id, estado),
  CONSTRAINT fk_proy_area    FOREIGN KEY (area_id)       REFERENCES areas (id),
  CONSTRAINT fk_proy_lider   FOREIGN KEY (lider_id)      REFERENCES usuarios (id),
  CONSTRAINT fk_proy_creador FOREIGN KEY (creado_por_id) REFERENCES usuarios (id),
  CONSTRAINT chk_proy_cerrado CHECK (
    (estado IN ('cerrado','archivado') AND fecha_fin_real IS NOT NULL)
    OR (estado NOT IN ('cerrado','archivado'))
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- MIEMBROS: membresía con rol por proyecto. La ESCRITURA se gobierna aquí;
-- la LECTURA sigue abierta (coherente con ADR-007, ver §5).
-- ---------------------------------------------------------------------------
CREATE TABLE proyecto_miembros (
  proyecto_id  INT UNSIGNED NOT NULL,
  usuario_id   INT UNSIGNED NOT NULL,
  rol_proyecto ENUM('lider','editor','miembro','observador') NOT NULL DEFAULT 'miembro',
  agregado_por_id INT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (proyecto_id, usuario_id),
  KEY idx_miembros_usuario (usuario_id),
  CONSTRAINT fk_miem_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos (id) ON DELETE CASCADE,
  CONSTRAINT fk_miem_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios (id),
  CONSTRAINT fk_miem_agrego   FOREIGN KEY (agregado_por_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- SECCIONES: columnas del kanban / agrupadores de la tabla, por proyecto.
-- `orden` DECIMAL para reordenar sin renumerar (inserción fraccional).
-- ---------------------------------------------------------------------------
CREATE TABLE proyecto_secciones (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proyecto_id INT UNSIGNED NOT NULL,
  nombre      VARCHAR(80)  NOT NULL,
  orden       DECIMAL(16,6) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_secciones_proyecto (proyecto_id, orden),
  CONSTRAINT fk_secc_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- TAREAS: unidad de trabajo delegable. Calca el patrón del acuerdo
-- (asignado único + colaboradores N:M + estados + fecha compromiso) y añade
-- jerarquía (subtareas), prioridad, sección y orden.
-- `vencida` se DERIVA (fecha_compromiso < hoy AND estado <> 'terminada');
-- no se persiste: regla 5 (estados temporales los asigna solo el sistema).
-- ---------------------------------------------------------------------------
CREATE TABLE tareas (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proyecto_id      INT UNSIGNED NOT NULL,
  seccion_id       INT UNSIGNED NULL,
  tarea_padre_id   INT UNSIGNED NULL,             -- subtarea (1 nivel en v1; CHECK en Service)
  titulo           VARCHAR(200) NOT NULL,
  descripcion      MEDIUMTEXT   NULL,             -- HTML saneado (mismo pipeline Tiptap+DOMPurify)
  asignado_id      INT UNSIGNED NULL,             -- puede nacer sin asignar (backlog del proyecto)
  estado           ENUM('backlog','en_proceso','en_revision','terminada','cancelada')
                   NOT NULL DEFAULT 'backlog',
  prioridad        ENUM('baja','media','alta','urgente') NOT NULL DEFAULT 'media',
  fecha_inicio     DATE NULL,
  fecha_compromiso DATE NULL,
  orden            DECIMAL(16,6) NOT NULL DEFAULT 0,
  acuerdo_id       INT UNSIGNED NULL,             -- origen: tarea derivada de un acuerdo
  terminada_por_id INT UNSIGNED NULL,
  terminada_at     DATETIME NULL,
  creado_por_id    INT UNSIGNED NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tareas_proyecto (proyecto_id, estado, orden),
  KEY idx_tareas_asignado (asignado_id, estado, fecha_compromiso),
  KEY idx_tareas_padre (tarea_padre_id),
  KEY idx_tareas_seccion (seccion_id, orden),
  CONSTRAINT fk_tarea_proyecto  FOREIGN KEY (proyecto_id)     REFERENCES proyectos (id),
  CONSTRAINT fk_tarea_seccion   FOREIGN KEY (seccion_id)      REFERENCES proyecto_secciones (id) ON DELETE SET NULL,
  CONSTRAINT fk_tarea_padre     FOREIGN KEY (tarea_padre_id)  REFERENCES tareas (id) ON DELETE CASCADE,
  CONSTRAINT fk_tarea_asignado  FOREIGN KEY (asignado_id)     REFERENCES usuarios (id),
  CONSTRAINT fk_tarea_acuerdo   FOREIGN KEY (acuerdo_id)      REFERENCES acuerdos (id) ON DELETE SET NULL,
  CONSTRAINT fk_tarea_terminada FOREIGN KEY (terminada_por_id) REFERENCES usuarios (id),
  CONSTRAINT fk_tarea_creador   FOREIGN KEY (creado_por_id)   REFERENCES usuarios (id),
  CONSTRAINT chk_tarea_terminada CHECK (
    (estado = 'terminada' AND terminada_por_id IS NOT NULL AND terminada_at IS NOT NULL)
    OR (estado <> 'terminada' AND terminada_por_id IS NULL AND terminada_at IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- COLABORADORES DE TAREA: calca acuerdo_corresponsables.
-- ---------------------------------------------------------------------------
CREATE TABLE tarea_colaboradores (
  tarea_id   INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tarea_id, usuario_id),
  KEY idx_colab_usuario (usuario_id),
  CONSTRAINT fk_colab_tarea   FOREIGN KEY (tarea_id)   REFERENCES tareas (id) ON DELETE CASCADE,
  CONSTRAINT fk_colab_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- COMENTARIOS DE TAREA: calca `avances` (tipo discrimina eventos de flujo).
-- ---------------------------------------------------------------------------
CREATE TABLE tarea_comentarios (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tarea_id   INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  tipo       ENUM('comentario','avance','reprogramacion','cambio_estado') NOT NULL DEFAULT 'comentario',
  contenido  MEDIUMTEXT NOT NULL,                 -- HTML saneado
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_coment_tarea (tarea_id, created_at),
  CONSTRAINT fk_coment_tarea   FOREIGN KEY (tarea_id)   REFERENCES tareas (id) ON DELETE CASCADE,
  CONSTRAINT fk_coment_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- DEPENDENCIAS (Fase 4): bloqueos entre tareas del mismo proyecto.
-- La detección de ciclos vive en TareaService (BFS sobre el grafo), no en SQL.
-- ---------------------------------------------------------------------------
CREATE TABLE tarea_dependencias (
  tarea_id        INT UNSIGNED NOT NULL,          -- la tarea bloqueada
  depende_de_id   INT UNSIGNED NOT NULL,          -- la tarea que bloquea
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tarea_id, depende_de_id),
  KEY idx_dep_bloqueante (depende_de_id),
  CONSTRAINT fk_dep_tarea    FOREIGN KEY (tarea_id)      REFERENCES tareas (id) ON DELETE CASCADE,
  CONSTRAINT fk_dep_bloquea  FOREIGN KEY (depende_de_id) REFERENCES tareas (id) ON DELETE CASCADE,
  CONSTRAINT chk_dep_no_self CHECK (tarea_id <> depende_de_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4.2 Generalización de tablas de soporte (migraciones)

```sql
-- recordatorios_enviados: de FK dura a referencia polimórfica (como auditoria).
ALTER TABLE recordatorios_enviados
  ADD COLUMN entidad ENUM('acuerdo','tarea','proyecto') NOT NULL DEFAULT 'acuerdo' AFTER id,
  ADD COLUMN entidad_id INT UNSIGNED NULL AFTER entidad;
-- Backfill: UPDATE recordatorios_enviados SET entidad_id = acuerdo_id;
-- La FK acuerdo_id se conserva una versión (deprecada) y se retira en migración posterior,
-- junto con el reemplazo del UNIQUE uq_recordatorio_unico por (entidad, entidad_id, usuario_id, tipo, programado_para).

-- google_sync: mismo tratamiento (evento de calendario por tarea con fecha compromiso).
ALTER TABLE google_sync
  ADD COLUMN entidad ENUM('acuerdo','tarea') NOT NULL DEFAULT 'acuerdo' AFTER id,
  ADD COLUMN entidad_id INT UNSIGNED NULL AFTER entidad;
```

### 4.3 Único cambio a `acuerdos` (aditivo)

```sql
ALTER TABLE acuerdos
  ADD COLUMN proyecto_id INT UNSIGNED NULL AFTER reunion_id,
  ADD KEY idx_acuerdos_proyecto (proyecto_id),
  ADD CONSTRAINT fk_acuerdos_proyecto FOREIGN KEY (proyecto_id)
      REFERENCES proyectos (id) ON DELETE SET NULL;
```

El ciclo de vida del acuerdo **no cambia**: sus estados, su conclusión (ADR-012), su
solicitud de revisión (ADR-014) y sus recordatorios siguen exactamente igual. El
`proyecto_id` solo hace que el acuerdo aparezca (solo lectura) en el tablero del proyecto.

### 4.4 Espejo `db.json`

Regla 1 del CLAUDE.md: las tablas nuevas se replican en `apps/web/src/lib/mock/db.json`
y `scripts/verificar_espejo.mjs` se extiende para validar también
`panel_proyectos_ddl.sql`. La verificación ejecutable es criterio de cierre de la Fase 2
de esta propuesta.

---

## 5. Modelo de permisos

### 5.1 Principio

**Lectura abierta, escritura por membresía.** Coherente con ADR-007 ("trabajamos en
conjunto"): todo rol operativo ve todos los proyectos y tareas. La escritura se gobierna
por `proyecto_miembros.rol_proyecto`, con Dirección como superusuario (como hoy). Esto
evita un ADR que contradiga la visibilidad aprobada y ahorra el join de membresía en
cada consulta de lectura.

### 5.2 Matriz de autorización (base de `Policies/PermisosProyecto.php`)

| Acción | Dirección | Líder | Editor | Miembro | Observador | No miembro | Pendiente |
|---|---|---|---|---|---|---|---|
| Ver proyecto y tareas | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (lectura) | ❌ |
| Crear proyecto | ✅ | — | — | — | — | ✅* | ❌ |
| Editar proyecto / secciones | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Gestionar miembros | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Cerrar / archivar proyecto | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Eliminar proyecto | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Crear tarea | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| Editar cualquier tarea del proyecto | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Editar tarea propia (asignado/colaborador/creador) | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| Comentar | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Terminar tarea | ✅ | ✅ | ✅ | ✅ (propia) | ❌ | ❌ | ❌ |
| Eliminar tarea | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

\* Quién puede crear proyectos es **pregunta abierta B-1** (§15): propuesta por defecto,
dirección + coordinación; configurable después.

### 5.3 Invariantes (mismas garantías que acuerdos)

- Todo intento denegado responde **403 y se audita** (`intento_*` en `auditoria`), como
  exige la regla 4. Toda Policy nueva nace con su **prueba negativa**.
- El rol global `pendiente` no ve nada (RF-01/ADR-006 se mantienen).
- `terminada` exige `terminada_por_id` + `terminada_at` (CHECK en BD, no solo en código).
- El asignado de una tarea **debe ser miembro** del proyecto; asignar a un no-miembro lo
  agrega automáticamente como `miembro` (con evento de auditoría) — reduce fricción sin
  perder trazabilidad.

---

## 6. Contrato de API

Extensión del `ApiClient` (regla 3: `api.ts` y doc 05 se actualizan en la misma sesión).
Todos los endpoints viven bajo el grupo protegido `api/v1` (CORS + FirebaseAuth +
Throttle), mismos filtros que hoy.

```ts
export interface ApiClient {
  // ... contrato actual sin cambios ...

  // proyectos
  listProyectos(filtros?: FiltrosProyectos): Promise<Paginado<Proyecto>>;
  getProyecto(id: number): Promise<ProyectoDetalle>;       // incluye miembros + secciones + métricas
  crearProyecto(alta: AltaProyecto): Promise<Proyecto>;
  editarProyecto(id: number, cambios: EdicionProyecto): Promise<Proyecto>;
  cambiarEstadoProyecto(id: number, estado: EstadoProyecto, nota?: string): Promise<Proyecto>;
  eliminarProyecto(id: number): Promise<void>;             // solo dirección

  // membresía
  setMiembros(proyectoId: number, miembros: MiembroInput[]): Promise<ProyectoDetalle>;

  // secciones (kanban)
  crearSeccion(proyectoId: number, nombre: string): Promise<Seccion>;
  editarSeccion(id: number, cambios: EdicionSeccion): Promise<Seccion>;   // nombre u orden
  eliminarSeccion(id: number, moverTareasA?: number): Promise<void>;

  // tareas
  listTareas(proyectoId: number, filtros?: FiltrosTareas): Promise<Tarea[]>;
  listMisTareas(filtros?: FiltrosTareas): Promise<Paginado<Tarea>>;       // cross-proyecto (paralelo a `mios` de ADR-013)
  getTarea(id: number): Promise<TareaDetalle>;
  crearTarea(proyectoId: number, alta: AltaTarea): Promise<Tarea>;
  editarTarea(id: number, cambios: EdicionTarea): Promise<Tarea>;
  moverTarea(id: number, destino: { seccionId?: number; despuesDeId?: number }): Promise<Tarea>; // drag & drop
  terminarTarea(id: number, nota?: string): Promise<Tarea>;
  reabrirTarea(id: number, nota: string): Promise<Tarea>;
  eliminarTarea(id: number): Promise<void>;
  setColaboradores(id: number, usuarioIds: number[]): Promise<TareaDetalle>;
  comentarTarea(id: number, comentario: NuevoComentario): Promise<TareaDetalle>;
  actividadTarea(id: number): Promise<EventoActividad[]>;  // reusa el tipo de bitácora unificada
  setDependencias(id: number, dependeDeIds: number[]): Promise<TareaDetalle>; // Fase 4

  // puente con acuerdos
  vincularAcuerdoAProyecto(acuerdoId: number, proyectoId: number | null): Promise<Acuerdo>;
  crearTareaDesdeAcuerdo(acuerdoId: number, proyectoId: number, alta?: Partial<AltaTarea>): Promise<Tarea>;

  // dashboard (Fase 6)
  getResumenProyecto(id: number): Promise<ResumenProyecto>; // % avance, carga por persona, vencidas, burn-up
}
```

**Mapa REST** (mismo estilo de rutas actual, con `OPTIONS` por CORS):

```
GET/POST        /api/v1/proyectos
GET/PATCH/DELETE /api/v1/proyectos/{id}
PATCH           /api/v1/proyectos/{id}/estado
PUT             /api/v1/proyectos/{id}/miembros
POST            /api/v1/proyectos/{id}/secciones
PATCH/DELETE    /api/v1/secciones/{id}
GET/POST        /api/v1/proyectos/{id}/tareas
GET             /api/v1/tareas/mias
GET/PATCH/DELETE /api/v1/tareas/{id}
PATCH           /api/v1/tareas/{id}/mover
PATCH           /api/v1/tareas/{id}/terminar · /reabrir
PUT             /api/v1/tareas/{id}/colaboradores · /dependencias
POST            /api/v1/tareas/{id}/comentarios
GET             /api/v1/tareas/{id}/actividad
PATCH           /api/v1/acuerdos/{id}/proyecto
POST            /api/v1/acuerdos/{id}/derivar-tarea
GET             /api/v1/proyectos/{id}/resumen
```

**Reglas transversales que heredan del sistema actual:**

- Transacciones en toda operación multi-tabla (crear tarea + colaboradores; terminar +
  comentario `cambio_estado` + auditoría) — regla 8.
- Cero N+1: `listTareas` carga asignado/colaboradores/conteo de comentarios con
  `whereIn` agrupado — regla 9.
- HTML de descripción/comentarios pasa por el mismo pipeline de saneo
  (Tiptap → DOMPurify en front, saneo en Service en back) — regla 7.
- Fechas en `America/Ciudad_Juarez` — regla 6.

---

## 7. UI/UX: vistas y componentes

### 7.1 Navegación

Se añade una sección **Proyectos** al shell actual (sidebar), sin tocar las vistas de
acuerdos:

| Ruta | Vista | Contenido |
|---|---|---|
| `/proyectos` | Lista de proyectos | Cards con % avance, líder, miembros (avatares apilados — `Avatar.tsx` ya existe), estado, próximo hito. Filtros: estado, área, líder. |
| `/proyectos/:id` | Tablero del proyecto | Tabs: **Kanban** (secciones + drag & drop) · **Tabla** · **Gantt** (fechas inicio/compromiso + dependencias) · **Acuerdos vinculados** (solo lectura) · **Resumen** (Fase 6) |
| `/mis-tareas` | Mis tareas cross-proyecto | Paralelo exacto de "Mis acuerdos" (ADR-013): agrupado por proyecto, orden por fecha compromiso |
| Drawer de tarea | Detalle lateral | Mismo patrón del `Drawer.tsx` de acuerdos: descripción, asignado, colaboradores (`CorresponsablesPicker` reutilizado), fechas (`DatePicker`), comentarios (`EditorHtml`), bitácora, dependencias |

### 7.2 Componentes: extraer, no duplicar

Del refactor habilitador (Fase 1) salen componentes genéricos parametrizados por un tipo
`ItemTrabajo` (unión discriminada `Acuerdo | Tarea`):

- `<TablaTrabajo items columnas acciones>` — hoy embebida en `Panel.tsx`.
- `<Kanban columnas onMover>` — columnas = estados (acuerdos) o secciones (tareas).
- `<Gantt items rango>` — el cronograma existente, con soporte de dependencias en Fase 4.
- `<BitacoraActividad eventos>` — ya casi genérico (`EventoActividad`).

Identidad visual: tokens PJ existentes (regla y doc 08); el `color` del proyecto solo
como acento (borde de card, chip), nunca texto lima sobre blanco.

### 7.3 Drag & drop

`orden DECIMAL(16,6)` con inserción fraccional (promedio entre vecinos) y renormalización
en Service cuando el gap se agota. Librería sugerida: `@dnd-kit/core` (accesible, sin
dependencias de estilos, compatible React 19). El `PATCH /tareas/{id}/mover` es
idempotente y devuelve la tarea con su orden final (optimistic update con TanStack Query
+ rollback en error).

---

## 8. Generalización del motor de notificaciones

El cron `recordatorios:procesar` (08:00 América/Ciudad_Juarez) se conserva como único
punto de entrada. Cambios:

1. `RecordatorioService` procesa **fuentes**: hoy `AcuerdosVencimientos`; se añade
   `TareasVencimientos` (previos, día D, seguimiento de vencida — misma configuración
   global, con override por proyecto como mejora posterior).
2. `recordatorios_enviados` polimórfica (§4.2) mantiene la **idempotencia** con el
   UNIQUE por (entidad, entidad_id, usuario, tipo, fecha programada).
3. Eventos transaccionales nuevos (asignación de tarea, comentario con @mención,
   dependencia desbloqueada) se **encolan** en una tabla `notificaciones` y se
   despachan por los canales activos: correo (Gmail API, ya existe), push (FCM, §9.2)
   e in-app (campana, backlog #9). Un solo evento → N canales, sin duplicar lógica.
4. `google_sync` polimórfica: tarea con `fecha_compromiso` genera evento en el
   calendario compartido "Acuerdos" **solo si el proyecto lo activa** (flag
   `sync_calendario` por proyecto) para no inundar el calendario institucional.

---

## 9. Análisis riguroso de servicios Firebase

Contexto real del proyecto: SPA React + PWA (`vite-plugin-pwa`, service worker activo),
Firebase JS SDK v12 ya instalado, backend CI4 con `kreait/firebase-php` v8 (verificación
de ID tokens), MySQL como fuente de verdad, Redis para cache/rate-limit, y las reglas no
negociables 5–10. Cada servicio se evalúa contra ese contexto, no en abstracto.

**Principio rector:** Firebase complementa, no sustituye. MySQL sigue siendo la única
fuente de verdad del dominio (romper eso rompería las reglas 1, 5, 8 y toda la capa de
Policies/auditoría). Los servicios se adoptan solo donde aportan una capacidad que el
stack actual no tiene.

### 9.1 Resumen de veredictos

| Servicio | Veredicto | Fase | Por qué (una línea) |
|---|---|---|---|
| Authentication | ✅ En uso | — | Ya es la base (ADR-002/006) |
| **Cloud Messaging (FCM)** | ✅ **Adoptar** | 5 | Push web sobre la PWA existente; el hueco #9 del backlog |
| **App Check** | ✅ Adoptar | 1 (hardening) | Corta abuso de API con esfuerzo S |
| Storage | 🟡 Evaluar | 6+ | Adjuntos de evidencia; compite con Google Drive (backlog #7) |
| Remote Config | 🟡 Evaluar | 5+ | Feature flags para despliegues graduales del módulo |
| Firestore / Realtime DB | 🔶 Solo como canal efímero | post-MVP | Presencia/"alguien edita"; JAMÁS datos de dominio |
| Cloud Functions | ❌ Descartar | — | CI4 + cron ya cubren el rol; añadiría un segundo backend |
| Hosting | ❌ Descartar | — | Ya hay servidor propio en producción; migrar no aporta |
| Analytics | ❌ Descartar | — | Decisión explícita del proyecto de no inicializarlo (privacidad); si se necesita medición, auditoría propia |
| Crashlytics / Performance | ❌ Descartar | — | Orientados a apps móviles nativas; para web usar Sentry o similar |
| Extensions / Data Connect | ❌ Descartar | — | Redundantes con el stack (Gmail API propia, MySQL propia) |

### 9.2 Cloud Messaging (FCM) — la adopción de mayor valor

**Qué habilita:** notificaciones push nativas en escritorio y Android (e iOS ≥16.4 con
PWA instalada) sin app móvil. Es la pieza que convierte "recordatorios por correo una
vez al día" en "me asignaron una tarea y me enteré en el momento".

**Casos de uso concretos:**

| Evento | Hoy | Con FCM |
|---|---|---|
| Te asignan tarea/acuerdo | Correo en el cron de las 08:00 (tipo `asignacion`) | Push inmediato + correo como respaldo |
| Comentario o @mención | Nada | Push inmediato |
| Solicitud de conclusión (ADR-014) | Aparece en checklist al entrar | Push a Dirección/coordinación del área |
| Tarea desbloqueada (dependencia terminada) | Nada | Push al asignado |
| Vencimiento hoy | Correo 08:00 | Correo + push |

**Arquitectura (encaja con lo existente):**

```
[Evento de dominio en Service CI4] → INSERT tabla `notificaciones` (transacción del evento)
[Despachador] → kreait/firebase-php Messaging::sendMulticast(tokens del usuario)
[SPA] → firebase/messaging (getToken con VAPID) → POST /api/v1/push/suscripcion
[Service worker PWA] → onBackgroundMessage → Notification API
```

- Tabla nueva `push_suscripciones (usuario_id, fcm_token, user_agent, created_at,
  last_ok_at)` — un usuario, N dispositivos. Tokens inválidos
  (`UNREGISTERED`) se podan en el despachador.
- `kreait/firebase-php` **ya está en `composer.json`** — el mismo paquete que verifica
  tokens envía mensajes; solo requiere credencial de service account (que ya existe para
  Gmail/Calendar; se añade el scope de FCM). Cero dependencias nuevas en backend.
- El front añade `firebase/messaging` (mismo SDK ya instalado) + un
  `firebase-messaging-sw.js` integrado al service worker de `vite-plugin-pwa`
  (estrategia `injectManifest`).
- Preferencias por usuario (qué eventos → qué canal) en la pantalla Configuración,
  extendiendo `ConfigRecordatorios`.

**Esfuerzo:** M (3–5 días incluyendo preferencias y poda de tokens).
**Riesgos:** permiso de notificaciones denegado por el usuario (el correo queda como
canal garantizado); iOS requiere PWA instalada (documentar en la guía de usuario).

### 9.3 App Check — hardening barato

**Qué es:** attestation que acompaña cada request con un token (reCAPTCHA v3/Enterprise
en web) probando que proviene de la app legítima, no de un script.

**Encaje:** la API ya tiene Throttle + Firebase Auth, pero un atacante con un ID token
válido (usuario `pendiente` autorregistrado, p. ej.) puede golpear la API desde scripts.
App Check añade una capa: el `FirebaseAuthFilter` (o un filter hermano `appcheck`)
verifica también el header `X-Firebase-AppCheck` — `kreait/firebase-php` incluye
`AppCheck::verifyToken()`. Alineado con el doc 04 (OWASP) y el checklist de despliegue.

**Esfuerzo:** S (1–2 días). **Riesgo:** falsos positivos de reCAPTCHA en redes
corporativas — desplegar primero en modo "monitor" (registrar sin bloquear).

### 9.4 Storage — evidencias de tareas y acuerdos

**Caso de uso:** el backlog #7 pide "adjuntar archivos como evidencia" vía Google Drive.
Firebase Storage (GCS) es la alternativa: subida directa desde el navegador con reglas
por usuario autenticado, sin pasar el archivo por el servidor PHP.

**Comparativa honesta:**

| Criterio | Google Drive (backlog #7) | Firebase Storage |
|---|---|---|
| Dónde viven los archivos | Drive institucional, visible/administrable por la organización | Bucket GCS del proyecto Firebase |
| Permisos | Los de Drive (familiar para la organización) | Security Rules (custom claims por usuario) |
| Costo | Incluido en Workspace | Facturación GCS (plan Blaze) por GB |
| Integración con lo existente | Ya hay service account y OAuth Google | Ya hay SDK Firebase en front |
| Esfuerzo | L (OAuth por usuario o carpetas por service account) | M (reglas + metadatos en MySQL) |

**Recomendación:** decisión de Dirección (pregunta B-2, §15). Si la organización quiere
los archivos "en su Drive", mantener backlog #7; si prioriza velocidad de implementación
y UX de subida, Storage gana. En ambos casos los **metadatos** (tabla
`adjuntos (entidad, entidad_id, nombre, url/ref, subido_por_id, ...)`) viven en MySQL.

### 9.5 Remote Config — feature flags para el despliegue gradual

**Caso de uso:** el módulo de proyectos es el cambio más grande desde el MVP. Remote
Config permite activar `modulo_proyectos_habilitado` primero para Dirección, luego
coordinaciones, luego todos — sin redeploy y con rollback instantáneo si algo falla en
producción (servidor único, sin entorno de staging según doc 08 §4).

**Alternativa sin Firebase:** la tabla `configuracion` existente + un endpoint de flags.
Menos elegante (requiere deploy para cambiar comportamiento del bundle) pero cero
dependencias nuevas. **Recomendación:** empezar con `configuracion` (esfuerzo S) y
adoptar Remote Config solo si se necesita segmentación por usuario/porcentaje.

### 9.6 Firestore / Realtime Database — la trampa a evitar (con una excepción)

**Por qué NO como base de datos:** mover proyectos/tareas a Firestore rompería las
reglas 1 (espejo DDL), 5 (estados solo del sistema), 8 (transacciones ACID
multi-tabla) y 9 (joins sin N+1), además de partir la fuente de verdad en dos y dejar
las Policies/auditoría del backend fuera del camino de escritura. Las Security Rules no
pueden expresar "coordinador concluye solo acuerdos de su área con auditoría del 403".

**La excepción legítima — canal efímero de tiempo real:** para colaboración viva en el
tablero (varios usuarios moviendo tarjetas), un documento Firestore por proyecto que
solo transporte señales `{tareaId, versión, ts}` — escrito por el backend tras cada
mutación — permite a los clientes suscritos invalidar la query de TanStack y refetchear
de la API real. Datos de dominio: cero en Firestore; solo "algo cambió".

**Alternativa sin Firebase:** polling con `refetchInterval` de TanStack Query (15–30 s)
— esfuerzo casi nulo y suficiente para un equipo del tamaño de Plan Juárez.
**Recomendación:** polling en v1; canal Firestore solo si la concurrencia real lo pide.

### 9.7 Cloud Functions, Hosting, Analytics, Crashlytics — descartes razonados

- **Cloud Functions:** su rol (lógica de servidor sin servidor) ya lo cumple CI4 + cron.
  Adoptarlo crearía un segundo backend en otro lenguaje, con secretos duplicados y sin
  acceso natural a MySQL. Solo tendría sentido si se abandonara el servidor propio.
- **Hosting:** la app ya se sirve desde el servidor propio junto a la API (mismo origen,
  CORS simple). Migrar el estático a Hosting separa orígenes sin beneficio.
- **Analytics:** `firebase.ts` documenta explícitamente que NO se inicializa Analytics.
  Es una decisión correcta para una herramienta interna con datos de gestión
  institucional. Si Dirección quiere métricas de adopción, es mejor un reporte sobre
  `auditoria` (quién usa qué) que telemetría de Google.
- **Crashlytics/Performance Monitoring:** orientados a móvil nativo. Para errores del
  SPA, un `ErrorBoundary` + endpoint propio de reporte (o Sentry) encaja mejor.

---

## 10. Catálogo de funcionalidades adicionales

Análisis exhaustivo de lo que el módulo habilita, priorizado. Convenciones del doc 08:
Impacto Alto/Medio/Bajo · Esfuerzo S/M/L · la columna Fase referencia el §11.

### 10.1 Incluidas en el núcleo de esta propuesta

| # | Funcionalidad | Impacto | Esfuerzo | Fase |
|---|---|---|---|---|
| N1 | CRUD de proyectos + membresía con roles | Alto | M | 2 |
| N2 | Tareas: asignado, colaboradores, estados, prioridad, fechas | Alto | M–L | 3 |
| N3 | Kanban con secciones y drag & drop | Alto | M | 3 |
| N4 | Mis tareas cross-proyecto (espejo de "Mis acuerdos") | Alto | S | 3 |
| N5 | Comentarios con editor HTML + bitácora por tarea | Medio | S (reuso) | 3 |
| N6 | Subtareas (1 nivel) | Medio | S–M | 4 |
| N7 | Dependencias entre tareas + gantt con flechas | Medio | M | 4 |
| N8 | Vinculación acuerdo↔proyecto + derivar tarea desde acuerdo | Alto | S–M | 4 |
| N9 | Recordatorios de tareas por correo (motor generalizado) | Alto | M | 5 |
| N10 | Push FCM + campana in-app (absorbe backlog #9) | Alto | M | 5 |
| N11 | Dashboard por proyecto: % avance, vencidas, carga por persona (absorbe backlog #6) | Alto | M | 6 |
| N12 | Sync opcional de tareas al calendario compartido | Medio | S (reuso) | 6 |

### 10.2 Extensiones de alto valor (post-núcleo, orden sugerido)

| # | Funcionalidad | Descripción y justificación | Impacto | Esfuerzo |
|---|---|---|---|---|
| E1 | **@Menciones** | Mencionar a un usuario en comentario (extensión Tiptap `mention` — Tiptap ya está) dispara notificación multicanal. Multiplicador del valor de FCM. | Alto | S–M |
| E2 | **Plantillas de proyecto** | Proyecto marcado como plantilla se clona (secciones + tareas con offsets de fecha). Ideal para procesos repetidos (convocatorias, eventos, informes anuales). | Alto | M |
| E3 | **Vista de carga de trabajo (workload)** | Matriz persona × semana con conteo de tareas+acuerdos; detecta sobrecarga antes de asignar. Usa datos ya existentes. | Alto | M |
| E4 | **Búsqueda global** | Un buscador (⌘K) sobre acuerdos, tareas, proyectos y personas. FULLTEXT INDEX de MySQL (`titulo`, `descripcion`) es suficiente a esta escala; no se requiere motor externo. | Alto | M |
| E5 | **Metas/OKRs (H-10 completo)** | Tabla `metas` (indicador, valor objetivo, valor actual, período) vinculable a proyectos y acuerdos. Cierra el ítem #11 del backlog con el modelo que pedía definir. | Alto | M–L |
| E6 | **Informes programados** | El cron genera y envía por Gmail un resumen semanal por proyecto (reusa `ResumenCorrida` + exportación XLSX existente). | Medio | S–M |
| E7 | **Etiquetas (tags) transversales** | `etiquetas` + N:M con tareas/acuerdos; filtros por etiqueta en todas las vistas. | Medio | S–M |
| E8 | **Adjuntos de evidencia** | Según decisión B-2: Google Drive (backlog #7) o Firebase Storage (§9.4). Metadatos en MySQL en ambos casos. | Medio | M–L |
| E9 | **Estimación y registro de esfuerzo** | Campo `horas_estimadas`/`horas_registradas` por tarea; alimenta workload (E3) y dashboard. Solo si Dirección lo pide — añade fricción de captura. | Medio | S |
| E10 | **Automatizaciones simples** | Reglas declarativas por proyecto ("al terminar todas las subtareas → terminar la padre", "al vencer → notificar al líder"). Motor de reglas acotado (tabla `automatizaciones` + evaluación en Service), NO un builder visual tipo Monday. | Medio | L |
| E11 | **Recurrencia de tareas** | Tareas que se regeneran (semanal/mensual) al terminarse. El cron ya existe como punto de anclaje. | Medio | M |
| E12 | **Archivado + papelera** | Soft-delete con restauración (absorbe backlog #12) extendido a proyectos y tareas. | Medio | M |

### 10.3 Explícitamente fuera de alcance (y por qué)

| Funcionalidad | Razón del descarte |
|---|---|
| **Campos personalizados por proyecto** | Es lo que hace "Monday" a Monday y lo que multiplica el esfuerzo ×3–4: esquema EAV o JSON, validación dinámica, render dinámico, filtros/exportación dinámicos. Con esquema fijo bien elegido (prioridad, fechas, etiquetas E7) se cubre el 90 % de la necesidad real de una organización de este tamaño. Reevaluar solo con casos de uso concretos que el esquema fijo no cubra. |
| Builder visual de automatizaciones | Ídem: E10 acotado cubre los casos reales. |
| Chat en tiempo real | Los comentarios por tarea + @menciones + push cubren la necesidad; un chat compite con WhatsApp/correo institucional y exige Firestore como almacén (contra §9.6). |
| App móvil nativa | La PWA instalable + FCM cubre móvil. Reevaluar solo si iOS resulta insuficiente en la práctica. |
| Facturación/CRM/formularios públicos | Fuera del dominio institucional del sistema. |

---

## 11. Plan de implementación por fases

Cada fase respeta la regla de gate: DoD verificada antes de iniciar la siguiente. Todas
las migraciones son reversibles (`down()` real) y aditivas.

| Fase | Contenido | Entregables clave | DoD | Estimación |
|---|---|---|---|---|
| **1. Refactor habilitador + hardening** | Extraer `AcuerdoService` de `AcuerdosController`; extraer `<TablaTrabajo>/<Kanban>/<Gantt>` de `Panel.tsx`; App Check en modo monitor | Cero cambio funcional; suite de tests actual verde sin modificar aserciones | Tests verdes; diff de comportamiento nulo; App Check registrando | 1 sem |
| **2. Proyectos + membresía** | Migraciones `proyectos`/`proyecto_miembros`/`proyecto_secciones`; `ProyectoService` + `PermisosProyecto` (Policy con pruebas negativas); `/proyectos` y detalle básico; espejo db.json + verificador extendido | CRUD completo con matriz §5.2 aplicada; `verificar_espejo.mjs` cubre el DDL nuevo | Pruebas de Policy (positivas y negativas); doc 05 actualizado en la misma sesión | 1–1.5 sem |
| **3. Tareas núcleo** | Migraciones `tareas`/`tarea_colaboradores`/`tarea_comentarios`; `TareaService`; kanban con drag & drop; drawer de tarea; Mis tareas | Delegación funcional de punta a punta | Cero N+1 auditado en listados; transacciones en multi-tabla; tests de transiciones de estado | 2 sem |
| **4. Jerarquía y puente** | Subtareas (1 nivel); `tarea_dependencias` + validación de ciclos; gantt con dependencias; `acuerdos.proyecto_id` + derivar tarea desde acuerdo | El acuerdo de dirección aterriza en proyectos | Prueba de no-regresión del ciclo de vida del acuerdo; prueba de ciclo detectado | 1–1.5 sem |
| **5. Notificaciones multicanal** | Motor generalizado (§8); tabla `notificaciones`; FCM (§9.2) + campana in-app; preferencias por usuario | Push de asignación/mención/solicitud; correo de vencimientos de tareas | Idempotencia del cron probada; poda de tokens; opt-out por usuario | 1–1.5 sem |
| **6. Dashboard y cierre** | `getResumenProyecto`; dashboard por proyecto y global; sync opcional a Calendar; exportación XLSX de tareas (reuso) | Absorbe backlog #6; DoD global del módulo | Checklist de despliegue (doc 04) extendido y verificado | 1 sem |

**Total: 7.5–9.5 semanas** de trabajo enfocado (6–8 si la Fase 1 revela menos
acoplamiento del estimado). Las extensiones E1–E12 se priorizan después con Dirección.

**Gobernanza:** antes de la Fase 2 se formaliza el **ADR-015** (anexo A) y este
documento se promueve a `docs/10-proyectos/` como especificación, actualizando la tabla
de fases del CLAUDE.md y el README (regla de gate).

---

## 12. Riesgos y mitigaciones

| # | Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|---|
| R1 | **Deriva de alcance hacia "paridad con Monday"** — competir con un producto horizontal diluye la ventaja vertical (gobernanza institucional de acuerdos) | Alta | Alto | §10.3 firmado por Dirección; toda adición pasa por backlog priorizado, no por "Monday lo tiene" |
| R2 | Refactor de Fase 1 rompe comportamiento en producción | Media | Alto | Suite de tests existente (36 archivos) como red; cero cambios de aserciones; despliegue de Fase 1 aislado y observado |
| R3 | Doble sistema percibido ("¿esto va en acuerdo o en tarea?") — confusión de usuarios | Media | Medio | Regla simple comunicada: *nace en reunión de dirección → acuerdo; nace en la operación → tarea*; el puente (derivar tarea) cubre el caso mixto |
| R4 | Migraciones polimórficas de `recordatorios_enviados`/`google_sync` corrompen idempotencia del cron | Baja | Alto | Migración en dos pasos (columna nueva + backfill; retiro de FK una versión después); prueba de idempotencia antes/después |
| R5 | Adopción baja del módulo (se sigue trabajando por correo) | Media | Alto | Despliegue gradual con flag (§9.5); pilotar con un proyecto real de una coordinación; FCM para cerrar el loop de "me asignaron algo" |
| R6 | Servidor único sin staging (doc 08 §4) — todo error llega a producción | Media | Alto | Flags de módulo; migraciones reversibles; ventana de despliegue fuera de horario; backup previo (checklist doc 04) |
| R7 | Carga del calendario compartido con eventos de tareas | Media | Bajo | Sync a Calendar **opt-in por proyecto** (§8.4), apagado por defecto |
| R8 | Costo Firebase (plan Blaze si se adopta Storage) | Baja | Bajo | FCM y App Check son gratuitos a esta escala; Storage requiere decisión explícita con estimación de GB (B-2) |

---

## 13. Cumplimiento de las reglas no negociables

Verificación regla por regla del CLAUDE.md:

| Regla | Cómo la cumple esta propuesta |
|---|---|
| 1. `db.json` espejo del DDL | DDL nuevo en `docs/03-datos/panel_proyectos_ddl.sql`; espejo y `verificar_espejo.mjs` extendidos (DoD Fase 2) |
| 2. Pantallas nunca leen `db.json` | Todo pasa por `ApiClient` extendido (§6); las vistas nuevas consumen `api` |
| 3. `api.ts` ≡ doc 05 | Cada endpoint nuevo actualiza ambos en la misma sesión; el contrato del §6 es el borrador |
| 4. Conclusión de acuerdos (ADR-012) | **Intacta** — el módulo no toca el ciclo de vida del acuerdo; `terminarTarea` es un flujo distinto con su propia Policy y pruebas negativas |
| 5. `vencido` solo del sistema | `tareas` no persiste "vencida": se deriva de `fecha_compromiso` (§4.1); el cliente jamás la envía |
| 6. TZ `America/Ciudad_Juarez` | Fechas de proyectos/tareas con el mismo tratamiento; cron único |
| 7. Prepared statements + escape | Query Builder en Models nuevos; descripciones/comentarios por el pipeline de saneo existente; sin `dangerouslySetInnerHTML` |
| 8. Transacciones multi-tabla | Crear tarea+colaboradores, terminar+comentario+auditoría, mover con renormalización: `transException(true)->transStart()` |
| 9. Cero N+1 | Listados con `whereIn` agrupado; auditado como DoD de Fase 3 |
| 10. Secretos en `.env` | Credencial FCM = service account existente; VAPID key en `VITE_*`; nada en repo |
| 11. Conversión 1:1 del demo | Las vistas nuevas usan tokens/componentes PJ existentes; ninguna vista aprobada se altera |
| Gate de fases | Este documento ES el paso previo: sin aprobación de Dirección no se genera código de Fase 3 |

---

## 14. Anexo A — Borrador ADR-015

> **ADR-015 — Módulo de proyectos y tareas como dominio paralelo**
>
> **Estado:** Propuesto (2026-07-30) · **Decisores:** Dirección + equipo de desarrollo
>
> **Contexto.** El sistema gestiona acuerdos de reuniones de dirección. La organización
> necesita además dar de alta proyectos, integrar equipos y delegar tareas operativas
> fuera del ciclo de reuniones. El dominio de acuerdos está en producción, validado por
> Dirección y protegido por ADR-007/011/012/014.
>
> **Decisión.** Se crea un dominio paralelo (`proyectos`, `proyecto_miembros`,
> `proyecto_secciones`, `tareas`, `tarea_colaboradores`, `tarea_comentarios`,
> `tarea_dependencias`) que comparte cimientos con acuerdos (usuarios, áreas, auditoría
> polimórfica, motor de notificaciones generalizado) sin fusionar objetos. El acuerdo
> gana `proyecto_id INT NULL` (vínculo aditivo). La lectura de proyectos/tareas es
> abierta para roles operativos (coherente con ADR-007); la escritura se gobierna por
> membresía con roles por proyecto (`lider`/`editor`/`miembro`/`observador`), con
> Dirección como superusuario. `recordatorios_enviados` y `google_sync` migran a
> referencia polimórfica (`entidad` + `entidad_id`).
>
> **Alternativas descartadas.** (a) Unificar acuerdo y tarea en una sola tabla:
> invalida el DDL congelado, los ADR aprobados y ~1,750 líneas de controlador en
> producción; el acuerdo tiene semántica institucional (validación de Dirección) que la
> tarea no comparte. (b) Proyectos como capa de solo-reporte: no habilita delegación,
> que es el objetivo.
>
> **Consecuencias.** (+) Cero riesgo sobre el dominio aprobado; reversible; reuso máximo
> de patrones probados. (−) Dos objetos de trabajo similares con dos flujos de cierre —
> mitigado con la regla de origen (*reunión → acuerdo; operación → tarea*) y el puente
> de derivación. (−) Migraciones polimórficas en dos tablas de soporte, en dos pasos.

---

## 15. Anexo B — Preguntas abiertas para Dirección

| # | Pregunta | Opciones | Recomendación |
|---|---|---|---|
| B-1 | ¿Quién puede crear proyectos? | (a) Solo Dirección · (b) Dirección + coordinación · (c) Cualquier rol operativo | **(b)** — espejo de cómo hoy la coordinación gobierna su área |
| B-2 | ¿Dónde viven los archivos de evidencia? | (a) Google Drive institucional (backlog #7) · (b) Firebase Storage (§9.4) · (c) Posponer | **(c)** para el núcleo; decidir con estimación de volumen antes de E8 |
| B-3 | ¿Las tareas terminadas requieren validación (como los acuerdos)? | (a) No: el asignado/líder termina y ya · (b) Flujo `en_revision` → líder valida | **(a) con (b) opcional por proyecto** — el estado `en_revision` ya existe en el ENUM para habilitarlo sin migración |
| B-4 | ¿El calendario compartido recibe eventos de tareas? | (a) Nunca · (b) Opt-in por proyecto · (c) Siempre | **(b)** (§8.4) |
| B-5 | ¿Se adopta push (FCM) desde el núcleo o después? | (a) Fase 5 del núcleo · (b) Post-núcleo | **(a)** — es el multiplicador de adopción del módulo (R5) |
| B-6 | ¿Piloto con qué proyecto real? | A definir con coordinaciones | Un proyecto activo de una coordinación con líder entusiasta |

---

*Fin del documento. Siguiente paso: revisión con Dirección; con las respuestas del
Anexo B se formaliza el ADR-015 y se calendariza la Fase 1.*

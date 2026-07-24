/**
 * Tipos espejo del modelo de datos (doc 03) + DTOs del contrato de API (doc 05).
 * Las interfaces *Row reflejan columnas del DDL 1:1 (snake_case intencional).
 */

// ── Enums del DDL ──
export type Rol = 'direccion' | 'coordinador' | 'responsable' | 'pendiente';
export type EstadoAcuerdo = 'en_proceso' | 'vencido' | 'concluido';
export type TipoAvance = 'avance' | 'reprogramacion' | 'validacion' | 'reapertura';
export type TipoRecordatorio = 'previo' | 'dia' | 'vencido' | 'resumen' | 'asignacion' | 'solicitud_avance';
export type EstadoEnvio = 'enviado' | 'fallido';
export type EstadoSync = 'pendiente' | 'sincronizado' | 'error';

// ── Filas espejo del DDL (db.json) ──
export interface AreaRow {
  id: number;
  nombre: string;
  activa: boolean;
  created_at: string;
  updated_at: string | null;
}

export interface UsuarioRow {
  id: number;
  firebase_uid: string | null;
  nombre: string;
  email: string;
  rol: Rol;
  area_id: number | null;
  activo: boolean;
  created_at: string;
  updated_at: string | null;
}

export interface ReunionRow {
  id: number;
  nombre: string;
  fecha: string; // YYYY-MM-DD
  created_at: string;
}

export interface AcuerdoRow {
  id: number;
  reunion_id: number;
  area_id: number;
  tema: string | null;
  accion: string;
  responsable_id: number;
  capturado_por_id: number;
  fecha_compromiso: string; // YYYY-MM-DD
  estado: EstadoAcuerdo;
  enlace: string | null;
  enlaces: string[] | null;
  observaciones: string | null;
  recordatorio_dias: number[] | null;
  concluido_por_id: number | null;
  concluido_at: string | null;
  created_at: string;
  updated_at: string | null;
}

export interface AcuerdoCorresponsableRow {
  acuerdo_id: number;
  usuario_id: number;
  created_at: string;
}

export interface AvanceRow {
  id: number;
  acuerdo_id: number;
  usuario_id: number;
  tipo: TipoAvance;
  descripcion: string;
  nueva_fecha: string | null;
  created_at: string;
}

export interface ConfiguracionRow {
  clave: string;
  valor: ConfigRecordatorios | Record<string, unknown>;
  updated_at: string | null;
}

export interface RecordatorioEnviadoRow {
  id: number;
  acuerdo_id: number | null;
  usuario_id: number;
  tipo: TipoRecordatorio;
  programado_para: string; // YYYY-MM-DD
  enviado_at: string | null;
  estado: EstadoEnvio;
  gmail_message_id: string | null;
  error: string | null;
}

export interface GoogleSyncRow {
  id: number;
  acuerdo_id: number;
  calendar_event_id: string | null;
  estado: EstadoSync;
  intentos: number;
  synced_at: string | null;
  error: string | null;
}

export interface UsuarioGoogleTokenRow {
  id: number;
  usuario_id: number;
  refresh_token_cifrado: string;
  scopes: string;
  connected_at: string;
  revoked_at: string | null;
}

export interface AuditoriaRow {
  id: number;
  usuario_id: number | null;
  accion: string;
  entidad: string;
  entidad_id: number | null;
  detalle: Record<string, unknown> | null;
  ip: string | null;
  created_at: string;
}

/** Forma completa de db.json: una clave por tabla del DDL. */
export interface DbJson {
  areas: AreaRow[];
  usuarios: UsuarioRow[];
  reuniones: ReunionRow[];
  acuerdos: AcuerdoRow[];
  acuerdo_corresponsables: AcuerdoCorresponsableRow[];
  avances: AvanceRow[];
  configuracion: ConfiguracionRow[];
  recordatorios_enviados: RecordatorioEnviadoRow[];
  google_sync: GoogleSyncRow[];
  usuario_google_tokens: UsuarioGoogleTokenRow[];
  auditoria: AuditoriaRow[];
}

// ── DTOs del contrato (doc 05) ──
export interface ConfigRecordatorios {
  dias_antes: number[];
  dia_compromiso: boolean;
  vencido_cada_dias: number;
  vencido_max_repeticiones: number;
  resumen_frecuencia: 'semanal' | 'quincenal' | 'mensual';
  /** Habilita los correos automáticos que piden avances a los responsables. */
  solicitud_avances_activa: boolean;
  /** Habilita que Google Calendar envíe la invitación por correo al crear/actualizar el evento. */
  invitaciones_calendario_activas: boolean;
}

export interface UsuarioRef {
  id: number;
  nombre: string;
  email: string;
  /** Color hex (#RRGGBB) de identidad del avatar; null = color por defecto. */
  avatar_color?: string | null;
}

export interface Usuario extends UsuarioRef {
  rol: Rol;
  area_id: number | null;
  activo: boolean;
}

export interface Area {
  id: number;
  nombre: string;
  activa: boolean;
}

export interface Sesion {
  usuario: Usuario;
  config_recordatorios: ConfigRecordatorios;
}

export interface Reunion {
  id: number;
  nombre: string;
  fecha: string;
}

export interface Acuerdo {
  id: number;
  reunion: Reunion;
  area: Area;
  tema: string | null;
  accion: string;
  responsable: UsuarioRef;
  corresponsables: UsuarioRef[];
  capturado_por: UsuarioRef;
  fecha_compromiso: string;
  estado: EstadoAcuerdo;
  /** Enlaces de productos/evidencias (0..N). El backend siempre devuelve lista. */
  enlaces: string[];
  observaciones: string | null;
  recordatorio_dias: number[] | null;
  concluido_por: UsuarioRef | null;
  concluido_at: string | null;
  created_at: string;
  updated_at: string | null;
}

export interface Avance {
  id: number;
  usuario: UsuarioRef;
  tipo: TipoAvance;
  descripcion: string;
  nueva_fecha: string | null;
  created_at: string;
}

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

export interface RecordatorioProgramado {
  tipo: TipoRecordatorio;
  programado_para: string;
  estado: 'programado' | 'enviado' | 'fallido';
}

export interface AcuerdoDetalle extends Acuerdo {
  avances: Avance[];
  recordatorios: RecordatorioProgramado[];
}

export interface FiltrosAcuerdos {
  estado?: EstadoAcuerdo | 'todos_abiertos';
  responsable_id?: number;
  mios?: boolean; // ADR-013: solo acuerdos donde el actor es responsable o corresponsable (wire: mios=1)
  q?: string;
  desde?: string;
  hasta?: string;
  page?: number;
  per_page?: number;
}

export interface Paginado<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

export interface NuevoAcuerdo {
  tema: string | null;
  accion: string;
  responsable_id: number;
  corresponsables_ids: number[];
  area_id: number;
  fecha_compromiso: string;
  enlaces: string[];
  observaciones: string | null;
  recordatorio_dias: number[] | null;
}

export interface LoteCaptura {
  reunion: { nombre: string; fecha: string };
  acuerdos: NuevoAcuerdo[];
}

export interface EdicionAcuerdo {
  tema?: string | null;
  accion?: string;
  responsable_id?: number;
  area_id?: number;
  enlaces?: string[];
  observaciones?: string | null;
  recordatorio_dias?: number[] | null;
}

export interface NuevoAvance {
  descripcion: string;
  nueva_fecha?: string | null;
}

export interface RecordatorioVista {
  key: string;
  acuerdo_id: number | null;
  tipo: TipoRecordatorio;
  programado_para: string;
  destinatario: UsuarioRef;
  accion: string;
  tema: string | null;
  fecha_compromiso: string | null;
  enviado: boolean;
  estado_envio: EstadoEnvio | null;
  error: string | null;
}

export interface ChecklistItem {
  acuerdo: Acuerdo;
  total_avances: number;
  ultimo_avance: Avance | null;
}

export interface CalendarioDia {
  fecha: string;
  acuerdos: Acuerdo[];
}

export interface CalendarioMes {
  mes: string; // YYYY-MM
  dias: CalendarioDia[];
}

export interface ResumenPorResponsable {
  responsable: UsuarioRef;
  en_proceso: number;
  vencidos: number;
  por_vencer_7d: number;
}

export interface Resumen {
  ambito: 'general' | 'area';
  area: Area | null;
  en_proceso: number;
  vencidos: number;
  por_vencer_7d: number;
  concluidos: number;
  por_responsable: ResumenPorResponsable[];
}

export interface AltaUsuario {
  nombre: string;
  email: string;
  rol: Rol;
  area_id: number | null;
}

export interface EdicionUsuario {
  nombre?: string;
  email?: string;
  rol?: Rol;
  area_id?: number | null;
  activo?: boolean;
}

/** Self-service (ADR-005): el propio usuario solo puede editar su `nombre`. */
export interface ActualizacionPerfil {
  nombre?: string;
  /** Color hex (#RRGGBB) del avatar, o null para volver al color por defecto. */
  avatar_color?: string | null;
}

/** Autorregistro (ADR-006): `POST /registro` — uid/email salen del token verificado. */
export interface RegistroCuenta {
  nombre: string;
}

export interface AltaArea {
  nombre: string;
}

export interface EdicionArea {
  nombre?: string;
  activa?: boolean;
}

/** Error de API normalizado (doc 05 §1). */
export interface ApiError {
  error: string;
  mensaje: string;
  campos?: Record<string, string>;
}

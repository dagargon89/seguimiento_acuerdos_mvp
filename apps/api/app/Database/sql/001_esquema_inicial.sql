CREATE TABLE areas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(120) NOT NULL,
  activa      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_areas_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE usuarios (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  firebase_uid  VARCHAR(128) NULL,
  nombre        VARCHAR(120) NOT NULL,
  email         VARCHAR(160) NOT NULL,
  rol           ENUM('direccion','coordinador','responsable') NOT NULL,
  area_id       INT UNSIGNED NULL,
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email),
  UNIQUE KEY uq_usuarios_firebase_uid (firebase_uid),
  KEY idx_usuarios_rol_activo (rol, activo),
  CONSTRAINT fk_usuarios_area FOREIGN KEY (area_id) REFERENCES areas (id),
  CONSTRAINT chk_coordinador_area CHECK (rol <> 'coordinador' OR area_id IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE reuniones (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(160) NOT NULL,
  fecha       DATE         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reuniones_nombre_fecha (nombre, fecha),
  KEY idx_reuniones_fecha (fecha)
) ENGINE=InnoDB;

CREATE TABLE acuerdos (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reunion_id        INT UNSIGNED NOT NULL,
  area_id           INT UNSIGNED NOT NULL,
  tema              VARCHAR(160) NULL,
  accion            TEXT         NOT NULL,
  responsable_id    INT UNSIGNED NOT NULL,
  capturado_por_id  INT UNSIGNED NOT NULL,
  fecha_compromiso  DATE         NOT NULL,
  estado            ENUM('en_proceso','vencido','concluido') NOT NULL DEFAULT 'en_proceso',
  enlace            VARCHAR(2048) NULL,
  observaciones     TEXT         NULL,
  recordatorio_dias JSON         NULL,
  concluido_por_id  INT UNSIGNED NULL,
  concluido_at      DATETIME     NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_acuerdos_estado_fecha (estado, fecha_compromiso),
  KEY idx_acuerdos_responsable (responsable_id, estado),
  KEY idx_acuerdos_area_estado (area_id, estado),
  KEY idx_acuerdos_reunion (reunion_id),
  CONSTRAINT fk_acuerdos_reunion      FOREIGN KEY (reunion_id)       REFERENCES reuniones (id),
  CONSTRAINT fk_acuerdos_area         FOREIGN KEY (area_id)          REFERENCES areas (id),
  CONSTRAINT fk_acuerdos_responsable  FOREIGN KEY (responsable_id)   REFERENCES usuarios (id),
  CONSTRAINT fk_acuerdos_capturado    FOREIGN KEY (capturado_por_id) REFERENCES usuarios (id),
  CONSTRAINT fk_acuerdos_concluido    FOREIGN KEY (concluido_por_id) REFERENCES usuarios (id),
  CONSTRAINT chk_concluido_consistente CHECK (
    (estado = 'concluido' AND concluido_por_id IS NOT NULL AND concluido_at IS NOT NULL)
    OR (estado <> 'concluido' AND concluido_por_id IS NULL AND concluido_at IS NULL)
  )
) ENGINE=InnoDB;

CREATE TABLE acuerdo_corresponsables (
  acuerdo_id  INT UNSIGNED NOT NULL,
  usuario_id  INT UNSIGNED NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (acuerdo_id, usuario_id),
  KEY idx_corresp_usuario (usuario_id),
  CONSTRAINT fk_corresp_acuerdo FOREIGN KEY (acuerdo_id) REFERENCES acuerdos (id) ON DELETE CASCADE,
  CONSTRAINT fk_corresp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE avances (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  acuerdo_id  INT UNSIGNED NOT NULL,
  usuario_id  INT UNSIGNED NOT NULL,
  tipo        ENUM('avance','reprogramacion','validacion','reapertura') NOT NULL DEFAULT 'avance',
  descripcion TEXT         NOT NULL,
  nueva_fecha DATE         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_avances_acuerdo (acuerdo_id, created_at),
  CONSTRAINT fk_avances_acuerdo FOREIGN KEY (acuerdo_id) REFERENCES acuerdos (id) ON DELETE CASCADE,
  CONSTRAINT fk_avances_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT chk_reprogramacion_fecha CHECK (tipo <> 'reprogramacion' OR nueva_fecha IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE configuracion (
  clave       VARCHAR(60) NOT NULL,
  valor       JSON        NOT NULL,
  updated_at  DATETIME    NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB;

CREATE TABLE recordatorios_enviados (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  acuerdo_id       INT UNSIGNED NULL,
  usuario_id       INT UNSIGNED NOT NULL,
  tipo             ENUM('previo','dia','vencido','resumen') NOT NULL,
  programado_para  DATE         NOT NULL,
  enviado_at       DATETIME     NULL,
  estado           ENUM('enviado','fallido') NOT NULL,
  gmail_message_id VARCHAR(64)  NULL,
  error            TEXT         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recordatorio_unico (acuerdo_id, usuario_id, tipo, programado_para),
  KEY idx_rec_usuario (usuario_id, programado_para),
  CONSTRAINT fk_rec_acuerdo FOREIGN KEY (acuerdo_id) REFERENCES acuerdos (id) ON DELETE CASCADE,
  CONSTRAINT fk_rec_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE google_sync (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  acuerdo_id        INT UNSIGNED NOT NULL,
  calendar_event_id VARCHAR(128) NULL,
  estado            ENUM('pendiente','sincronizado','error') NOT NULL DEFAULT 'pendiente',
  intentos          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  synced_at         DATETIME NULL,
  error             TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_acuerdo (acuerdo_id),
  KEY idx_sync_estado (estado),
  CONSTRAINT fk_sync_acuerdo FOREIGN KEY (acuerdo_id) REFERENCES acuerdos (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE usuario_google_tokens (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id             INT UNSIGNED NOT NULL,
  refresh_token_cifrado  TEXT         NOT NULL,
  scopes                 VARCHAR(500) NOT NULL,
  connected_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at             DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_usuario (usuario_id),
  CONSTRAINT fk_token_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE auditoria (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id  INT UNSIGNED NULL,
  accion      VARCHAR(60)  NOT NULL,
  entidad     VARCHAR(40)  NOT NULL,
  entidad_id  INT UNSIGNED NULL,
  detalle     JSON         NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aud_entidad (entidad, entidad_id),
  KEY idx_aud_usuario_fecha (usuario_id, created_at),
  CONSTRAINT fk_aud_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

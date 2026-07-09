#!/usr/bin/env node
/**
 * Verificación ejecutable db.json ↔ DDL (Gobernanza v3 §5).
 * Confronta apps/web/src/lib/mock/db.json contra docs/03-datos/panel_acuerdos_ddl.sql:
 *   1. Mismas tablas (toda tabla del DDL existe en db.json y viceversa; _meta se ignora).
 *   2. Mismas columnas por fila (ni de más ni de menos).
 *   3. Enums solo con valores válidos del DDL.
 *   4. Integridad referencial: toda FK apunta a un id existente.
 *   5. CHECKs del DDL: coordinador con área; consistencia de conclusión; reprogramación con fecha.
 * Salida ≠ 0 si hay discrepancias (bloqueante para cerrar el Sprint D).
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
const ddl = readFileSync(join(raiz, 'docs', '03-datos', 'panel_acuerdos_ddl.sql'), 'utf8');
const db = JSON.parse(
  readFileSync(join(raiz, 'apps', 'web', 'src', 'lib', 'mock', 'db.json'), 'utf8'),
);

const errores = [];
const avisos = [];

// ── 1. Parsear el DDL: tablas → {columnas, enums} ──
const tablas = new Map();
const reTabla = /CREATE TABLE (\w+) \(([\s\S]*?)\n\) ENGINE=InnoDB;/g;
for (const m of ddl.matchAll(reTabla)) {
  const [, nombre, cuerpo] = m;
  const columnas = new Map(); // col → {enum?: string[]}
  for (const linea of cuerpo.split('\n')) {
    const l = linea.trim().replace(/,$/, '');
    if (!l || /^(PRIMARY KEY|UNIQUE KEY|KEY|CONSTRAINT|OR |AND |[()])/.test(l)) continue;
    const col = l.match(/^(\w+)\s+(INT|BIGINT|TINYINT|VARCHAR|TEXT|DATE|DATETIME|ENUM|JSON)/i);
    if (!col) continue;
    const colNombre = col[1];
    const enumM = l.match(/ENUM\(([^)]+)\)/i);
    columnas.set(colNombre, {
      enum: enumM ? enumM[1].split(',').map((s) => s.trim().replace(/^'|'$/g, '')) : null,
    });
  }
  tablas.set(nombre, columnas);
}

// ── 2. Tablas presentes en ambos lados ──
const tablasJson = Object.keys(db).filter((k) => !k.startsWith('_'));
for (const t of tablas.keys()) {
  if (!tablasJson.includes(t)) errores.push(`Tabla del DDL ausente en db.json: ${t}`);
}
for (const t of tablasJson) {
  if (!tablas.has(t)) errores.push(`Clave de db.json sin tabla en el DDL: ${t}`);
}

// ── 3. Columnas exactas y enums por fila ──
for (const [tabla, columnas] of tablas) {
  const filas = db[tabla];
  if (!Array.isArray(filas)) continue;
  const esperadas = new Set(columnas.keys());
  filas.forEach((fila, i) => {
    for (const k of Object.keys(fila)) {
      if (!esperadas.has(k)) errores.push(`${tabla}[${i}]: columna extra '${k}'`);
    }
    for (const k of esperadas) {
      if (!(k in fila)) errores.push(`${tabla}[${i}]: falta columna '${k}'`);
    }
    for (const [k, meta] of columnas) {
      if (meta.enum && fila[k] !== null && fila[k] !== undefined && !meta.enum.includes(fila[k])) {
        errores.push(`${tabla}[${i}].${k}: valor '${fila[k]}' fuera del ENUM(${meta.enum.join(',')})`);
      }
    }
  });
}

// ── 4. Integridad referencial ──
const ids = (t) => new Set((db[t] ?? []).map((r) => r.id));
const usuarios = ids('usuarios');
const areasIds = ids('areas');
const reunionesIds = ids('reuniones');
const acuerdosIds = ids('acuerdos');
const fk = (tabla, campo, valor, destino, i) => {
  if (valor !== null && valor !== undefined && !destino.has(valor)) {
    errores.push(`${tabla}[${i}].${campo}: FK ${valor} sin destino`);
  }
};
(db.usuarios ?? []).forEach((r, i) => fk('usuarios', 'area_id', r.area_id, areasIds, i));
(db.acuerdos ?? []).forEach((r, i) => {
  fk('acuerdos', 'reunion_id', r.reunion_id, reunionesIds, i);
  fk('acuerdos', 'area_id', r.area_id, areasIds, i);
  fk('acuerdos', 'responsable_id', r.responsable_id, usuarios, i);
  fk('acuerdos', 'capturado_por_id', r.capturado_por_id, usuarios, i);
  fk('acuerdos', 'concluido_por_id', r.concluido_por_id, usuarios, i);
});
(db.acuerdo_corresponsables ?? []).forEach((r, i) => {
  fk('acuerdo_corresponsables', 'acuerdo_id', r.acuerdo_id, acuerdosIds, i);
  fk('acuerdo_corresponsables', 'usuario_id', r.usuario_id, usuarios, i);
});
(db.avances ?? []).forEach((r, i) => {
  fk('avances', 'acuerdo_id', r.acuerdo_id, acuerdosIds, i);
  fk('avances', 'usuario_id', r.usuario_id, usuarios, i);
});
(db.recordatorios_enviados ?? []).forEach((r, i) => {
  fk('recordatorios_enviados', 'acuerdo_id', r.acuerdo_id, acuerdosIds, i);
  fk('recordatorios_enviados', 'usuario_id', r.usuario_id, usuarios, i);
});
(db.google_sync ?? []).forEach((r, i) => fk('google_sync', 'acuerdo_id', r.acuerdo_id, acuerdosIds, i));
(db.usuario_google_tokens ?? []).forEach((r, i) => fk('usuario_google_tokens', 'usuario_id', r.usuario_id, usuarios, i));
(db.auditoria ?? []).forEach((r, i) => fk('auditoria', 'usuario_id', r.usuario_id, usuarios, i));

// ── 5. CHECKs del DDL ──
(db.usuarios ?? []).forEach((r, i) => {
  if (r.rol === 'coordinador' && r.area_id === null) {
    errores.push(`usuarios[${i}]: chk_coordinador_area — coordinador sin área`);
  }
});
(db.acuerdos ?? []).forEach((r, i) => {
  const c = r.estado === 'concluido';
  if (c && (r.concluido_por_id === null || r.concluido_at === null)) {
    errores.push(`acuerdos[${i}]: chk_concluido_consistente — concluido sin autor/fecha`);
  }
  if (!c && (r.concluido_por_id !== null || r.concluido_at !== null)) {
    errores.push(`acuerdos[${i}]: chk_concluido_consistente — no-concluido con datos de conclusión`);
  }
});
(db.avances ?? []).forEach((r, i) => {
  if (r.tipo === 'reprogramacion' && r.nueva_fecha === null) {
    errores.push(`avances[${i}]: chk_reprogramacion_fecha — reprogramación sin nueva_fecha`);
  }
});

// ── 6. Reglas de higiene del espejo (Demo-First v2) ──
(db.usuarios ?? []).forEach((r, i) => {
  if (!/@demo\.test$/.test(r.email)) avisos.push(`usuarios[${i}].email: no usa dominio @demo.test (H-05)`);
});
// Unicidad natural de recordatorios
const claves = new Set();
(db.recordatorios_enviados ?? []).forEach((r, i) => {
  const k = `${r.acuerdo_id}|${r.usuario_id}|${r.tipo}|${r.programado_para}`;
  if (claves.has(k)) errores.push(`recordatorios_enviados[${i}]: viola uq_recordatorio_unico (${k})`);
  claves.add(k);
});

// ── Reporte ──
console.log('Verificación db.json ↔ DDL (Gobernanza v3 §5)');
console.log(`Tablas DDL: ${tablas.size} · Tablas db.json: ${tablasJson.length}`);
if (avisos.length) {
  console.log(`\nAvisos (${avisos.length}):`);
  for (const a of avisos) console.log(`  ⚠ ${a}`);
}
if (errores.length) {
  console.error(`\nDISCREPANCIAS BLOQUEANTES (${errores.length}):`);
  for (const e of errores) console.error(`  ✗ ${e}`);
  process.exit(1);
}
console.log('\n\u2713 Espejo verificado: tablas, columnas, enums, FKs y CHECKs sin discrepancias.');

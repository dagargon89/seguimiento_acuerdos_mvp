// k6-acuerdos.js — S3.1: script de carga para los umbrales de rendimiento del
// doc 06 §4 (Plan de pruebas). NO se ejecutó en este entorno (k6 no está
// instalado) — ver README.md de este directorio para cómo correrlo y qué
// dataset necesita.
//
// Escenarios (doc 06 §4):
//   1. GET /acuerdos            (5,000 filas, per_page=200)  p95 < 500 ms
//   2. GET /acuerdos/{id}       (50 avances)                 p95 < 300 ms
//   3. POST /acuerdos/lote      (20 acuerdos por request)    p95 < 800 ms
//
// Carga: 10 VU × 2 min por escenario (doc 06 §4), en paralelo vía `scenarios`.
//
// Parámetros por variable de entorno:
//   BASE_URL   — default http://localhost:8080/api/v1
//   API_TOKEN  — Bearer token de un usuario Dirección (idToken Firebase real
//                o el que use el entorno de pruebas de carga). REQUERIDO.
//   ACUERDO_ID — id de un acuerdo con ~50 avances para el escenario de detalle
//                (default 1 — ajusta tras correr PerfSeeder, ver README).
//   AREA_ID / RESPONSABLE_ID — usados para construir el payload del lote
//                (defaults 1 / 1 — ajusta a un usuario/área existentes).
//
// Ejecutar (con k6 instalado):
//   BASE_URL=http://localhost:8080/api/v1 API_TOKEN=eyJ... k6 run tests/perf/k6-acuerdos.js

import http from 'k6/http';
import { check } from 'k6';

const BASE_URL       = __ENV.BASE_URL || 'http://localhost:8080/api/v1';
const API_TOKEN      = __ENV.API_TOKEN || '';
const ACUERDO_ID     = __ENV.ACUERDO_ID || '1';
const AREA_ID         = Number(__ENV.AREA_ID || 1);
const RESPONSABLE_ID = Number(__ENV.RESPONSABLE_ID || 1);

if (!API_TOKEN) {
  // k6 evalúa el script al cargarlo; fallar rápido y explícito en vez de
  // disparar 401 en cada request de cada VU.
  throw new Error('Falta API_TOKEN. Ejemplo: API_TOKEN=eyJ... k6 run tests/perf/k6-acuerdos.js');
}

const headers = {
  Authorization: `Bearer ${API_TOKEN}`,
  'Content-Type': 'application/json',
};

export const options = {
  scenarios: {
    listado_acuerdos: {
      executor: 'constant-vus',
      exec: 'listado',
      vus: 10,
      duration: '2m',
      tags: { escenario: 'listado' },
    },
    detalle_acuerdo: {
      executor: 'constant-vus',
      exec: 'detalle',
      vus: 10,
      duration: '2m',
      tags: { escenario: 'detalle' },
    },
    captura_lote: {
      executor: 'constant-vus',
      exec: 'lote',
      vus: 10,
      duration: '2m',
      tags: { escenario: 'lote' },
    },
  },
  thresholds: {
    // Umbrales del doc 06 §4, filtrados por escenario vía tags.
    'http_req_duration{escenario:listado}': ['p(95)<500'],
    'http_req_duration{escenario:detalle}': ['p(95)<300'],
    'http_req_duration{escenario:lote}': ['p(95)<800'],
    // Cero errores de servidor en cualquier escenario (higiene mínima de carga).
    http_req_failed: ['rate<0.01'],
  },
};

/** Escenario 1 — GET /acuerdos con 5,000 filas sembradas (ver PerfSeeder), per_page=200. */
export function listado() {
  const res = http.get(`${BASE_URL}/acuerdos?per_page=200`, { headers, tags: { escenario: 'listado' } });
  check(res, {
    'listado 200': (r) => r.status === 200,
    'listado trae data[]': (r) => {
      try {
        return Array.isArray(JSON.parse(r.body).data);
      } catch {
        return false;
      }
    },
  });
}

/** Escenario 2 — GET /acuerdos/{id} de un acuerdo con ~50 avances (ver PerfSeeder). */
export function detalle() {
  const res = http.get(`${BASE_URL}/acuerdos/${ACUERDO_ID}`, { headers, tags: { escenario: 'detalle' } });
  check(res, {
    'detalle 200': (r) => r.status === 200,
    'detalle trae avances[]': (r) => {
      try {
        return Array.isArray(JSON.parse(r.body).data.avances);
      } catch {
        return false;
      }
    },
  });
}

/** Escenario 3 — POST /acuerdos/lote con 20 acuerdos por request (doc 05 §2.2). */
export function lote() {
  const hoy = new Date();
  const fechaFutura = new Date(hoy.getTime() + 30 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);

  const acuerdos = [];
  for (let i = 0; i < 20; i++) {
    acuerdos.push({
      tema: `k6 carga #${i}`,
      accion: `Acuerdo de carga generado por k6 (VU ${__VU}, iter ${__ITER}, #${i})`,
      responsable_id: RESPONSABLE_ID,
      corresponsables_ids: [],
      area_id: AREA_ID,
      fecha_compromiso: fechaFutura,
      enlace: null,
      observaciones: null,
      recordatorio_dias: null,
    });
  }

  const payload = JSON.stringify({
    reunion: { nombre: `Reunión de carga k6 (VU ${__VU} iter ${__ITER})`, fecha: fechaFutura },
    acuerdos,
  });

  const res = http.post(`${BASE_URL}/acuerdos/lote`, payload, { headers, tags: { escenario: 'lote' } });
  check(res, {
    'lote 201': (r) => r.status === 201,
  });
}

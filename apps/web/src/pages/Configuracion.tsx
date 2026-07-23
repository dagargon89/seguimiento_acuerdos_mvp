/**
 * Configuración global (solo Dirección). Reúne los ajustes que antes vivían en
 * el modal "Configurar avisos" de Recordatorios y agrega el interruptor para
 * habilitar/deshabilitar los correos de solicitud de avances a los usuarios.
 * Todo persiste en el mismo esquema global (`configuracion.recordatorios_default`).
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib';
import type { ConfigRecordatorios } from '../lib';
import { camposError, mensajeError } from '../components/EstadoHelpers';
import { Select } from '../components/Select';
import { useToast } from '../components/Toast';

export function Configuracion() {
  const { toast } = useToast();
  const queryClient = useQueryClient();

  const configQ = useQuery({ queryKey: ['config-recordatorios'], queryFn: () => api.getConfigRecordatorios() });
  const cfg = configQ.data;

  // Estado del formulario de avisos, sembrado desde el esquema vigente.
  const [diasCsv, setDiasCsv] = useState('');
  const [diaCompromiso, setDiaCompromiso] = useState(true);
  const [vencidoCada, setVencidoCada] = useState('3');
  const [vencidoMax, setVencidoMax] = useState('5');
  const [frecuencia, setFrecuencia] = useState<ConfigRecordatorios['resumen_frecuencia']>('semanal');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!cfg) return;
    setDiasCsv(cfg.dias_antes.join(', '));
    setDiaCompromiso(cfg.dia_compromiso);
    setVencidoCada(String(cfg.vencido_cada_dias));
    setVencidoMax(String(cfg.vencido_max_repeticiones));
    setFrecuencia(cfg.resumen_frecuencia);
  }, [cfg]);

  const guardarMut = useMutation({
    mutationFn: (nueva: ConfigRecordatorios) => api.setConfigRecordatorios(nueva),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['config-recordatorios'] });
      void queryClient.invalidateQueries({ queryKey: ['recordatorios'] });
      void queryClient.invalidateQueries({ queryKey: ['acuerdo'] });
    },
  });

  const guardarAvisos = () => {
    if (!cfg) return;
    const dias = diasCsv
      .split(/[,\s]+/)
      .filter((x) => x !== '')
      .map(Number);
    if (dias.length === 0 || dias.some((d) => !Number.isInteger(d) || d < 0 || d > 30)) {
      setError('Escribe los avisos previos como días separados por coma (0–30), por ejemplo: 7, 3, 1.');
      return;
    }
    const cada = Number(vencidoCada);
    const max = Number(vencidoMax);
    if (!Number.isInteger(cada) || cada < 1 || !Number.isInteger(max) || max < 0) {
      setError('El seguimiento de vencidos requiere números válidos (cada N días, máximo de repeticiones).');
      return;
    }
    setError(null);
    guardarMut.mutate(
      {
        dias_antes: dias,
        dia_compromiso: diaCompromiso,
        vencido_cada_dias: cada,
        vencido_max_repeticiones: max,
        resumen_frecuencia: frecuencia,
        solicitud_avances_activa: cfg.solicitud_avances_activa,
        invitaciones_calendario_activas: cfg.invitaciones_calendario_activas,
      },
      {
        onSuccess: () => toast('El esquema de recordatorios se actualizó.'),
        onError: (e) => {
          const campos = camposError(e);
          setError(campos.dias_antes ? `Avisos previos: ${campos.dias_antes}` : mensajeError(e));
          toast(mensajeError(e), 'error');
        },
      },
    );
  };

  const alternarSolicitud = (activa: boolean) => {
    if (!cfg) return;
    guardarMut.mutate(
      { ...cfg, solicitud_avances_activa: activa },
      {
        onSuccess: () =>
          toast(activa ? 'Se habilitaron las solicitudes de avances.' : 'Se deshabilitaron las solicitudes de avances.'),
        onError: (e) => toast(mensajeError(e), 'error'),
      },
    );
  };

  const alternarInvitaciones = (activa: boolean) => {
    if (!cfg) return;
    guardarMut.mutate(
      { ...cfg, invitaciones_calendario_activas: activa },
      {
        onSuccess: () =>
          toast(
            activa
              ? 'Google Calendar enviará invitaciones por correo.'
              : 'Google Calendar ya no enviará invitaciones por correo.',
          ),
        onError: (e) => toast(mensajeError(e), 'error'),
      },
    );
  };

  return (
    <div style={{ maxWidth: 720, margin: '0 auto' }}>
      <div className="anim-in" style={{ marginBottom: 28 }}>
        <div className="section-header__eyebrow">Administración · solo Dirección</div>
        <h2 className="section-header__title">Configuración</h2>
        <p className="section-header__subtitle">
          Ajustes globales del panel: el esquema de avisos automáticos por correo y las solicitudes de avances a los
          responsables.
        </p>
      </div>

      {configQ.isError && (
        <div className="alert alert--error" style={{ marginBottom: 16 }}>
          <div className="alert__body">{mensajeError(configQ.error)}</div>
        </div>
      )}
      {configQ.isLoading && (
        <div className="panel-card" style={{ padding: 32, textAlign: 'center', fontSize: 13, color: 'var(--text-muted)' }}>
          Cargando configuración…
        </div>
      )}

      {cfg && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 22 }}>
          {/* ── Esquema de avisos ── */}
          <section className="panel-card anim-in anim-in--1" style={{ padding: '20px 22px 24px' }}>
            <h3 style={{ margin: '0 0 4px', fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 16 }}>
              Esquema de avisos
            </h3>
            <p style={{ margin: '0 0 18px', fontSize: 12.5, lineHeight: 1.6, color: 'var(--text-muted)' }}>
              Aplica a todos los acuerdos sin esquema personalizado. Los acuerdos con recordatorios propios (definidos
              al capturar) conservan su configuración.
            </p>

            {error && (
              <div className="alert alert--error" style={{ marginBottom: 16 }}>
                <div className="alert__body">{error}</div>
              </div>
            )}

            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div className="field">
                <label className="field__label" htmlFor="cfg-dias">
                  Avisos previos (días antes, separados por coma) <span className="req">*</span>
                </label>
                <input
                  id="cfg-dias"
                  className="input"
                  placeholder="Ej. 7, 3, 1"
                  value={diasCsv}
                  onChange={(e) => setDiasCsv(e.target.value)}
                />
              </div>
              <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, fontWeight: 500, cursor: 'pointer' }}>
                <input type="checkbox" checked={diaCompromiso} onChange={(e) => setDiaCompromiso(e.target.checked)} />
                Enviar aviso el día del compromiso
              </label>
              <div className="grid grid-cols-1 sm:grid-cols-[1fr_1fr] gap-[16px]">
                <div className="field">
                  <label className="field__label" htmlFor="cfg-cada">
                    Vencidos: repetir cada (días)
                  </label>
                  <input
                    id="cfg-cada"
                    className="input"
                    type="number"
                    min={1}
                    value={vencidoCada}
                    onChange={(e) => setVencidoCada(e.target.value)}
                  />
                </div>
                <div className="field">
                  <label className="field__label" htmlFor="cfg-max">
                    Vencidos: máximo de envíos
                  </label>
                  <input
                    id="cfg-max"
                    className="input"
                    type="number"
                    min={0}
                    value={vencidoMax}
                    onChange={(e) => setVencidoMax(e.target.value)}
                  />
                </div>
              </div>
              <div className="field">
                <label className="field__label" htmlFor="cfg-frec">
                  Frecuencia del resumen periódico
                </label>
                <Select
                  id="cfg-frec"
                  buscable={false}
                  value={frecuencia}
                  opciones={[
                    { value: 'semanal', label: 'Semanal' },
                    { value: 'quincenal', label: 'Quincenal' },
                    { value: 'mensual', label: 'Mensual' },
                  ]}
                  onChange={(v) => setFrecuencia(v as ConfigRecordatorios['resumen_frecuencia'])}
                />
              </div>
              <div>
                <button type="button" className="btn btn--accent btn--md" onClick={guardarAvisos} disabled={guardarMut.isPending}>
                  {guardarMut.isPending ? 'Guardando…' : 'Guardar esquema'}
                </button>
              </div>
            </div>
          </section>

          {/* ── Solicitud de avances ── */}
          <section className="panel-card anim-in anim-in--2" style={{ padding: '20px 22px 24px' }}>
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 16, flexWrap: 'wrap' }}>
              <div style={{ flex: 1, minWidth: 260 }}>
                <h3 style={{ margin: '0 0 4px', fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 16 }}>
                  Solicitud de avances
                </h3>
                <p style={{ margin: 0, fontSize: 12.5, lineHeight: 1.6, color: 'var(--text-muted)' }}>
                  Cuando está habilitada, el sistema envía correos automáticos pidiendo a los responsables reportar el
                  avance de sus acuerdos abiertos. Al deshabilitarla, estos correos dejan de enviarse.
                </p>
              </div>
              <label style={{ display: 'inline-flex', alignItems: 'center', gap: 10, cursor: 'pointer', flex: 'none' }}>
                <input
                  type="checkbox"
                  role="switch"
                  checked={cfg.solicitud_avances_activa}
                  disabled={guardarMut.isPending}
                  onChange={(e) => alternarSolicitud(e.target.checked)}
                />
                <span style={{ fontSize: 13, fontWeight: 600 }}>
                  {cfg.solicitud_avances_activa ? 'Habilitada' : 'Deshabilitada'}
                </span>
              </label>
            </div>
          </section>

          {/* ── Invitaciones de Google Calendar ── */}
          <section className="panel-card anim-in anim-in--3" style={{ padding: '20px 22px 24px' }}>
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 16, flexWrap: 'wrap' }}>
              <div style={{ flex: 1, minWidth: 260 }}>
                <h3 style={{ margin: '0 0 4px', fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 16 }}>
                  Invitaciones de Google Calendar
                </h3>
                <p style={{ margin: 0, fontSize: 12.5, lineHeight: 1.6, color: 'var(--text-muted)' }}>
                  Cuando está habilitada, Google Calendar envía una invitación por correo al responsable y
                  corresponsables al crear o actualizar el evento del acuerdo. Al deshabilitarla, el acuerdo sigue
                  sincronizándose al calendario, pero sin enviar esas invitaciones individuales. No afecta los
                  recordatorios por correo.
                </p>
              </div>
              <label style={{ display: 'inline-flex', alignItems: 'center', gap: 10, cursor: 'pointer', flex: 'none' }}>
                <input
                  type="checkbox"
                  role="switch"
                  checked={cfg.invitaciones_calendario_activas}
                  disabled={guardarMut.isPending}
                  onChange={(e) => alternarInvitaciones(e.target.checked)}
                />
                <span style={{ fontSize: 13, fontWeight: 600 }}>
                  {cfg.invitaciones_calendario_activas ? 'Habilitadas' : 'Deshabilitadas'}
                </span>
              </label>
            </div>
          </section>
        </div>
      )}
    </div>
  );
}

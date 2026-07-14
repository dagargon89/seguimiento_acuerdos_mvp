/**
 * Captura de acuerdos 1:1 con el demo (doble vista Formularios/Hoja, bloques
 * repetibles, validación con resaltado) + campos nuevos: corresponsables y
 * esquema de recordatorios. SIN campo estado (máquina de estados v2: todo
 * acuerdo nace en_proceso, RF-05.1).
 */
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib';
import type { LoteCaptura, NuevoAcuerdo } from '../lib';
import { fmtF, hoyISO } from '../lib/fechas';
import { camposError, mensajeError } from '../components/EstadoHelpers';
import { CorresponsablesPicker } from '../components/CorresponsablesPicker';
import { DatePicker } from '../components/DatePicker';
import { ModeSwitch } from '../components/ModeSwitch';
import { Select } from '../components/Select';
import { useSesion } from '../components/SessionContext';
import { useToast } from '../components/Toast';

interface FormAcuerdo {
  tema: string;
  accion: string;
  responsable_id: string;
  corresponsables: number[];
  area_id: string;
  fecha: string;
  enlace: string;
  observaciones: string;
  recModo: 'global' | 'custom';
  recDias: string;
}

const nuevoForm = (): FormAcuerdo => ({
  tema: '',
  accion: '',
  responsable_id: '',
  corresponsables: [],
  area_id: '',
  fecha: '',
  enlace: '',
  observaciones: '',
  recModo: 'global',
  recDias: '',
});

function parseRecDias(csv: string): number[] | null {
  const dias = csv
    .split(/[,\s]+/)
    .filter((x) => x !== '')
    .map(Number);
  if (dias.length === 0 || dias.some((d) => !Number.isInteger(d) || d < 0 || d > 30)) return null;
  return dias;
}

export function Captura() {
  const { sesion } = useSesion();
  const { toast } = useToast();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [vista, setVista] = useState<'form' | 'hoja'>('form');
  const [forms, setForms] = useState<FormAcuerdo[]>([nuevoForm()]);
  const [formError, setFormError] = useState<string | null>(null);
  const [formErrorRows, setFormErrorRows] = useState<number[]>([]);

  const usuariosQ = useQuery({ queryKey: ['usuarios'], queryFn: () => api.listUsuarios() });
  const areasQ = useQuery({ queryKey: ['areas'], queryFn: () => api.listAreas() });
  const configQ = useQuery({ queryKey: ['config-recordatorios'], queryFn: () => api.getConfigRecordatorios() });

  const usuariosActivos = (usuariosQ.data ?? []).filter((u) => u.activo);
  const areas = areasQ.data ?? [];
  const esquemaGlobal = configQ.data ? configQ.data.dias_antes.join(', ') : '7, 3, 1';

  const setCampo = <K extends keyof FormAcuerdo>(i: number, campo: K, valor: FormAcuerdo[K]) => {
    setForms((xs) => xs.map((f, idx) => (idx === i ? { ...f, [campo]: valor } : f)));
    if (formError) {
      setFormError(null);
      setFormErrorRows([]);
    }
  };

  const agregar = () => setForms((xs) => [...xs, nuevoForm()]);
  const quitar = (i: number) => {
    setForms((xs) => {
      const ys = xs.filter((_, idx) => idx !== i);
      return ys.length === 0 ? [nuevoForm()] : ys;
    });
    setFormError(null);
    setFormErrorRows([]);
  };

  const guardarMut = useMutation({
    mutationFn: (lote: LoteCaptura) => api.capturarLote(lote),
    onSuccess: (creados) => {
      toast(
        creados.length === 1
          ? 'Se agregó al panel y sus recordatorios quedaron programados.'
          : `Se agregaron ${creados.length} acuerdos al panel y sus recordatorios quedaron programados.`,
        'success',
        creados.length === 1 ? 'Acuerdo guardado' : 'Acuerdos guardados',
      );
      void queryClient.invalidateQueries({ queryKey: ['acuerdos'] });
      void queryClient.invalidateQueries({ queryKey: ['calendario'] });
      void queryClient.invalidateQueries({ queryKey: ['recordatorios'] });
      void queryClient.invalidateQueries({ queryKey: ['checklist'] });
      void navigate('/panel');
    },
    onError: (e) => {
      const campos = camposError(e);
      const filas = [
        ...new Set(
          Object.keys(campos)
            .map((k) => /^acuerdos\.(\d+)\./.exec(k)?.[1])
            .filter((x): x is string => x !== undefined)
            .map(Number),
        ),
      ];
      setFormError(mensajeError(e));
      setFormErrorRows(filas);
      toast(mensajeError(e), 'error');
    },
  });

  const guardar = () => {
    const incompletos: number[] = [];
    forms.forEach((f, i) => {
      const recInvalido = f.recModo === 'custom' && parseRecDias(f.recDias) === null;
      if (!f.accion.trim() || !f.responsable_id || !f.fecha || !f.area_id || recInvalido) incompletos.push(i);
    });
    if (incompletos.length) {
      const nums = incompletos.map((i) => i + 1);
      const cual =
        nums.length === 1
          ? `${vista === 'hoja' ? 'el renglón ' : 'el acuerdo '}${nums[0]}`
          : `${vista === 'hoja' ? 'los renglones ' : 'los acuerdos '}${nums.join(', ')}`;
      setFormError(
        `Completa los campos obligatorios (acuerdo/acción, responsable, fecha compromiso y área — y días de aviso válidos si son personalizados) de ${cual}.`,
      );
      setFormErrorRows(incompletos);
      return;
    }
    const acuerdos: NuevoAcuerdo[] = forms.map((f) => ({
      tema: f.tema.trim() ? f.tema.trim() : null,
      accion: f.accion.trim(),
      responsable_id: Number(f.responsable_id),
      corresponsables_ids: f.corresponsables,
      area_id: Number(f.area_id),
      fecha_compromiso: f.fecha,
      enlace: f.enlace.trim() ? f.enlace.trim() : null,
      observaciones: f.observaciones.trim() ? f.observaciones.trim() : null,
      recordatorio_dias: f.recModo === 'custom' ? parseRecDias(f.recDias) : null,
    }));
    guardarMut.mutate({
      reunion: { nombre: `Reunión de dirección · ${fmtF(hoyISO())}`, fecha: hoyISO() },
      acuerdos,
    });
  };

  const total = forms.length;
  const esHoja = vista === 'hoja';

  const bloque = (f: FormAcuerdo, i: number) => {
    const invalido = formErrorRows.includes(i);
    return (
      <div
        key={i}
        className={`panel-card captura-bloque anim-in${invalido ? ' captura-bloque--invalido' : ''}`}
        style={{ padding: '26px 30px', marginBottom: 16 }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 20 }}>
          <span
            style={{
              fontFamily: 'var(--font-display)',
              fontSize: 11,
              fontWeight: 600,
              textTransform: 'uppercase',
              letterSpacing: '.12em',
              color: 'var(--teal)',
            }}
          >
            Acuerdo {i + 1} de {total}
          </span>
          <div style={{ flex: 1, height: 1, background: 'var(--border)' }} />
          {total > 1 && (
            <button type="button" className="captura-bloque__quitar" onClick={() => quitar(i)} title="Quitar este acuerdo">
              ✕ Quitar
            </button>
          )}
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-[1fr_1fr] gap-[18px]">
          <div className="field">
            <label className="field__label" htmlFor={`f-tema-${i}`}>
              Tema
            </label>
            <input
              className="input"
              id={`f-tema-${i}`}
              placeholder="Ej. Panel de seguimiento"
              value={f.tema}
              onChange={(e) => setCampo(i, 'tema', e.target.value)}
            />
          </div>
          <div className="field">
            <label className="field__label" htmlFor={`f-resp-${i}`}>
              Responsable <span className="req">*</span>
            </label>
            <Select
              id={`f-resp-${i}`}
              value={f.responsable_id}
              placeholder="Selecciona…"
              opciones={usuariosActivos.map((u) => ({ value: String(u.id), label: u.nombre }))}
              onChange={(id) => {
                setForms((xs) =>
                  xs.map((x, idx) =>
                    idx === i
                      ? { ...x, responsable_id: id, corresponsables: x.corresponsables.filter((c) => c !== Number(id)) }
                      : x,
                  ),
                );
              }}
            />
          </div>
          <div className="field" style={{ gridColumn: '1 / -1' }}>
            <label className="field__label" htmlFor={`f-accion-${i}`}>
              Acuerdo / acción <span className="req">*</span>
            </label>
            <textarea
              className="textarea"
              id={`f-accion-${i}`}
              placeholder="Qué se acordó hacer, en una frase accionable"
              style={{ minHeight: 84 }}
              value={f.accion}
              onChange={(e) => setCampo(i, 'accion', e.target.value)}
            />
          </div>
          <div className="field" style={{ gridColumn: '1 / -1' }}>
            <span className="field__label">Corresponsables</span>
            <CorresponsablesPicker
              directorio={usuariosActivos}
              seleccionados={f.corresponsables}
              excluirId={f.responsable_id ? Number(f.responsable_id) : null}
              onChange={(ids) => setCampo(i, 'corresponsables', ids)}
            />
          </div>
          <div className="field">
            <label className="field__label" htmlFor={`f-fecha-${i}`}>
              Fecha compromiso <span className="req">*</span>
            </label>
            <DatePicker
              id={`f-fecha-${i}`}
              min={hoyISO()}
              value={f.fecha}
              onChange={(iso) => setCampo(i, 'fecha', iso)}
            />
          </div>
          <div className="field">
            <label className="field__label" htmlFor={`f-area-${i}`}>
              Área <span className="req">*</span>
            </label>
            <Select
              id={`f-area-${i}`}
              value={f.area_id}
              placeholder="Selecciona…"
              opciones={areas.map((a) => ({ value: String(a.id), label: a.nombre }))}
              onChange={(v) => setCampo(i, 'area_id', v)}
            />
          </div>
          <div className="field">
            <label className="field__label" htmlFor={`f-rec-${i}`}>
              Recordatorios
            </label>
            <Select
              id={`f-rec-${i}`}
              buscable={false}
              value={f.recModo}
              opciones={[
                { value: 'global', label: `Esquema global (${esquemaGlobal})` },
                { value: 'custom', label: 'Personalizado' },
              ]}
              onChange={(v) => setCampo(i, 'recModo', v as 'global' | 'custom')}
            />
            {f.recModo === 'custom' && (
              <input
                className="input"
                aria-label="Días de aviso personalizados"
                placeholder='Días antes, separados por coma. Ej. "5, 1"'
                value={f.recDias}
                onChange={(e) => setCampo(i, 'recDias', e.target.value)}
              />
            )}
          </div>
          <div className="field">
            <label className="field__label" htmlFor={`f-enlace-${i}`}>
              Enlace a productos
            </label>
            <input
              className="input"
              id={`f-enlace-${i}`}
              placeholder="URL del documento o carpeta en Drive (opcional)"
              value={f.enlace}
              onChange={(e) => setCampo(i, 'enlace', e.target.value)}
            />
          </div>
          <div className="field" style={{ gridColumn: '1 / -1' }}>
            <label className="field__label" htmlFor={`f-obs-${i}`}>
              Observaciones
            </label>
            <textarea
              className="textarea"
              id={`f-obs-${i}`}
              placeholder="Contexto adicional (opcional)"
              style={{ minHeight: 64 }}
              value={f.observaciones}
              onChange={(e) => setCampo(i, 'observaciones', e.target.value)}
            />
          </div>
        </div>
      </div>
    );
  };

  const hoja = (
    <div className="panel-card captura-grid" style={{ overflowX: 'auto', marginBottom: 16 }}>
      <table>
        <thead>
          <tr>
            <th style={{ width: 36 }}>#</th>
            <th style={{ minWidth: 130 }}>Tema</th>
            <th style={{ minWidth: 240 }}>
              Acuerdo / acción <span className="req">*</span>
            </th>
            <th style={{ minWidth: 150 }}>
              Responsable <span className="req">*</span>
            </th>
            <th style={{ minWidth: 170 }}>
              Área <span className="req">*</span>
            </th>
            <th style={{ minWidth: 135 }}>
              Fecha compromiso <span className="req">*</span>
            </th>
            <th style={{ minWidth: 170 }}>Corresponsables</th>
            <th style={{ minWidth: 150 }}>Recordatorios</th>
            <th style={{ minWidth: 150 }}>Enlace a productos</th>
            <th style={{ minWidth: 150 }}>Observaciones</th>
            <th style={{ width: 44 }} />
          </tr>
        </thead>
        <tbody>
          {forms.map((f, i) => (
            <tr key={i} className={formErrorRows.includes(i) ? 'captura-grid__fila--invalida' : ''}>
              <td className="captura-grid__num">{i + 1}</td>
              <td>
                <input className="cell-input" placeholder="Tema" value={f.tema} onChange={(e) => setCampo(i, 'tema', e.target.value)} />
              </td>
              <td>
                <input
                  className="cell-input"
                  placeholder="Qué se acordó hacer *"
                  value={f.accion}
                  onChange={(e) => setCampo(i, 'accion', e.target.value)}
                />
              </td>
              <td>
                <Select
                  variante="cell"
                  ariaLabel={`Responsable del renglón ${i + 1}`}
                  value={f.responsable_id}
                  placeholder="Selecciona… *"
                  opciones={usuariosActivos.map((u) => ({ value: String(u.id), label: u.nombre }))}
                  onChange={(id) => {
                    setForms((xs) =>
                      xs.map((x, idx) =>
                        idx === i
                          ? { ...x, responsable_id: id, corresponsables: x.corresponsables.filter((c) => c !== Number(id)) }
                          : x,
                      ),
                    );
                  }}
                />
              </td>
              <td>
                <Select
                  variante="cell"
                  ariaLabel={`Área del renglón ${i + 1}`}
                  value={f.area_id}
                  placeholder="Selecciona… *"
                  opciones={areas.map((a) => ({ value: String(a.id), label: a.nombre }))}
                  onChange={(v) => setCampo(i, 'area_id', v)}
                />
              </td>
              <td>
                <DatePicker
                  variante="cell"
                  ariaLabel={`Fecha compromiso del renglón ${i + 1}`}
                  min={hoyISO()}
                  value={f.fecha}
                  onChange={(iso) => setCampo(i, 'fecha', iso)}
                />
              </td>
              <td style={{ padding: '4px 6px' }}>
                <CorresponsablesPicker
                  directorio={usuariosActivos}
                  seleccionados={f.corresponsables}
                  excluirId={f.responsable_id ? Number(f.responsable_id) : null}
                  onChange={(ids) => setCampo(i, 'corresponsables', ids)}
                  compacto
                />
              </td>
              <td>
                <Select
                  variante="cell"
                  buscable={false}
                  ariaLabel={`Recordatorios del renglón ${i + 1}`}
                  value={f.recModo}
                  opciones={[
                    { value: 'global', label: `Esquema global (${esquemaGlobal})` },
                    { value: 'custom', label: 'Personalizado' },
                  ]}
                  onChange={(v) => setCampo(i, 'recModo', v as 'global' | 'custom')}
                />
                {f.recModo === 'custom' && (
                  <input
                    className="cell-input"
                    aria-label={`Días de aviso del renglón ${i + 1}`}
                    placeholder='Ej. "5, 1"'
                    value={f.recDias}
                    onChange={(e) => setCampo(i, 'recDias', e.target.value)}
                  />
                )}
              </td>
              <td>
                <input
                  className="cell-input"
                  placeholder="URL (opcional)"
                  value={f.enlace}
                  onChange={(e) => setCampo(i, 'enlace', e.target.value)}
                />
              </td>
              <td>
                <input
                  className="cell-input"
                  placeholder="Opcional"
                  value={f.observaciones}
                  onChange={(e) => setCampo(i, 'observaciones', e.target.value)}
                />
              </td>
              <td className="captura-grid__acciones">
                {forms.length > 1 && (
                  <button type="button" className="captura-bloque__quitar" onClick={() => quitar(i)} title="Quitar este renglón">
                    ✕
                  </button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );

  return (
    <div style={{ maxWidth: esHoja ? undefined : 820, margin: '0 auto' }}>
      <div className="anim-in" style={{ display: 'flex', alignItems: 'flex-end', gap: 16, flexWrap: 'wrap', marginBottom: 28 }}>
        <div style={{ flex: 1, minWidth: 300 }}>
          <div className="section-header__eyebrow">Formato de Reunión Operativa</div>
          <h2 className="section-header__title">Capturar acuerdos</h2>
          <p className="section-header__subtitle">
            Se llena en el momento, mientras los acuerdos se pactan en la reunión. Agrega tantos acuerdos como
            necesites y guárdalos todos de una vez; el panel y los recordatorios se generan a partir de estos campos.
          </p>
        </div>
        <div style={{ flex: 'none' }}>
          <ModeSwitch
            opciones={[
              { k: 'form', label: 'Formularios' },
              { k: 'hoja', label: 'Hoja de captura' },
            ]}
            activo={vista}
            onChange={setVista}
          />
        </div>
      </div>

      {formError && (
        <div style={{ marginBottom: 16 }}>
          <div className="alert alert--error">
            <div className="alert__body">{formError}</div>
          </div>
        </div>
      )}

      {esHoja ? hoja : forms.map((f, i) => bloque(f, i))}

      <button type="button" className="captura-agregar" onClick={agregar}>
        {esHoja ? '+ Agregar renglón' : '+ Agregar otro acuerdo'}
      </button>

      <div className="panel-card" style={{ padding: '18px 30px', marginTop: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <button type="button" className="btn btn--accent btn--glow btn--md" onClick={guardar} disabled={guardarMut.isPending}>
            {guardarMut.isPending ? 'Guardando…' : total === 1 ? 'Guardar acuerdo' : `Guardar ${total} acuerdos`}
          </button>
          <button type="button" className="btn btn--ghost btn--md" onClick={() => void navigate('/panel')}>
            Cancelar
          </button>
          <span style={{ marginLeft: 'auto', fontSize: 12, color: 'var(--text-muted)', maxWidth: 300, textAlign: 'right' }}>
            Los recordatorios se programarán automáticamente {esquemaGlobal} días antes de cada fecha compromiso
            (o con el esquema personalizado del acuerdo).
          </span>
        </div>
      </div>

      <div style={{ marginTop: 14, fontSize: 12.5, color: 'var(--text-muted)' }}>
        {total === 1 ? 'Este acuerdo se registrará' : 'Estos acuerdos se registrarán'} en: Reunión de dirección ·{' '}
        {fmtF(hoyISO())} · Capturado por {sesion?.usuario.nombre ?? '—'}
      </div>
    </div>
  );
}

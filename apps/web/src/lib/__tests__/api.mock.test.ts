/**
 * Pruebas del mock del contrato (reglas de dominio del SRS).
 * Único lugar donde se permite importar api.mock directamente.
 * El db en memoria es compartido: los tests corren en orden dentro del archivo.
 */
import { describe, expect, it } from 'vitest';
import { mockClient, mockLogin } from '../api.mock';

/** Fecha a N días de hoy, formateada localmente (mediodía, sin bordes de TZ). */
function fecha(nDias: number): string {
  const t = new Date();
  t.setHours(12, 0, 0, 0);
  t.setDate(t.getDate() + nDias);
  const y = t.getFullYear();
  const m = String(t.getMonth() + 1).padStart(2, '0');
  const d = String(t.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

const ID_DIRECCION = 1; // Diana Dirección
const ID_COORD = 2; // Carla Coordinadora
const ID_RESPONSABLE = 4; // Rita Responsable

describe('api.mock — reglas de dominio', () => {
  it('un responsable solo ve acuerdos donde es responsable o corresponsable', async () => {
    mockLogin(ID_RESPONSABLE);
    const { data } = await mockClient.listAcuerdos({ per_page: 200 });
    expect(data.length).toBeGreaterThan(0);
    for (const a of data) {
      const participa =
        a.responsable.id === ID_RESPONSABLE || a.corresponsables.some((c) => c.id === ID_RESPONSABLE);
      expect(participa).toBe(true);
    }
  });

  it('un responsable NO puede concluir un acuerdo (403)', async () => {
    mockLogin(ID_RESPONSABLE);
    const { data } = await mockClient.listAcuerdos({ per_page: 200 });
    const abierto = data.find((a) => a.estado !== 'concluido');
    expect(abierto).toBeDefined();
    await expect(mockClient.concluirAcuerdo(abierto!.id, 'intento')).rejects.toMatchObject({
      status: 403,
      error: 'solo_direccion',
    });
  });

  it('registrar avance con nueva fecha futura sobre un vencido lo regresa a en_proceso', async () => {
    mockLogin(ID_DIRECCION);
    const { data } = await mockClient.listAcuerdos({ estado: 'vencido', per_page: 200 });
    expect(data.length).toBeGreaterThan(0);
    const vencido = data[0];
    const detalle = await mockClient.registrarAvance(vencido.id, {
      descripcion: 'Se retomó el pendiente con nueva fecha.',
      nueva_fecha: fecha(10),
    });
    expect(detalle.estado).toBe('en_proceso');
    expect(detalle.fecha_compromiso).toBe(fecha(10));
    expect(detalle.avances[0].tipo).toBe('reprogramacion');
  });

  it('dirección concluye un acuerdo y desaparece de la lista default', async () => {
    mockLogin(ID_DIRECCION);
    const { data } = await mockClient.listAcuerdos({ per_page: 200 });
    const abierto = data.find((a) => a.estado !== 'concluido');
    expect(abierto).toBeDefined();

    const concluido = await mockClient.concluirAcuerdo(abierto!.id, 'Validado en checklist.');
    expect(concluido.estado).toBe('concluido');
    expect(concluido.concluido_por?.id).toBe(ID_DIRECCION);

    const despues = await mockClient.listAcuerdos({ per_page: 200 });
    expect(despues.data.some((a) => a.id === abierto!.id)).toBe(false);

    const soloConcluidos = await mockClient.listAcuerdos({ estado: 'concluido', per_page: 200 });
    expect(soloConcluidos.data.some((a) => a.id === abierto!.id)).toBe(true);
  });

  it('capturarLote con un renglón sin acción rechaza 422 y NO persiste ninguno (todo-o-nada)', async () => {
    mockLogin(ID_DIRECCION);
    const antes = (await mockClient.listAcuerdos({ per_page: 200 })).meta.total;

    const valido = {
      tema: 'Prueba',
      accion: 'Acuerdo válido de prueba',
      responsable_id: ID_RESPONSABLE,
      corresponsables_ids: [],
      area_id: 1,
      fecha_compromiso: fecha(5),
      enlace: null,
      observaciones: null,
      recordatorio_dias: null,
    };
    const invalido = { ...valido, accion: '   ' };

    await expect(
      mockClient.capturarLote({
        reunion: { nombre: 'Reunión de prueba', fecha: fecha(0) },
        acuerdos: [valido, invalido],
      }),
    ).rejects.toMatchObject({
      status: 422,
      campos: expect.objectContaining({ 'acuerdos.1.accion': 'Requerido' }),
    });

    const despues = (await mockClient.listAcuerdos({ per_page: 200 })).meta.total;
    expect(despues).toBe(antes);
  });

  it('un coordinador NO puede cambiar la configuración de recordatorios (403)', async () => {
    mockLogin(ID_COORD);
    await expect(
      mockClient.setConfigRecordatorios({
        dias_antes: [5, 1],
        dia_compromiso: true,
        vencido_cada_dias: 2,
        vencido_max_repeticiones: 3,
        resumen_frecuencia: 'semanal',
      }),
    ).rejects.toMatchObject({ status: 403, error: 'solo_direccion' });
  });
});

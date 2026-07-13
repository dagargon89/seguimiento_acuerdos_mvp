<?php

namespace App\Libraries\Correo;

use CodeIgniter\I18n\Time;

/**
 * Plantillas de correo 1:1 con el demo (`EmailModal.tsx` +
 * `recordatorioVm.ts` en `apps/web/src/components`). S2.2.
 *
 * Genera asunto + cuerpo HTML por tipo de recordatorio (`previo`/`dia`/
 * `vencido`) y el resumen periódico (`resumen`), replicando textualmente los
 * `subject`/`intro` que arma el componente React, para que la vista previa
 * del frontend y el correo real que manda `GmailService` luzcan igual.
 *
 * Seguridad (OWASP A03): TODO contenido dinámico proveniente de datos de
 * usuario (acción, tema, responsable, estado, nombre, etc.) se escapa con
 * `esc()` antes de insertarse en el HTML. No hay HTML crudo de usuario sin
 * escapar en ningún punto de esta clase.
 */
final class PlantillaCorreo
{
    private const MORADO = '#53155a';
    private const LIMA   = '#dbec57';

    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    private const ESTADOS = [
        'en_proceso' => ['label' => 'En proceso', 'color' => '#53155a'],
        'vencido'    => ['label' => 'Vencido', 'color' => '#c0392b'],
        'concluido'  => ['label' => 'Concluido', 'color' => '#2e7d50'],
    ];

    /**
     * Recordatorio individual (`previo`/`dia`/`vencido`) para un destinatario.
     *
     * @param array<string, mixed> $acuerdo Fila de `acuerdos` (tema, accion,
     *                                      responsable_nombre, fecha_compromiso, estado, ...).
     * @param array<string, mixed> $usuario Fila de `usuarios` del destinatario (nombre, ...).
     *
     * @return array{asunto: string, html: string}
     */
    public function recordatorio(string $tipo, array $acuerdo, array $usuario): array
    {
        $accion = (string) ($acuerdo['accion'] ?? '');
        $tema   = (string) ($acuerdo['tema'] ?? '');
        $fecha  = (string) ($acuerdo['fecha_compromiso'] ?? '');

        [$asunto, $intro] = match ($tipo) {
            'previo' => [
                'Recordatorio: ' . $accion,
                'Te recordamos que el siguiente acuerdo vence el ' . $this->fechaLarga($fecha)
                    . '. Si ya está resuelto, registra el avance en el panel.',
            ],
            'dia' => [
                'Vence hoy: ' . $accion,
                'Hoy es la fecha compromiso del siguiente acuerdo. Registra el avance en el panel '
                    . 'para que Dirección pueda validarlo.',
            ],
            'vencido' => [
                'Seguimiento: acuerdo vencido — ' . ($tema !== '' ? $tema : $accion),
                'El siguiente acuerdo venció y sigue abierto. Registra un avance o reprograma la '
                    . 'fecha compromiso con el equipo.',
            ],
            default => [
                'Recordatorio: ' . $accion,
                'Recordatorio de acuerdo.',
            ],
        };

        $titulo = match ($tipo) {
            'previo'  => 'Recordatorio de acuerdo',
            'dia'     => 'Hoy vence un acuerdo',
            'vencido' => 'Acuerdo vencido',
            default   => 'Recordatorio de acuerdo',
        };

        $html = $this->armarHtml(
            titulo: $titulo,
            nombreDestinatario: (string) ($usuario['nombre'] ?? ''),
            intro: $intro,
            fichaAcuerdo: $this->fichaAcuerdo($acuerdo),
        );

        return ['asunto' => $asunto, 'html' => $html];
    }

    /**
     * Notificación inmediata de asignación (ADR-010): se envía al capturar el
     * acuerdo, al responsable y a cada corresponsable.
     *
     * @param array<string, mixed> $acuerdo Fila de `acuerdos` (tema, accion,
     *                                      responsable_nombre, fecha_compromiso, ...).
     * @param array<string, mixed> $usuario Fila de `usuarios` del destinatario (nombre, ...).
     * @param bool                 $esCorresponsable false = responsable del acuerdo.
     *
     * @return array{asunto: string, html: string}
     */
    public function asignacion(array $acuerdo, array $usuario, bool $esCorresponsable): array
    {
        $accion = (string) ($acuerdo['accion'] ?? '');
        $fecha  = (string) ($acuerdo['fecha_compromiso'] ?? '');

        $rol   = $esCorresponsable ? 'corresponsable' : 'responsable';
        $intro = "Se te asignó como {$rol} el siguiente acuerdo, con fecha compromiso el "
            . $this->fechaLarga($fecha)
            . '. Recibirás recordatorios automáticos conforme se acerque la fecha; puedes registrar avances en el panel desde hoy.';

        $html = $this->armarHtml(
            titulo: 'Nuevo acuerdo asignado',
            nombreDestinatario: (string) ($usuario['nombre'] ?? ''),
            intro: $intro,
            fichaAcuerdo: $this->fichaAcuerdo($acuerdo),
        );

        return ['asunto' => 'Nuevo acuerdo asignado: ' . $accion, 'html' => $html];
    }

    /**
     * Resumen periódico (RF-11): lista de acuerdos abiertos del ámbito del
     * destinatario, ordenados por fecha compromiso.
     *
     * @param array<string, mixed>               $usuario           Destinatario del resumen.
     * @param list<array<string, mixed>>          $acuerdosDelAmbito Acuerdos abiertos del ámbito.
     *
     * @return array{asunto: string, html: string}
     */
    public function resumen(array $usuario, array $acuerdosDelAmbito): array
    {
        $asunto = 'Resumen periódico: acuerdos abiertos';
        $intro  = 'Este es el resumen periódico de los acuerdos abiertos que te corresponden, '
            . 'ordenados por fecha compromiso.';

        $ordenados = $acuerdosDelAmbito;
        usort(
            $ordenados,
            static fn (array $a, array $b) => strcmp(
                (string) ($a['fecha_compromiso'] ?? ''),
                (string) ($b['fecha_compromiso'] ?? ''),
            ),
        );

        $html = $this->armarHtml(
            titulo: 'Resumen periódico de pendientes',
            nombreDestinatario: (string) ($usuario['nombre'] ?? ''),
            intro: $intro,
            fichaAcuerdo: null,
            listaAcuerdos: $ordenados,
        );

        return ['asunto' => $asunto, 'html' => $html];
    }

    /**
     * Bloque de datos de UN acuerdo (tema, acción, responsable, fecha, estado),
     * réplica del `<FilaEmail>` de `EmailModal.tsx`. Todo escapado.
     *
     * @param array<string, mixed> $acuerdo
     */
    private function fichaAcuerdo(array $acuerdo): string
    {
        $accion       = esc((string) ($acuerdo['accion'] ?? ''));
        $tema         = esc((string) ($acuerdo['tema'] ?? '') !== '' ? (string) $acuerdo['tema'] : 'Sin tema');
        $responsable  = esc((string) ($acuerdo['responsable_nombre'] ?? $acuerdo['responsable']['nombre'] ?? '…'));
        $fecha        = esc($acuerdo['fecha_compromiso'] ?? '' ? $this->fechaLarga((string) $acuerdo['fecha_compromiso']) : '—');
        $estadoInterno = (string) ($acuerdo['estado'] ?? '');
        $estadoMeta    = self::ESTADOS[$estadoInterno] ?? ['label' => '…', 'color' => '#737373'];
        $estadoLabel   = esc($estadoMeta['label']);
        $estadoColor   = esc($estadoMeta['color']);

        return <<<HTML
            <div style="background:#fafafa;border:1px solid #e5e5e5;border-radius:10px;padding:16px 18px;margin-bottom:22px;">
                <div style="display:flex;gap:10px;font-size:13px;margin-bottom:9px;">
                    <span style="width:130px;flex:none;font-weight:600;color:#737373;">Acuerdo</span>
                    <span style="font-weight:600;">{$accion}</span>
                </div>
                <div style="display:flex;gap:10px;font-size:13px;margin-bottom:9px;">
                    <span style="width:130px;flex:none;font-weight:600;color:#737373;">Tema</span>
                    <span>{$tema}</span>
                </div>
                <div style="display:flex;gap:10px;font-size:13px;margin-bottom:9px;">
                    <span style="width:130px;flex:none;font-weight:600;color:#737373;">Responsable</span>
                    <span>{$responsable}</span>
                </div>
                <div style="display:flex;gap:10px;font-size:13px;margin-bottom:9px;">
                    <span style="width:130px;flex:none;font-weight:600;color:#737373;">Fecha compromiso</span>
                    <span style="font-weight:600;">{$fecha}</span>
                </div>
                <div style="display:flex;gap:10px;font-size:13px;">
                    <span style="width:130px;flex:none;font-weight:600;color:#737373;">Estado actual</span>
                    <span style="font-weight:600;color:{$estadoColor};">{$estadoLabel}</span>
                </div>
            </div>
            HTML;
    }

    /**
     * Lista de acuerdos del resumen periódico, cada fila escapada.
     *
     * @param list<array<string, mixed>> $acuerdos
     */
    private function listaResumen(array $acuerdos): string
    {
        if ($acuerdos === []) {
            return '<p style="margin:0 0 22px;font-size:13.5px;color:#737373;">No hay acuerdos abiertos en tu ámbito.</p>';
        }

        $filas = '';
        foreach ($acuerdos as $acuerdo) {
            $accion      = esc((string) ($acuerdo['accion'] ?? ''));
            $tema        = esc((string) ($acuerdo['tema'] ?? '') !== '' ? (string) $acuerdo['tema'] : 'Sin tema');
            $responsable = esc((string) ($acuerdo['responsable_nombre'] ?? $acuerdo['responsable']['nombre'] ?? '…'));
            $fecha       = esc($acuerdo['fecha_compromiso'] ?? '' ? $this->fechaLarga((string) $acuerdo['fecha_compromiso']) : '—');
            $estadoInterno = (string) ($acuerdo['estado'] ?? '');
            $estadoMeta    = self::ESTADOS[$estadoInterno] ?? ['label' => '…', 'color' => '#737373'];
            $estadoLabel   = esc($estadoMeta['label']);
            $estadoColor   = esc($estadoMeta['color']);

            $filas .= <<<HTML
                <tr>
                    <td style="padding:10px 12px;border-bottom:1px solid #e5e5e5;font-size:13px;font-weight:600;">{$accion}</td>
                    <td style="padding:10px 12px;border-bottom:1px solid #e5e5e5;font-size:13px;">{$tema}</td>
                    <td style="padding:10px 12px;border-bottom:1px solid #e5e5e5;font-size:13px;">{$responsable}</td>
                    <td style="padding:10px 12px;border-bottom:1px solid #e5e5e5;font-size:13px;font-weight:600;">{$fecha}</td>
                    <td style="padding:10px 12px;border-bottom:1px solid #e5e5e5;font-size:13px;font-weight:600;color:{$estadoColor};">{$estadoLabel}</td>
                </tr>
                HTML;
        }

        return <<<HTML
            <table style="width:100%;border-collapse:collapse;margin-bottom:22px;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:8px 12px;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#737373;border-bottom:1px solid #e5e5e5;">Acuerdo</th>
                        <th style="text-align:left;padding:8px 12px;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#737373;border-bottom:1px solid #e5e5e5;">Tema</th>
                        <th style="text-align:left;padding:8px 12px;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#737373;border-bottom:1px solid #e5e5e5;">Responsable</th>
                        <th style="text-align:left;padding:8px 12px;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#737373;border-bottom:1px solid #e5e5e5;">Fecha compromiso</th>
                        <th style="text-align:left;padding:8px 12px;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#737373;border-bottom:1px solid #e5e5e5;">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    {$filas}
                </tbody>
            </table>
            HTML;
    }

    /**
     * Envoltura HTML común (header morado, intro, cuerpo, botón lima, footer),
     * réplica de la estructura visual de `EmailModal.tsx`. Todo el contenido
     * dinámico llega ya escapado desde los métodos que la invocan; `$titulo`
     * e `$intro` son literales fijos definidos en esta clase (no datos de
     * usuario), pero de todos modos se escapan para no depender de esa
     * garantía a futuro.
     *
     * @param list<array<string, mixed>>|null $listaAcuerdos
     */
    private function armarHtml(
        string $titulo,
        string $nombreDestinatario,
        string $intro,
        ?string $fichaAcuerdo,
        ?array $listaAcuerdos = null,
    ): string {
        $tituloEsc  = esc($titulo);
        $introEsc   = esc($intro);
        $nombreEsc  = esc($this->nombreCorto($nombreDestinatario));
        $urlPanel   = esc($this->urlPanel());
        $cuerpo     = $fichaAcuerdo ?? ($listaAcuerdos !== null ? $this->listaResumen($listaAcuerdos) : '');

        return <<<HTML
            <div style="font-family:Arial,Helvetica,sans-serif;background:#f5f5f5;padding:24px 0;">
                <div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;">
                    <div style="background:{$this->colorMorado()};padding:20px 26px;">
                        <div style="font-size:13px;font-weight:700;color:{$this->colorLima()};letter-spacing:.04em;margin-bottom:10px;">
                            Panel de acuerdos &middot; Participa Ju&aacute;rez
                        </div>
                        <div style="font-weight:600;font-size:19px;color:#ffffff;">{$tituloEsc}</div>
                    </div>
                    <div style="padding:22px 26px 26px;">
                        <p style="margin:0 0 12px;font-size:14px;color:#1a1a1a;">Hola, {$nombreEsc}:</p>
                        <p style="margin:0 0 18px;font-size:13.5px;line-height:1.65;color:#404040;">{$introEsc}</p>
                        {$cuerpo}
                        <div style="text-align:center;margin-bottom:22px;">
                            <a href="{$urlPanel}" style="display:inline-block;background:{$this->colorLima()};color:{$this->colorMorado()};font-weight:600;font-size:13.5px;padding:11px 26px;border-radius:999px;text-decoration:none;">
                                Abrir panel de seguimiento
                            </a>
                        </div>
                        <p style="margin:0;font-size:11.5px;line-height:1.6;color:#737373;text-align:center;">
                            Este correo se generó automáticamente a partir del Formato de Reunión Operativa.<br>
                            Si el acuerdo ya se cumplió, registra el avance en el panel para detener los recordatorios.
                        </p>
                    </div>
                </div>
            </div>
            HTML;
    }

    private function colorMorado(): string
    {
        return self::MORADO;
    }

    private function colorLima(): string
    {
        return self::LIMA;
    }

    /** Primer nombre, réplica de `nombreCorto()` del demo (`EstadoHelpers.ts`). */
    private function nombreCorto(string $nombre): string
    {
        $partes = explode(' ', trim($nombre));

        return $partes[0] !== '' ? $partes[0] : $nombre;
    }

    /** URL base del panel (`app.baseURL`), sin credenciales ni datos sensibles. */
    private function urlPanel(): string
    {
        $base = (string) (env('app.baseURL') ?? config('App')->baseURL ?? '');

        return rtrim($base, '/') . '/';
    }

    /**
     * "8 de julio de 2026" (fmtL del demo), en TZ America/Ciudad_Juarez, meses
     * en español. `$fechaIso` es una fecha `Y-m-d` (o con hora) ya validada por
     * el modelo; no se interpola HTML crudo aquí — solo dígitos y el nombre de
     * mes fijo de `self::MESES`.
     */
    private function fechaLarga(string $fechaIso): string
    {
        if ($fechaIso === '') {
            return '—';
        }

        try {
            $fecha = Time::parse($fechaIso, 'America/Ciudad_Juarez');
        } catch (\Exception) {
            return $fechaIso;
        }

        $dia = (int) $fecha->format('j');
        $mes = self::MESES[(int) $fecha->format('n')] ?? $fecha->format('n');
        $anio = $fecha->format('Y');

        return "{$dia} de {$mes} de {$anio}";
    }
}

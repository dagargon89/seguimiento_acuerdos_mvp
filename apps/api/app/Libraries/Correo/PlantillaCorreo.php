<?php

namespace App\Libraries\Correo;

use CodeIgniter\I18n\Time;

/**
 * Plantillas de correo del panel (S2.2), con la identidad Cívica Nocturna
 * (Propuesta C, 2026-07-13): encabezado nocturno #0B0F15 con acento teal,
 * cuerpo claro (seguro para clientes de correo) con la paleta clara del tema
 * y CTA teal sólido — espejo del `EmailModal.tsx` del frontend.
 *
 * Genera asunto + cuerpo HTML por tipo de recordatorio (`previo`/`dia`/
 * `vencido`), la asignación inmediata (ADR-010), el aviso de eliminación
 * (ADR-011), el resumen periódico (`resumen`) y la solicitud de avances
 * (`solicitud_avance`).
 *
 * Seguridad (OWASP A03): TODO contenido dinámico proveniente de datos de
 * usuario (acción, tema, responsable, estado, nombre, etc.) se escapa con
 * `esc()` antes de insertarse en el HTML. No hay HTML crudo de usuario sin
 * escapar en ningún punto de esta clase.
 */
final class PlantillaCorreo
{
    // Paleta Cívica Nocturna (tokens de apps/web/src/styles/tokens/colors.css).
    private const HEADER_BG  = '#0b0f15'; // panel de marca nocturno (sidebar/login)
    private const TEAL_BRILLO = '#2fbfa5'; // acento sobre fondo oscuro y CTA
    private const ON_TEAL    = '#06251d'; // texto sobre teal sólido
    private const TEAL_CLARO = '#0fa188'; // teal legible sobre blanco
    private const TEXTO      = '#17222b';
    private const TEXTO2     = '#51606e';
    private const MUTED      = '#7c8a99';
    private const BORDE      = '#d8e0e8';
    private const SUPERFICIE = '#f7f9fb';

    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    // Colores de estado en su variante clara (el cuerpo del correo es claro).
    private const ESTADOS = [
        'en_proceso' => ['label' => 'En proceso', 'color' => '#2e6ed9'],
        'vencido'    => ['label' => 'Vencido', 'color' => '#d9482e'],
        'concluido'  => ['label' => 'Concluido', 'color' => '#0fa188'],
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
     * Aviso de eliminación (ADR-011): Dirección borró el acuerdo; se avisa al
     * responsable y corresponsables para que no lo esperen ni lo busquen.
     *
     * @param array<string, mixed> $acuerdo Ficha del acuerdo TAL COMO ERA antes de borrarse.
     * @param array<string, mixed> $usuario Fila de `usuarios` del destinatario (nombre, ...).
     * @param bool                 $esCorresponsable false = responsable del acuerdo.
     *
     * @return array{asunto: string, html: string}
     */
    public function eliminacion(array $acuerdo, array $usuario, bool $esCorresponsable): array
    {
        $accion = (string) ($acuerdo['accion'] ?? '');

        $rol   = $esCorresponsable ? 'corresponsable' : 'responsable';
        $intro = "Dirección eliminó el siguiente acuerdo, en el que participabas como {$rol}. "
            . 'Ya no recibirás recordatorios de este compromiso y su evento salió del calendario.';

        $html = $this->armarHtml(
            titulo: 'Acuerdo eliminado',
            nombreDestinatario: (string) ($usuario['nombre'] ?? ''),
            intro: $intro,
            fichaAcuerdo: $this->fichaAcuerdo($acuerdo),
        );

        return ['asunto' => 'Acuerdo eliminado: ' . $accion, 'html' => $html];
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
     * Solicitud de avances: correo que pide a un responsable/corresponsable
     * registrar el avance de sus acuerdos abiertos (lista ordenada por fecha
     * compromiso). Envío condicionado por `solicitud_avances_activa`.
     *
     * @param array<string, mixed>      $usuario  Destinatario (responsable/corresponsable).
     * @param list<array<string, mixed>> $acuerdos Sus acuerdos abiertos.
     *
     * @return array{asunto: string, html: string}
     */
    public function solicitudAvances(array $usuario, array $acuerdos): array
    {
        $asunto = 'Solicitud de avances: registra el estado de tus acuerdos';
        $intro  = 'Te pedimos registrar el avance de los siguientes acuerdos abiertos que tienes '
            . 'asignados. Actualiza cada uno en el panel para que Dirección tenga el estado al día.';

        $ordenados = $acuerdos;
        usort(
            $ordenados,
            static fn (array $a, array $b) => strcmp(
                (string) ($a['fecha_compromiso'] ?? ''),
                (string) ($b['fecha_compromiso'] ?? ''),
            ),
        );

        $html = $this->armarHtml(
            titulo: 'Solicitud de avances',
            nombreDestinatario: (string) ($usuario['nombre'] ?? ''),
            intro: $intro,
            fichaAcuerdo: null,
            listaAcuerdos: $ordenados,
        );

        return ['asunto' => $asunto, 'html' => $html];
    }

    /**
     * Solicitud de conclusión (spec 2026-07-29, revisión de conclusión): un
     * responsable/corresponsable pidió marcar el acuerdo como concluido; se
     * avisa a Dirección y a la coordinación del área para que revise.
     *
     * @param array<string,mixed> $acuerdo
     * @param array<string,mixed> $destinatario
     * @param array<string,mixed> $solicitante
     *
     * @return array{asunto: string, html: string}
     */
    public function solicitudConclusion(array $acuerdo, array $destinatario, array $solicitante): array
    {
        $accion = (string) ($acuerdo['accion'] ?? '');
        $html   = '<p>Hola ' . esc((string) $destinatario['nombre']) . ',</p>'
            . '<p><strong>' . esc((string) $solicitante['nombre']) . '</strong> solicitó marcar como concluido el acuerdo:</p>'
            . '<blockquote>' . esc($accion) . '</blockquote>'
            . '<p>Revisa y aprueba o rechaza la solicitud en el panel.</p>';

        return ['asunto' => 'Solicitud de conclusión: ' . mb_substr($accion, 0, 60), 'html' => $html];
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
        $estadoMeta    = self::ESTADOS[$estadoInterno] ?? ['label' => '…', 'color' => '#7c8a99'];
        $estadoLabel   = esc($estadoMeta['label']);
        $estadoColor   = esc($estadoMeta['color']);

        return <<<HTML
            <div style="background:#f7f9fb;border:1px solid #d8e0e8;border-radius:12px;padding:16px 18px;margin-bottom:22px;">
                <div style="display:flex;gap:10px;font-size:13px;margin-bottom:9px;">
                    <span style="width:130px;flex:none;font-weight:600;color:#7c8a99;">Acuerdo</span>
                    <span style="font-weight:600;">{$accion}</span>
                </div>
                <div style="display:flex;gap:10px;font-size:13px;margin-bottom:9px;">
                    <span style="width:130px;flex:none;font-weight:600;color:#7c8a99;">Tema</span>
                    <span>{$tema}</span>
                </div>
                <div style="display:flex;gap:10px;font-size:13px;margin-bottom:9px;">
                    <span style="width:130px;flex:none;font-weight:600;color:#7c8a99;">Responsable</span>
                    <span>{$responsable}</span>
                </div>
                <div style="display:flex;gap:10px;font-size:13px;margin-bottom:9px;">
                    <span style="width:130px;flex:none;font-weight:600;color:#7c8a99;">Fecha compromiso</span>
                    <span style="font-weight:600;">{$fecha}</span>
                </div>
                <div style="display:flex;gap:10px;font-size:13px;">
                    <span style="width:130px;flex:none;font-weight:600;color:#7c8a99;">Estado actual</span>
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
            return '<p style="margin:0 0 22px;font-size:13.5px;color:#7c8a99;">No hay acuerdos abiertos en tu ámbito.</p>';
        }

        $filas = '';
        foreach ($acuerdos as $acuerdo) {
            $accion      = esc((string) ($acuerdo['accion'] ?? ''));
            $tema        = esc((string) ($acuerdo['tema'] ?? '') !== '' ? (string) $acuerdo['tema'] : 'Sin tema');
            $responsable = esc((string) ($acuerdo['responsable_nombre'] ?? $acuerdo['responsable']['nombre'] ?? '…'));
            $fecha       = esc($acuerdo['fecha_compromiso'] ?? '' ? $this->fechaLarga((string) $acuerdo['fecha_compromiso']) : '—');
            $estadoInterno = (string) ($acuerdo['estado'] ?? '');
            $estadoMeta    = self::ESTADOS[$estadoInterno] ?? ['label' => '…', 'color' => '#7c8a99'];
            $estadoLabel   = esc($estadoMeta['label']);
            $estadoColor   = esc($estadoMeta['color']);

            $filas .= <<<HTML
                <tr>
                    <td style="padding:10px 12px;border-bottom:1px solid #d8e0e8;font-size:13px;font-weight:600;">{$accion}</td>
                    <td style="padding:10px 12px;border-bottom:1px solid #d8e0e8;font-size:13px;">{$tema}</td>
                    <td style="padding:10px 12px;border-bottom:1px solid #d8e0e8;font-size:13px;">{$responsable}</td>
                    <td style="padding:10px 12px;border-bottom:1px solid #d8e0e8;font-size:13px;font-weight:600;">{$fecha}</td>
                    <td style="padding:10px 12px;border-bottom:1px solid #d8e0e8;font-size:13px;font-weight:600;color:{$estadoColor};">{$estadoLabel}</td>
                </tr>
                HTML;
        }

        return <<<HTML
            <table style="width:100%;border-collapse:collapse;margin-bottom:22px;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:8px 12px;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#7c8a99;border-bottom:1px solid #d8e0e8;">Acuerdo</th>
                        <th style="text-align:left;padding:8px 12px;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#7c8a99;border-bottom:1px solid #d8e0e8;">Tema</th>
                        <th style="text-align:left;padding:8px 12px;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#7c8a99;border-bottom:1px solid #d8e0e8;">Responsable</th>
                        <th style="text-align:left;padding:8px 12px;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#7c8a99;border-bottom:1px solid #d8e0e8;">Fecha compromiso</th>
                        <th style="text-align:left;padding:8px 12px;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#7c8a99;border-bottom:1px solid #d8e0e8;">Estado</th>
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

        $headerBg  = self::HEADER_BG;
        $tealAcento = self::TEAL_BRILLO;
        $onTeal    = self::ON_TEAL;
        $texto     = self::TEXTO;
        $texto2    = self::TEXTO2;
        $muted     = self::MUTED;

        return <<<HTML
            <div style="font-family:Arial,Helvetica,sans-serif;background:#f2f5f8;padding:24px 0;">
                <div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid {$this->colorBorde()};border-radius:14px;overflow:hidden;">
                    <div style="background:{$headerBg};padding:20px 26px;">
                        <div style="font-size:11px;font-weight:700;color:{$tealAcento};letter-spacing:.14em;text-transform:uppercase;margin-bottom:10px;">
                            Panel de acuerdos &middot; Participa Ju&aacute;rez
                        </div>
                        <div style="font-weight:600;font-size:19px;color:#e9ecf2;">{$tituloEsc}</div>
                    </div>
                    <div style="padding:22px 26px 26px;">
                        <p style="margin:0 0 12px;font-size:14px;color:{$texto};">Hola, {$nombreEsc}:</p>
                        <p style="margin:0 0 18px;font-size:13.5px;line-height:1.65;color:{$texto2};">{$introEsc}</p>
                        {$cuerpo}
                        <div style="text-align:center;margin-bottom:22px;">
                            <a href="{$urlPanel}" style="display:inline-block;background:{$tealAcento};color:{$onTeal};font-weight:600;font-size:13.5px;padding:11px 26px;border-radius:10px;text-decoration:none;">
                                Abrir panel de seguimiento
                            </a>
                        </div>
                        <p style="margin:0;font-size:11.5px;line-height:1.6;color:{$muted};text-align:center;">
                            Este correo se generó automáticamente a partir del Formato de Reunión Operativa.<br>
                            Si el acuerdo ya se cumplió, registra el avance en el panel para detener los recordatorios.
                        </p>
                    </div>
                </div>
            </div>
            HTML;
    }

    private function colorBorde(): string
    {
        return self::BORDE;
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

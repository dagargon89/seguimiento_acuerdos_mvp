<?php

namespace App\Libraries\Correo;

/**
 * Envío de correo transaccional (RF-08.4). El job de recordatorios
 * (`recordatorios:procesar`) depende de esta interfaz, no de la implementación
 * concreta, para poder probarse con dobles SIN credenciales de Google.
 *
 * La implementación real `GmailMailer` (Gmail API) llega en S2.2; por defecto
 * `Config\Services::mailer()` resuelve a `NoopMailer` mientras esas credenciales
 * no estén configuradas.
 */
interface Mailer
{
    /**
     * Envía un correo HTML y devuelve el id del mensaje generado por el
     * proveedor (p.ej. el `id` del recurso de Gmail). Lanza una excepción si
     * el envío falla — el job la captura por destinatario y marca `fallido`
     * SIN abortar la corrida (RE-07).
     *
     * @throws \RuntimeException en fallo de envío
     */
    public function enviar(string $para, string $asunto, string $html): string;
}

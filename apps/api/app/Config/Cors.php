<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Cross-Origin Resource Sharing (CORS) Configuration
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
 */
class Cors extends BaseConfig
{
    /**
     * The default CORS configuration.
     *
     * @var array{
     *      allowedOrigins: list<string>,
     *      allowedOriginsPatterns: list<string>,
     *      supportsCredentials: bool,
     *      allowedHeaders: list<string>,
     *      exposedHeaders: list<string>,
     *      allowedMethods: list<string>,
     *      maxAge: int,
     *  }
     */
    public array $default = [
        /**
         * Origins for the `Access-Control-Allow-Origin` header.
         *
         * Se deja vacío a propósito: la lista blanca real se puebla en
         * allowedOriginsPatterns (ver __construct) para evitar un caso especial
         * del filtro Cors nativo con exactamente 1 origen — ver ese comentario.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Origin
         *
         * E.g.:
         *   - ['http://localhost:8080']
         *   - ['https://www.example.com']
         */
        'allowedOrigins' => [],

        /**
         * Origin regex patterns for the `Access-Control-Allow-Origin` header.
         *
         * Lista blanca real (CORS_ALLOWED_ORIGINS en .env, coma-separado; dev:
         * http://localhost:5173), poblada en __construct con cada origen
         * escapado como patrón literal exacto. Sin wildcard '*' (doc 04 §A05):
         * un origen no listado no recibe ningún header Access-Control-*.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Origin
         *
         * NOTE: A pattern specified here is part of a regular expression. It will
         *       be actually `#\A<pattern>\z#`.
         *
         * E.g.:
         *   - ['https://\w+\.example\.com']
         */
        'allowedOriginsPatterns' => [],

        /**
         * Weather to send the `Access-Control-Allow-Credentials` header.
         *
         * The Access-Control-Allow-Credentials response header tells browsers whether
         * the server allows cross-origin HTTP requests to include credentials.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Credentials
         */
        'supportsCredentials' => false,

        /**
         * Set headers to allow.
         *
         * The Access-Control-Allow-Headers response header is used in response to
         * a preflight request which includes the Access-Control-Request-Headers to
         * indicate which HTTP headers can be used during the actual request.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Headers
         */
        'allowedHeaders' => ['Authorization', 'Content-Type'],

        /**
         * Set headers to expose.
         *
         * The Access-Control-Expose-Headers response header allows a server to
         * indicate which response headers should be made available to scripts running
         * in the browser, in response to a cross-origin request.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Expose-Headers
         */
        'exposedHeaders' => [],

        /**
         * Set methods to allow.
         *
         * The Access-Control-Allow-Methods response header specifies one or more
         * methods allowed when accessing a resource in response to a preflight
         * request.
         *
         * E.g.:
         *   - ['GET', 'POST', 'PUT', 'DELETE']
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Methods
         */
        'allowedMethods' => ['GET', 'POST', 'PATCH', 'PUT', 'OPTIONS'],

        /**
         * Set how many seconds the results of a preflight request can be cached.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Max-Age
         */
        'maxAge' => 7200,
    ];

    public function __construct()
    {
        parent::__construct();

        // CORS_ALLOWED_ORIGINS en .env, coma-separado (dev: http://localhost:5173).
        // Sin wildcard '*' (doc 04 §A05): un origen no listado no recibe ningún
        // header Access-Control-*.
        //
        // Se usa allowedOriginsPatterns (no allowedOrigins) a propósito: el
        // filtro Cors nativo de CI4 4.7 (system/HTTP/Cors.php::setAllowOrigin)
        // tiene un caso especial cuando allowedOrigins tiene EXACTAMENTE 1
        // elemento — refleja ese origen SIEMPRE, sin comparar contra el header
        // `Origin` real de la request entrante. Con un solo dominio de SPA en
        // dev (http://localhost:5173) eso violaría OW-04 (un origen no listado
        // no debe recibir Access-Control-Allow-Origin). La rama de
        // allowedOriginsPatterns sí compara siempre con preg_match, sin ese
        // atajo, así que se usa aquí con cada origen escapado como patrón
        // literal exacto.
        $origenes = (string) (env('CORS_ALLOWED_ORIGINS') ?? 'http://localhost:5173');

        $this->default['allowedOriginsPatterns'] = array_values(array_filter(array_map(
            static fn (string $origen): string => preg_quote(trim($origen), '#'),
            explode(',', $origenes),
        )));
    }
}

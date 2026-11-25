<?php

namespace App\Services;

/*
|--------------------------------------------------------------------------
| Envío de correo por SMTP
|--------------------------------------------------------------------------
|
| Cliente SMTP mínimo (RFC 5321) para el ambiente local. Se conecta al
| servidor indicado en MAIL_HOST / MAIL_PORT y envía el mensaje en texto
| plano UTF-8. Admite STARTTLS y autenticación AUTH LOGIN cuando se
| configuran MAIL_ENCRYPTION=tls y MAIL_USERNAME / MAIL_PASSWORD.
|
| En la demostración se usa Mailpit (localhost:1025), un servidor de correo
| de prueba que recibe los mensajes y los muestra en http://localhost:8025.
|
*/
class SmtpCorreoService
{
    private $socket;

    public function enviar(string $para, string $asunto, string $cuerpo): void
    {
        $host = (string) env('MAIL_HOST', '127.0.0.1');
        $port = (int) env('MAIL_PORT', 1025);
        $from = (string) env('MAIL_FROM_ADDRESS', 'portal@proveedores.local');
        $fromName = (string) env('MAIL_FROM_NAME', 'Portal de Proveedores');
        $timeout = max(1, (int) env('MAIL_TIMEOUT', 5));

        $this->socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);

        if (!$this->socket) {
            throw new \RuntimeException("No fue posible conectar con el servidor SMTP {$host}:{$port}: {$errstr}");
        }

        stream_set_timeout($this->socket, $timeout);

        try {
            $this->esperar(220);
            $this->comando('EHLO portal-proveedores.local', 250);

            if (strtolower((string) env('MAIL_ENCRYPTION', '')) === 'tls') {
                $this->comando('STARTTLS', 220);

                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('No fue posible iniciar TLS con el servidor SMTP.');
                }

                $this->comando('EHLO portal-proveedores.local', 250);
            }

            $usuario = (string) env('MAIL_USERNAME', '');

            if ($usuario !== '') {
                $this->comando('AUTH LOGIN', 334);
                $this->comando(base64_encode($usuario), 334);
                $this->comando(base64_encode((string) env('MAIL_PASSWORD', '')), 235);
            }

            $this->comando('MAIL FROM:<' . $from . '>', 250);
            $this->comando('RCPT TO:<' . $para . '>', [250, 251]);
            $this->comando('DATA', 354);

            $mensaje = implode("\r\n", [
                'Date: ' . date('r'),
                'From: ' . $this->encabezado($fromName) . ' <' . $from . '>',
                'To: <' . $para . '>',
                'Subject: ' . $this->encabezado($asunto),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@portal-proveedores.local>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                '',
                rtrim(chunk_split(base64_encode($cuerpo), 76, "\r\n")),
            ]);

            fwrite($this->socket, $mensaje . "\r\n.\r\n");
            $this->esperar(250);
            $this->comando('QUIT', 221);
        } finally {
            fclose($this->socket);
        }
    }

    private function comando(string $linea, $esperado): string
    {
        fwrite($this->socket, $linea . "\r\n");

        return $this->esperar($esperado);
    }

    /**
     * Lee la respuesta (incluidas las de varias líneas) y valida el código.
     */
    private function esperar($esperado): string
    {
        $respuesta = '';

        while (($linea = fgets($this->socket, 515)) !== false) {
            $respuesta .= $linea;

            if (strlen($linea) < 4 || $linea[3] === ' ') {
                break;
            }
        }

        $codigo = (int) substr($respuesta, 0, 3);

        if (!in_array($codigo, (array) $esperado, true)) {
            throw new \RuntimeException('El servidor SMTP respondió: ' . trim($respuesta));
        }

        return $respuesta;
    }

    private function encabezado(string $texto): string
    {
        return '=?UTF-8?B?' . base64_encode($texto) . '?=';
    }
}

<?php

declare(strict_types=1);

namespace FortKnox\IRC;

use RuntimeException;

final class IrcClient
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly bool $tls = true,
        private readonly float $timeout = 10.0,
    ) {
    }

    public function connect(): void
    {
        if ($this->socket !== null) {
            throw new RuntimeException(
                'IRC client is already connected.'
            );
        }

        $transport = $this->tls ? 'tls' : 'tcp';

        $address = sprintf(
            '%s://%s:%d',
            $transport,
            $this->host,
            $this->port
        );

        $errno = 0;
        $error = '';

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'SNI_enabled' => true,
                'peer_name' => $this->host,
            ],
        ]);

        $socket = @stream_socket_client(
            $address,
            $errno,
            $error,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new RuntimeException(
                sprintf(
                    'Unable to connect to IRC server: %s (%d)',
                    $error,
                    $errno
                )
            );
        }

        stream_set_blocking($socket, false);

        $this->socket = $socket;
    }

    public function send(string $command): void
    {
        $this->assertConnected();

        $command = rtrim($command, "\r\n") . "\r\n";

        $length = strlen($command);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite(
                $this->socket,
                substr($command, $offset)
            );

            if ($written === false) {
                throw new RuntimeException(
                    'Unable to write to IRC server.'
                );
            }

            if ($written === 0) {
                throw new RuntimeException(
                    'IRC socket write returned zero bytes.'
                );
            }

            $offset += $written;
        }

        $this->logOutgoing($command);
    }

    public function readLine(): ?string
    {
        $this->assertConnected();

        $read = [$this->socket];
        $write = null;
        $except = null;

        $changed = @stream_select(
            $read,
            $write,
            $except,
            1
        );

        if ($changed === false) {
            throw new RuntimeException(
                'Unable to monitor IRC socket.'
            );
        }

        if ($changed === 0) {
            return '';
        }

        $line = @fgets($this->socket);

        if ($line === false) {
            if (feof($this->socket)) {
                return null;
            }

            return '';
        }

        return rtrim($line, "\r\n");
    }

    public function disconnect(): void
    {
        if ($this->socket === null) {
            return;
        }

        @fclose($this->socket);

        $this->socket = null;
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    private function logOutgoing(string $command): void
    {
        $trimmed = rtrim($command);

        if (
            preg_match('/^PASS\s+/i', $trimmed) === 1
            || preg_match(
                '/^PRIVMSG\s+\S+\s+:\s*IDENTIFY\s+/i',
                $trimmed
            ) === 1
        ) {
            echo '> [REDACTED]' . PHP_EOL;
            return;
        }

        echo '> ' . $trimmed . PHP_EOL;
    }

    private function assertConnected(): void
    {
        if ($this->socket === null) {
            throw new RuntimeException(
                'IRC client is not connected.'
            );
        }
    }
}

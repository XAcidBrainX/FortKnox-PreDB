<?php

declare(strict_types=1);

namespace FortKnox\IRC;

use RuntimeException;

final class IrcSession
{
    private bool $running = false;

    public function __construct(
        private readonly IrcClient $client,
        private readonly IrcProtocolParser $parser,
        private readonly AnnouncementHandler $announcementHandler,
        private readonly string $nickname,
        private readonly string $username,
        private readonly string $realname,
        private readonly array $channels = [],
        private readonly ?string $password = null,
        private readonly ?string $nickServPassword = null,
        private readonly ?string $nickServService = 'NickServ',
    ) {
    }

    public function run(): void
    {
        $this->running = true;

        $this->installSignalHandlers();

        while ($this->running) {
            try {
                $this->runConnection();
            } catch (\Throwable $exception) {
                if (!$this->running) {
                    break;
                }

                fwrite(
                    STDERR,
                    '[IRC] Connection error: ' .
                    $exception->getMessage() .
                    PHP_EOL
                );

                $this->client->disconnect();

                $this->sleepBeforeReconnect();
            }
        }

        $this->client->disconnect();

        echo '[IRC] Session stopped.' . PHP_EOL;
    }

    public function stop(): void
    {
        $this->running = false;
        $this->client->disconnect();
    }

    private function runConnection(): void
    {
        echo '[IRC] Connecting...' . PHP_EOL;

        $this->client->connect();

        echo '[IRC] Connected.' . PHP_EOL;

        $this->register();

        while ($this->running) {
            $line = $this->client->readLine();

            if ($line === null) {
                throw new RuntimeException(
                    'IRC connection closed by remote server.'
                );
            }

            if ($line === '') {
                $this->dispatchSignals();
                continue;
            }

            $this->handleLine($line);

            $this->dispatchSignals();
        }
    }

    private function register(): void
    {
        if (
            $this->password !== null
            && $this->password !== ''
        ) {
            $this->send(
                'PASS ' . $this->password
            );
        }

        $this->send(
            'NICK ' . $this->nickname
        );

        $this->send(
            sprintf(
                'USER %s 0 * :%s',
                $this->username,
                $this->realname
            )
        );
    }

    private function handleLine(string $line): void
    {
        echo '< ' . $line . PHP_EOL;

        /*
         * IRC keepalive.
         */
        if (str_starts_with($line, 'PING ')) {
            $payload = substr($line, 5);

            $this->send(
                'PONG ' . $payload
            );

            echo '> PONG ' . $payload . PHP_EOL;

            return;
        }

        /*
         * Registration completed.
         */
        if ($this->isNumeric($line, '001')) {
            $this->authenticateNickServ();
            $this->joinChannels();

            return;
        }

        /*
         * Server reported an IRC error.
         */
        if (preg_match('/^:\S+\s+ERROR\s+/i', $line) === 1) {
            throw new RuntimeException(
                'IRC server returned ERROR.'
            );
        }

        /*
         * Parse PRIVMSG.
         */
        $message = $this->parser->parse($line);

        if ($message === null) {
            return;
        }

        /*
         * Only configured channels are processed.
         */
        if (!$this->isAllowedChannel($message->channel())) {
            echo '> Ignored channel: ' .
                $message->channel() .
                PHP_EOL;

            return;
        }

        /*
         * Send announcement into PreDB pipeline.
         */
        $result = $this->announcementHandler->handle(
            $message
        );

        if ($result === null) {
            echo '> Announcement ignored.' . PHP_EOL;

            return;
        }

        if ($result->imported()) {
            echo '> IMPORTED #' .
                $result->releaseId() .
                ' ' .
                $result->release()->releaseName() .
                PHP_EOL;

            return;
        }

        echo '> DUPE #' .
            $result->releaseId() .
            ' ' .
            $result->release()->releaseName() .
            PHP_EOL;
    }

    private function authenticateNickServ(): void
    {
        if (
            $this->nickServPassword === null
            || $this->nickServPassword === ''
        ) {
            return;
        }

        $service = $this->nickServService ?: 'NickServ';

        $this->send(
            sprintf(
                'PRIVMSG %s :IDENTIFY %s',
                $service,
                $this->nickServPassword
            )
        );

        echo '> NickServ IDENTIFY sent.' . PHP_EOL;
    }

    private function joinChannels(): void
    {
        foreach ($this->channels as $channel) {
            $channel = trim($channel);

            if ($channel === '') {
                continue;
            }

            $this->send(
                'JOIN ' . $channel
            );

            echo '> JOIN ' . $channel . PHP_EOL;
        }
    }

    private function isAllowedChannel(
        string $channel
    ): bool {
        foreach ($this->channels as $allowed) {
            if (
                strcasecmp(
                    trim($allowed),
                    trim($channel)
                ) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    private function send(string $command): void
    {
        $this->client->send($command);
    }

    private function sleepBeforeReconnect(): void
    {
        static $attempt = 0;

        $attempt++;

        /*
         * Exponential backoff:
         *
         * 2, 4, 8, 16, 32, 60 seconds.
         */
        $delay = min(
            60,
            2 ** min($attempt, 5)
        );

        echo sprintf(
            '[IRC] Reconnecting in %d seconds...' .
            PHP_EOL,
            $delay
        );

        for (
            $i = 0;
            $i < $delay && $this->running;
            $i++
        ) {
            sleep(1);
            $this->dispatchSignals();
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(
            SIGTERM,
            function (): void {
                echo '[IRC] SIGTERM received.' .
                    PHP_EOL;

                $this->stop();
            }
        );

        pcntl_signal(
            SIGINT,
            function (): void {
                echo '[IRC] SIGINT received.' .
                    PHP_EOL;

                $this->stop();
            }
        );
    }

    private function dispatchSignals(): void
    {
        if (
            function_exists(
                'pcntl_signal_dispatch'
            )
        ) {
            pcntl_signal_dispatch();
        }
    }

    private function isNumeric(
        string $line,
        string $numeric
    ): bool {
        return preg_match(
            '/^:\S+\s+' .
            preg_quote($numeric, '/') .
            '\s/',
            $line
        ) === 1;
    }
}

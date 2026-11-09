<?php

declare(strict_types=1);

namespace FortKnox\IRC;

use RuntimeException;

final class IrcSession
{
    private bool $running = false;
    private bool $identified = false;

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
        private array $channelKeys = [],
        private readonly ?FiSHKeyStore $fishKeyStore = null,
        private readonly ?string $fishPeerNick = null,
        private readonly string $fishDh1080Channel = '#predb',
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
        $this->identified = false;

        $this->client->connect();

        echo '[IRC] Connected.' . PHP_EOL;

        $this->register();

        $lastKeepAlive = time();

        while ($this->running) {
            if (time() - $lastKeepAlive > 60) {
                try {
                    $this->client->send("PING fortknox-keepalive");
                } catch (\Throwable $e) {
                    // Ignore, readLine will catch disconnection
                }
                $lastKeepAlive = time();
            }

            $line = $this->client->readLine();

            if ($line === null) {
                throw new RuntimeException(
                    'IRC connection closed by remote server.'
                );
            }

            if ($line === '') {
                $this->processOutbox();
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
        // Auto-Nuke Channel Parser
        if (preg_match('/^:(\S+?)!\S+\s+PRIVMSG\s+(#\S+)\s+:(.+)$/i', $line, $m)) {
            $sender = $m[1];
            $channel = strtolower($m[2]);
            $text = $m[3];

            // Eigene Nachrichten ignorieren
            if ($sender !== $this->nickname && $channel === '#predb') {
                // IRC Farbcodes/Steuerzeichen entfernen
                $clean = preg_replace('/[(?:\d{1,2}(?:,\d{1,2})?)?]/', '', $text);

                // Format: [NUKE] » » » [ RELEASE ] - [REASON]
                if (preg_match('/\[NUKE\]\s*(?:[^\w\[]+)?\s*\[\s*([A-Za-z0-9._-]+)\s*\]\s*-\s*\[\s*([^\]]+)\s*\]/i', $clean, $hit)) {
                    $nukedRelease = trim($hit[1]);
                    $reason = trim($hit[2]);
                    echo "[AUTO-NUKE] Erkannt: Release={$nukedRelease}, Grund={$reason} (von {$sender})\n";

                    try {
                        $env = parse_ini_file(dirname(__DIR__, 2) . '/.env') ?: [];
                        $pdo = new \PDO('mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['DB_PORT'] ?? 3306) . ';dbname=' . ($env['DB_DATABASE'] ?? 'fortknox') . ';charset=utf8mb4', $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '');
                        $repo = new \FortKnox\PreDB\ReleaseRepository($pdo);
                        $done = $repo->nukeReleaseByName($nukedRelease, $reason, $sender);
                        if ($done) {
                            echo "[AUTO-NUKE] Release {$nukedRelease} erfolgreich auf NUKED gesetzt.\n";
                        } else {
                            echo "[AUTO-NUKE] Release {$nukedRelease} nicht in lokaler DB gefunden.\n";
                        }
                    } catch (\Throwable $e) {
                        echo "[AUTO-NUKE Fehler] " . $e->getMessage() . "\n";
                    }
                }
            }
        }

        // Nickname Collision Handling (433)
        if (preg_match('/ 433 \* /i', $line)) {
            echo "[IRC] Nickname collision detected - taking alt nick for registration.\n";
            $this->client->send("NICK " . $this->nickname . "_");
            return;
        }

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
            echo "[IRC] 001 Welcome received. Reclaiming nick and identifying.\n";
            $nsPass = $this->nickServPassword ?: $this->password;
            if ($nsPass !== null && $nsPass !== '') {
                $service = $this->nickServService ?: 'NickServ';
                $this->client->send("PRIVMSG {$service} :RECOVER " . $this->nickname . " " . $nsPass);
                sleep(2);
                $this->client->send("NICK " . $this->nickname);
                sleep(1);
                $this->client->send("PRIVMSG {$service} :IDENTIFY " . $nsPass);
                sleep(1);
            } else {
                $this->authenticateNickServ();
            }
            $this->joinChannels();
            $this->identified = true;
            echo '[IRC] Registration complete; outbox enabled for network ' . $this->nickname . PHP_EOL;
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

        if ($message->command() === 'NOTICE') {
            $this->handleFiSHNotice($message);
            return;
        }

        if ($message->command() !== 'PRIVMSG') {
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

        // Decrypt FiSH 10 channel messages before they enter the PreDB parser.
        $incomingText = $message->text();
        if (str_starts_with($incomingText, '+OK ')) {
            $fishKey = $this->channelKeys[strtolower($message->channel())] ?? null;
            if (!is_string($fishKey) || $fishKey === '') {
                echo '> Encrypted message ignored: no FiSH key configured for ' . $message->channel() . PHP_EOL;
                return;
            }
            try {
                $decrypted = FiSH::decrypt($incomingText, $fishKey);
            } catch (\Throwable) {
                $decrypted = null;
            }
            if ($decrypted === null || $decrypted === '') {
                echo '> Encrypted message ignored: FiSH decryption failed for ' . $message->channel() . PHP_EOL;
                return;
            }
            $message = new IrcMessage($message->sender(), $message->channel(), $decrypted);
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

        echo '> RELEASE PROCESSED' . PHP_EOL;
        return;
    }


    private function handleFiSHNotice(IrcMessage $message): void
    {
        if (strcasecmp($message->channel(), $this->nickname) !== 0) {
            return;
        }

        if (!preg_match('/^DH1080_INIT\s+([A-Za-z0-9+\/]{180}A)\s+CBC$/i', trim($message->text()), $match)) {
            return;
        }

        if ($this->fishPeerNick === null || $this->fishPeerNick === '') {
            echo '[FiSH] DH1080 request ignored: IRC_FISH_PEER_NICK is not configured.' . PHP_EOL;
            return;
        }

        if (strcasecmp($message->sender(), $this->fishPeerNick) !== 0) {
            echo '[FiSH] DH1080 request ignored from an unapproved nick.' . PHP_EOL;
            return;
        }

        if ($this->fishKeyStore === null || !$this->isAllowedChannel($this->fishDh1080Channel)) {
            echo '[FiSH] DH1080 request ignored: channel key storage or target channel is unavailable.' . PHP_EOL;
            return;
        }

        try {
            $local = FiSHDh1080::generate();
            $sharedSecret = FiSHDh1080::compute($local['private'], $match[1]);
            $channelKey = 'cbc:' . $sharedSecret;

            // Persist before replying: mIRC only installs the key after FINISH.
            $this->fishKeyStore->store($this->fishDh1080Channel, $channelKey);
            $this->channelKeys[strtolower($this->fishDh1080Channel)] = $channelKey;

            $this->send('NOTICE ' . $message->sender() . ' :DH1080_FINISH ' . $local['public'] . ' CBC');
            echo '[FiSH] DH1080 CBC key exchange completed and channel key stored for '
                . $this->fishDh1080Channel . ' (peer ' . $message->sender() . ').' . PHP_EOL;
        } catch (\Throwable $exception) {
            echo '[FiSH] DH1080 key exchange failed: ' . $exception->getMessage() . PHP_EOL;
        }
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
    private function processOutbox(): void
    {
        if (!$this->identified) {
            return;
        }

        static $pdo = null;

        try {
            if ($pdo === null) {
                $env = parse_ini_file(dirname(__DIR__, 2) . '/.env');
                if ($env === false) {
                    throw new RuntimeException('Unable to read .env for IRC outbox.');
                }
                $pdo = new \PDO(
                    'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1')
                    . ';port=' . ($env['DB_PORT'] ?? 3306)
                    . ';dbname=' . ($env['DB_DATABASE'] ?? 'fortknox')
                    . ';charset=utf8mb4',
                    $env['DB_USERNAME'] ?? 'root',
                    $env['DB_PASSWORD'] ?? '',
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
            }

            $stmt = $pdo->prepare("SELECT id, network_id, channel, message FROM irc_outbox WHERE status = 'pending' AND created_at >= NOW() - INTERVAL 10 MINUTE AND network_id = (SELECT id FROM irc_networks WHERE nick = ? LIMIT 1) ORDER BY id ASC LIMIT 5");
            $stmt->execute([$this->nickname]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $update = $pdo->prepare("UPDATE irc_outbox SET status = 'sent', sent_at = NOW() WHERE id = ? AND network_id = ? AND status = 'pending'");
            foreach ($rows as $row) {
                $outgoing = (string) $row['message'];
                $fishKey = $this->channelKeys[strtolower((string) $row['channel'])] ?? null;
                if (is_string($fishKey) && $fishKey !== '') {
                    $outgoing = FiSH::encrypt($outgoing, $fishKey);
                }
                $this->client->send('PRIVMSG ' . $row['channel'] . ' :' . $outgoing);
                $update->execute([$row['id'], $row['network_id']]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Outbox row ' . $row['id'] . ' sent but status update failed.');
                }
                usleep(250000); // Flood protection
            }
        } catch (\Throwable $exception) {
            // A MariaDB restart invalidates this long-lived PDO handle.
            // Drop it so the next outbox poll establishes a fresh connection.
            $pdo = null;

            // Avoid flooding the IRC log while the database is unavailable.
            static $lastOutboxErrorAt = 0;
            $now = time();
            if ($now - $lastOutboxErrorAt >= 30) {
                fwrite(STDERR, '[IRC] Outbox temporarily unavailable; database connection will be retried: '
                    . $exception->getMessage() . PHP_EOL);
                $lastOutboxErrorAt = $now;
            }
        }
    }
}

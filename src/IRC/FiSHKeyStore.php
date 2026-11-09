<?php

declare(strict_types=1);

namespace FortKnox\IRC;

use PDO;

final class FiSHKeyStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FiSHKeyVault $vault,
        private readonly int $networkId,
    ) {
    }

    public function store(string $channel, string $key): void
    {
        $channel = strtolower(trim($channel));
        $stmt = $this->pdo->prepare(
            'INSERT INTO irc_channel_keys (network_id, channel, encrypted_key) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE encrypted_key = VALUES(encrypted_key), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$this->networkId, $channel, $this->vault->encrypt($key)]);
    }
}

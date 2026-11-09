CREATE TABLE IF NOT EXISTS irc_channel_keys (
    network_id INT(11) NOT NULL,
    channel VARCHAR(128) NOT NULL,
    encrypted_key TEXT NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (network_id, channel),
    CONSTRAINT fk_irc_channel_keys_network
        FOREIGN KEY (network_id) REFERENCES irc_networks(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

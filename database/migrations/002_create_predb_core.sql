CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(64) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS release_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(128) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_release_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS releases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    release_name VARCHAR(255) NOT NULL,
    category_id BIGINT UNSIGNED DEFAULT NULL,
    group_id BIGINT UNSIGNED DEFAULT NULL,

    size_bytes BIGINT UNSIGNED DEFAULT NULL,
    source VARCHAR(64) DEFAULT NULL,

    status ENUM(
        'normal',
        'nuked',
        'unnuked',
        'deleted'
    ) NOT NULL DEFAULT 'normal',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_releases_name (release_name),

    KEY idx_releases_category (category_id),
    KEY idx_releases_group (group_id),
    KEY idx_releases_status (status),
    KEY idx_releases_created (created_at),

    CONSTRAINT fk_releases_category
        FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_releases_group
        FOREIGN KEY (group_id)
        REFERENCES release_groups(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS release_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    release_id BIGINT UNSIGNED NOT NULL,

    event_type ENUM(
        'announce',
        'dupe',
        'nuke',
        'unnuke',
        'update'
    ) NOT NULL,

    message VARCHAR(500) DEFAULT NULL,
    source VARCHAR(64) DEFAULT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_release_events_release (release_id),
    KEY idx_release_events_type (event_type),
    KEY idx_release_events_created (created_at),

    CONSTRAINT fk_release_events_release
        FOREIGN KEY (release_id)
        REFERENCES releases(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

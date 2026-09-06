ALTER TABLE releases
    ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER category,
    ADD COLUMN season SMALLINT UNSIGNED NULL AFTER group_id,
    ADD COLUMN episode SMALLINT UNSIGNED NULL AFTER season,
    ADD COLUMN resolution VARCHAR(32) NULL AFTER episode,
    ADD KEY idx_releases_group_id (group_id),
    ADD KEY idx_releases_season_episode (season, episode),
    ADD KEY idx_releases_resolution (resolution),
    ADD CONSTRAINT fk_releases_group
        FOREIGN KEY (group_id)
        REFERENCES release_groups (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE;

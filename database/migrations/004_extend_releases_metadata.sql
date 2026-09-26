ALTER TABLE releases
    ADD COLUMN title VARCHAR(255) NULL AFTER name,
    ADD COLUMN year SMALLINT UNSIGNED NULL AFTER category,
    ADD COLUMN language VARCHAR(32) NULL AFTER resolution,
    ADD COLUMN codec VARCHAR(32) NULL AFTER language,
    ADD KEY idx_releases_title (title),
    ADD KEY idx_releases_year (year),
    ADD KEY idx_releases_language (language),
    ADD KEY idx_releases_codec (codec);

CREATE TABLE IF NOT EXISTS daily_visitors (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 visit_date DATE NOT NULL,
 visitor_hash CHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uq_daily_visitors_date_hash (visit_date, visitor_hash),
 KEY idx_daily_visitors_date (visit_date)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

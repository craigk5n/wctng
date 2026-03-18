-- Additional performance indexes for query optimization (P9-E1-S3)
-- Run via: php bin/console tenant:migrate

-- Composite index for user+date+time queries (sorted event lists)
CREATE INDEX IF NOT EXISTS idx_entry_user_date_time ON webcal_entry (cal_create_by, cal_date, cal_time);

-- Access level index for public event filters (SEO, sitemap, public calendar)
CREATE INDEX IF NOT EXISTS idx_entry_access ON webcal_entry (cal_access);

-- Modification date index for "recently modified" queries (dashboard, feeds)
CREATE INDEX IF NOT EXISTS idx_entry_mod_date ON webcal_entry (cal_mod_date);

-- Reverse composite for participant-first lookups (reminder service, notification service)
CREATE INDEX IF NOT EXISTS idx_entry_user_login ON webcal_entry_user (cal_login, cal_id);

-- Repeats table index for EXISTS subquery in findByDateRange
CREATE INDEX IF NOT EXISTS idx_entry_repeats_cal ON webcal_entry_repeats (cal_id);

-- Repeats-not (exceptions) index for batch loading
CREATE INDEX IF NOT EXISTS idx_entry_repeats_not_cal ON webcal_entry_repeats_not (cal_id);

-- User preferences index for preference lookups
CREATE INDEX IF NOT EXISTS idx_user_pref_login ON webcal_user_pref (cal_login);

-- Config settings index for setting lookups
CREATE INDEX IF NOT EXISTS idx_config_name ON webcal_config (cal_setting);

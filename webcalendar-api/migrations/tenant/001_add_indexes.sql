-- Performance indexes for webcalendar-core tables
-- Run via: php bin/console tenant:migrate

CREATE INDEX IF NOT EXISTS idx_entry_date ON webcal_entry (cal_date);
CREATE INDEX IF NOT EXISTS idx_entry_create_by ON webcal_entry (cal_create_by);
CREATE INDEX IF NOT EXISTS idx_entry_type ON webcal_entry (cal_type);
CREATE INDEX IF NOT EXISTS idx_entry_date_user ON webcal_entry (cal_create_by, cal_date);
CREATE INDEX IF NOT EXISTS idx_entry_user_cal ON webcal_entry_user (cal_id, cal_login);
CREATE INDEX IF NOT EXISTS idx_entry_categories_cal ON webcal_entry_categories (cal_id);
CREATE INDEX IF NOT EXISTS idx_entry_categories_cat ON webcal_entry_categories (cat_id);
CREATE INDEX IF NOT EXISTS idx_user_layers_login ON webcal_user_layers (cal_login);
CREATE INDEX IF NOT EXISTS idx_group_user_group ON webcal_group_user (cal_group_id)

-- Performance optimization indexes (P11-E1-S2)
-- Apply via: docker exec -i <mysql> mysql -u webcalendar -pwebcalendar_dev webcalendar < migrations/003_performance_optimization.sql
-- Safe to re-run: uses stored procedure to skip existing indexes

DELIMITER //
DROP PROCEDURE IF EXISTS add_index_if_not_exists//
CREATE PROCEDURE add_index_if_not_exists(IN tbl VARCHAR(64), IN idx VARCHAR(64), IN cols VARCHAR(255))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = tbl AND index_name = idx) THEN
    SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD INDEX ', idx, ' (', cols, ')');
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END//
DELIMITER ;

-- Core date range queries
CALL add_index_if_not_exists('webcal_entry', 'idx_entry_date', 'cal_date');
CALL add_index_if_not_exists('webcal_entry', 'idx_entry_create_by', 'cal_create_by');
CALL add_index_if_not_exists('webcal_entry', 'idx_entry_user_date', 'cal_create_by, cal_date');
CALL add_index_if_not_exists('webcal_entry', 'idx_entry_user_date_time', 'cal_create_by, cal_date, cal_time');
CALL add_index_if_not_exists('webcal_entry', 'idx_entry_access', 'cal_access');
CALL add_index_if_not_exists('webcal_entry', 'idx_entry_mod_date', 'cal_mod_date');

-- Recurrence subquery
CALL add_index_if_not_exists('webcal_entry_repeats', 'idx_entry_repeats_cal', 'cal_id');
CALL add_index_if_not_exists('webcal_entry_repeats_not', 'idx_entry_repeats_not_cal', 'cal_id');

-- Category lookups
CALL add_index_if_not_exists('webcal_entry_categories', 'idx_entry_categories_cal', 'cal_id');
CALL add_index_if_not_exists('webcal_entry_categories', 'idx_entry_categories_cat', 'cat_id');

-- User/participant lookups
CALL add_index_if_not_exists('webcal_entry_user', 'idx_entry_user_cal', 'cal_id, cal_login');
CALL add_index_if_not_exists('webcal_entry_user', 'idx_entry_user_login', 'cal_login, cal_id');

-- Preference and config lookups
CALL add_index_if_not_exists('webcal_user_pref', 'idx_user_pref_login', 'cal_login');
CALL add_index_if_not_exists('webcal_config', 'idx_config_name', 'cal_setting');

-- Layer lookups
CALL add_index_if_not_exists('webcal_user_layers', 'idx_user_layers_login', 'cal_login');

-- FULLTEXT index for search (requires separate handling)
SELECT COUNT(*) INTO @ft_exists FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'webcal_entry' AND index_name = 'idx_entry_fulltext';
SET @ft_sql = IF(@ft_exists = 0, 'ALTER TABLE webcal_entry ADD FULLTEXT INDEX idx_entry_fulltext (cal_name, cal_description)', 'SELECT 1');
PREPARE ft_stmt FROM @ft_sql;
EXECUTE ft_stmt;
DEALLOCATE PREPARE ft_stmt;

-- Cleanup
DROP PROCEDURE IF EXISTS add_index_if_not_exists;

-- Add cal_image column to webcal_entry (required by webcalendar-core >= 4.1.0)
-- Apply via: docker exec -i <mysql> mysql -u webcalendar -pwebcalendar_dev webcalendar < migrations/004_add_cal_image.sql
-- Safe to re-run: uses information_schema check to skip if column already exists.

DELIMITER //

DROP PROCEDURE IF EXISTS add_cal_image_column //

CREATE PROCEDURE add_cal_image_column()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'webcal_entry'
          AND COLUMN_NAME = 'cal_image'
    ) THEN
        ALTER TABLE webcal_entry
            ADD COLUMN cal_image VARCHAR(2048) DEFAULT NULL AFTER cal_status;
    END IF;
END //

CALL add_cal_image_column() //
DROP PROCEDURE add_cal_image_column //

DELIMITER ;

-- Initialize WebCalendar schema on first run
-- This file is mounted as a Docker entrypoint init script

-- Source the webcalendar-core schema
-- Note: The schema is copied into this file at build time by docker-compose volume mount
-- The actual schema comes from webcalendar-core/src/Infrastructure/Persistence/mysql-schema.sql

-- Grant permissions
GRANT ALL PRIVILEGES ON webcalendar.* TO 'webcalendar'@'%';
FLUSH PRIVILEGES;

-- 1. Create the database (if it doesn't already exist)
CREATE DATABASE IF NOT EXISTS telegram_bot_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. Use the new database
USE telegram_bot_db;

-- 3. Create the table to store encrypted texts
CREATE TABLE IF NOT EXISTS encrypted_texts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_code VARCHAR(16) NOT NULL UNIQUE,
    encrypted_data TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. (Optional) Create a MySQL Event to auto-delete entries older than 7 days
-- This helps manage database size.
SET GLOBAL event_scheduler = ON;

CREATE EVENT IF NOT EXISTS delete_old_texts
ON SCHEDULE EVERY 1 DAY
DO
    DELETE FROM encrypted_texts
    WHERE created_at < NOW() - INTERVAL 7 DAY;
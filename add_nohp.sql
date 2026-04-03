-- Migration: add no_hp column to users table (safe to run multiple times)
-- Target DB: soalpintar (change USE if your database name differs)
USE soalpintar;

SET @col_nohp := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'no_hp'
);

SET @sql_nohp := IF(
  @col_nohp = 0,
  'ALTER TABLE users ADD COLUMN no_hp VARCHAR(32) NULL AFTER username',
  'SELECT 1'
);

PREPARE stmt_nohp FROM @sql_nohp;
EXECUTE stmt_nohp;
DEALLOCATE PREPARE stmt_nohp;

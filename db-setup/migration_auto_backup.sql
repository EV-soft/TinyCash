-- migration_auto_backup.sql v:1.2.0 d:2026-08-11 i:evs 
-- Tilføjer settings-nøgler til automatisk backup

BEGIN TRANSACTION;

INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('auto_backup_mail',     '');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('auto_backup_password', '');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('auto_backup_last',     '0');
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('auto_backup_error',    '');

INSERT OR IGNORE INTO system_migrations (migration_key) VALUES ('auto_backup_settings_2026_08_04');

COMMIT;

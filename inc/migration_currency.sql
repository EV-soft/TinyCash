-- migration_currency.sql v:1.2.0 d:2026-08-11 i:evs 
-- Tilføjer orig_amount og exch_rate til expenses og invoice_lines
-- Kør via run_migrate.php eller phpLiteAdmin

BEGIN TRANSACTION;

-- expenses: beløb i originalvaluta + anvendt kurs
ALTER TABLE expenses ADD COLUMN orig_currency VARCHAR(3)    DEFAULT NULL;
ALTER TABLE expenses ADD COLUMN orig_amount   NUMERIC       DEFAULT NULL;
ALTER TABLE expenses ADD COLUMN exch_rate     NUMERIC       DEFAULT NULL;

-- invoice_lines: samme princip for udenlandske fakturaer
ALTER TABLE invoice_lines ADD COLUMN orig_currency VARCHAR(3) DEFAULT NULL;
ALTER TABLE invoice_lines ADD COLUMN orig_amount   NUMERIC    DEFAULT NULL;
ALTER TABLE invoice_lines ADD COLUMN exch_rate     NUMERIC    DEFAULT NULL;

-- Registrer migration
INSERT OR IGNORE INTO system_migrations (migration_key) VALUES ('add_currency_fields_2026_08_04');

COMMIT;

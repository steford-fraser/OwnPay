-- DDL runs in Plugin::ensureSchema() (MySQL commits DDL and breaks PluginMigrator transactions).
-- This file records the migration batch only.
SELECT 1;

-- ============================================================================
-- L1: Drop redundant indexes (production)
-- ============================================================================
-- Each index below is a duplicate or a left-prefix of another index on the same
-- table, so it only adds write/storage overhead. The create migrations have been
-- adapted so fresh databases never build them; run this against already-migrated
-- databases to bring them in line.
--
-- Dropping an index is a fast, non-blocking, fully reversible metadata operation.
-- MySQL 8 has no DROP INDEX IF EXISTS, so an index that was already removed will
-- raise "check that column/key exists" (errno 1091) — that error is safe to ignore.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- Management database (run once)
-- ---------------------------------------------------------------------------
-- roles: unique(scope, team_id, key) already covers this tuple.
ALTER TABLE `roles`      DROP INDEX `roles_scope_team_id_key_index`;
-- team_user / space_user: unique(...) already covers the same tuple.
ALTER TABLE `team_user`  DROP INDEX `team_user_team_id_user_id_index`;
ALTER TABLE `space_user` DROP INDEX `space_user_space_id_user_id_index`;


-- ---------------------------------------------------------------------------
-- Space databases (run once PER space database)
-- ---------------------------------------------------------------------------
-- audit_logs: single-column indexes that are redundant left-prefixes of the
-- composites (referenced_type, referenced_id) / (owner_type, owner_id) /
-- (operation, created_at). audit_logs takes an INSERT on every space-model write,
-- so trimming these three directly cuts per-write index maintenance.
ALTER TABLE `audit_logs`     DROP INDEX `audit_logs_referenced_type_index`;
ALTER TABLE `audit_logs`     DROP INDEX `audit_logs_owner_type_index`;
ALTER TABLE `audit_logs`     DROP INDEX `audit_logs_operation_index`;

-- asset_versions: (asset_id) is a left-prefix of (asset_id, version_number).
ALTER TABLE `asset_versions` DROP INDEX `asset_versions_asset_id_index`;

-- comments: (content_id) is a left-prefix of (content_id, is_resolved).
ALTER TABLE `comments`       DROP INDEX `comments_content_id_index`;

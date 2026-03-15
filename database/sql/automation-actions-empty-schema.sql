-- MySQL / MariaDB migration for an already-migrated but still empty schema.
-- Assumes the legacy single-table automation schema exists and contains no rows.

CREATE TABLE `automation_actions` (
  `id` char(26) NOT NULL,
  `space_id` char(26) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text NULL,
  `type` enum('webhook','email','void') NOT NULL,
  `config` json NULL,
  `secrets` json NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_executed_at` timestamp NULL DEFAULT NULL,
  `last_execution_status` varchar(20) NULL,
  `last_execution_error` text NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `automation_actions_space_id_type_index` (`space_id`, `type`),
  KEY `automation_actions_space_id_is_active_index` (`space_id`, `is_active`),
  KEY `automation_actions_last_executed_at_index` (`last_executed_at`),
  CONSTRAINT `automation_actions_space_id_foreign`
    FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON DELETE CASCADE
);

ALTER TABLE `automations`
  ADD COLUMN `action_id` char(26) NOT NULL AFTER `description`,
  ADD COLUMN `trigger_type` enum('on_insert','on_update','on_delete','time_based','manual') NOT NULL AFTER `action_id`,
  ADD COLUMN `trigger_config` json NULL AFTER `trigger_type`,
  ADD KEY `automations_space_id_trigger_type_index` (`space_id`, `trigger_type`),
  ADD KEY `automations_space_id_is_active_index` (`space_id`, `is_active`),
  ADD KEY `automations_action_id_is_active_index` (`action_id`, `is_active`),
  ADD KEY `automations_last_triggered_at_index` (`last_triggered_at`),
  ADD CONSTRAINT `automations_action_id_foreign`
    FOREIGN KEY (`action_id`) REFERENCES `automation_actions` (`id`) ON DELETE RESTRICT;

ALTER TABLE `automations`
  DROP COLUMN `trigger`,
  DROP COLUMN `action`,
  DROP COLUMN `secrets`;

UPDATE `roles`
SET `abilities` = JSON_ARRAY(
  'space.view',
  'space.update',
  'space.archive',
  'space.delete',
  'space.members.view',
  'space.members.manage',
  'space.invites.view',
  'space.invites.manage',
  'space.billing.view',
  'space.billing.manage',
  'space.tokens.view',
  'space.tokens.manage',
  'assets.view',
  'assets.manage',
  'asset_folders.view',
  'asset_folders.manage',
  'asset_tags.view',
  'asset_tags.manage',
  'blocks.view',
  'blocks.manage',
  'block_templates.view',
  'block_templates.manage',
  'block_versions.view',
  'block_versions.manage',
  'content.view',
  'content.manage',
  'content.publish',
  'content.history.view',
  'comments.view',
  'comments.create',
  'comments.update_own',
  'comments.delete_own',
  'comments.resolve_own',
  'comments.react',
  'data_sources.view',
  'data_sources.manage',
  'data_entries.view',
  'data_entries.manage',
  'redirects.view',
  'redirects.manage',
  'releases.view',
  'releases.manage',
  'releases.publish',
  'backups.view',
  'backups.manage',
  'migrations.view',
  'migrations.manage',
  'automation_actions.view',
  'automation_actions.manage',
  'automations.view',
  'automations.manage',
  'ai.view',
  'ai.manage'
),
`updated_at` = CURRENT_TIMESTAMP
WHERE `scope` = 'space'
  AND `team_id` IS NULL
  AND `key` IN ('owner', 'admin');

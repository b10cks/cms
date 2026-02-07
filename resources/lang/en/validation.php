<?php

return [
    'backup' => [
        'name_required' => 'A backup name is required.',
        'recipients_required' => 'At least one recipient email address is required.',
        'invalid_email' => 'Please provide a valid email address.',
        'expires_after_now' => 'The expiration date must be in the future.',
	],
    'block_template' => [
        'color_regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
        'name_required' => 'The template name is required.',
        'content_required' => 'The template content is required.',
    ],
    'block_version' => [
        'commit_message_required' => 'The commit message is required.',
        'commit_message_max' => 'The commit message may not be greater than 500 characters.',
    ],
    'blueprint' => [
        'name_required' => 'A blueprint name is required.',
        'color_invalid' => 'The color must be a valid hex color code (e.g., #FF5733).',
        'source_space_invalid' => 'The selected source space could not be found.',
        'source_space_team_mismatch' => 'The source space must belong to the selected team.',
        'tables_invalid' => 'One or more selected tables are not supported for blueprints.',
        'invalid' => 'The selected blueprint is not available.',
        'delete_failed' => 'An error occurred while deleting the space blueprint.',
    ],
];

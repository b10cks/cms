<?php

return [
    'block_template' => [
        'color_regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
        'name_required' => 'The template name is required.',
        'content_required' => 'The template content is required.',
    ],
    'block_version' => [
        'commit_message_required' => 'The commit message is required.',
        'commit_message_max' => 'The commit message may not be greater than 500 characters.',
    ],
];

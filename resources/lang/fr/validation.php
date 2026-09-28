<?php

return [
    'backup' => [
        'name_required' => 'Un nom de sauvegarde est requis.',
        'recipients_required' => 'Au moins une adresse e-mail de destinataire est requise.',
        'invalid_email' => 'Veuillez fournir une adresse e-mail valide.',
        'expires_after_now' => 'La date d\'expiration doit être dans le futur.',
	],
    'block_template' => [
        'color_regex' => 'La couleur doit être un code couleur hexadécimal valide (par ex. #FF5733).',
        'name_required' => 'Le nom du modèle est requis.',
        'content_required' => 'Le contenu du modèle est requis.',
    ],
    'block_version' => [
        'commit_message_required' => 'Le message de commit est requis.',
        'commit_message_max' => 'Le message de commit ne peut pas dépasser 500 caractères.',
    ],
    'blueprint' => [
        'name_required' => 'Un nom de plan (espace) est requis.',
        'color_invalid' => 'La couleur doit être un code couleur hexadécimal valide (par ex. #FF5733).',
        'source_space_invalid' => 'L\'espace source sélectionné est introuvable.',
        'source_space_not_ready' => 'L\'espace source sélectionné n\'est pas encore prêt à être copié.',
        'tables_invalid' => 'Une ou plusieurs tables sélectionnées ne sont pas prises en charge pour les plans.',
        'invalid' => 'Le plan sélectionné n\'est pas disponible.',
        'delete_failed' => 'Une erreur est survenue lors de la suppression du plan.',
    ],
];

<?php

return [
    'backup' => [
        'name_required' => 'Se requiere un nombre para la copia de seguridad.',
        'recipients_required' => 'Se requiere al menos una dirección de correo de destinatario.',
        'invalid_email' => 'Facilite una dirección de correo válida.',
        'expires_after_now' => 'La fecha de caducidad debe ser posterior al momento actual.',
	],
    'block_template' => [
        'color_regex' => 'El color debe ser un código de color hexadecimal válido (por ejemplo, #FF5733).',
        'name_required' => 'Se requiere el nombre de la plantilla.',
        'content_required' => 'Se requiere el contenido de la plantilla.',
    ],
    'block_version' => [
        'commit_message_required' => 'Se requiere el mensaje de confirmación (commit).',
        'commit_message_max' => 'El mensaje de confirmación no puede superar los 500 caracteres.',
    ],
    'blueprint' => [
        'name_required' => 'Se requiere un nombre para la plantilla de espacio.',
        'color_invalid' => 'El color debe ser un código de color hexadecimal válido (por ejemplo, #FF5733).',
        'source_space_invalid' => 'No se ha podido encontrar el espacio de origen seleccionado.',
        'source_space_not_ready' => 'El espacio de origen seleccionado aún no está listo para ser copiado.',
        'tables_invalid' => 'Una o varias de las tablas seleccionadas no son compatibles con las plantillas de espacio.',
        'invalid' => 'La plantilla de espacio seleccionada no está disponible.',
        'delete_failed' => 'Se produjo un error al eliminar la plantilla de espacio.',
    ],
];

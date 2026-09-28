<?php

return [
    'failed' => 'Estas credenciales no coinciden con nuestros registros.',
    'password' => 'La contraseña facilitada es incorrecta.',
    'throttle' => 'Demasiados intentos de inicio de sesión. Inténtelo de nuevo en :seconds segundos.',
    'too_many_verification_attempts' => 'Demasiados intentos de verificación fallidos. Inténtelo de nuevo en :seconds segundos.',

    // Password Reset
    'password_reset_link_sent' => 'Si existe una cuenta con esa dirección de correo, le hemos enviado el enlace para restablecer la contraseña.',
    'password_reset_successful' => 'Su contraseña se ha restablecido correctamente.',
    'password_reset_failed' => 'No hemos podido restablecer su contraseña. Inténtelo de nuevo.',
    'invalid_password_reset_token' => 'Este token de restablecimiento de contraseña no es válido.',
    'password_reset_throttled' => 'Espere antes de solicitar un nuevo enlace de restablecimiento.',

    // Email Verification
    'email_already_verified' => 'Su dirección de correo ya está verificada.',
    'email_not_verified' => 'Su dirección de correo no está verificada. Se ha enviado un correo de verificación.',
    'email_verification_sent' => 'Se ha enviado un enlace de verificación a su dirección de correo.',
    'email_verification_rate_limit' => 'Espere antes de solicitar un nuevo correo de verificación.',
    'email_verified' => 'Su dirección de correo ha sido verificada.',
    'invalid_verification_link' => 'Este enlace de verificación no es válido o ha expirado.',

    // Two-Factor Authentication
    '2fa_already_enabled' => 'La autenticación en dos pasos ya está activada en su cuenta.',
    '2fa_not_enabled' => 'La autenticación en dos pasos no está activada en su cuenta.',
    '2fa_enabled' => 'La autenticación en dos pasos se ha activado correctamente.',
    '2fa_disabled' => 'La autenticación en dos pasos se ha desactivado correctamente.',
    '2fa_required' => 'Se requiere el código de autenticación en dos pasos.',
    '2fa_setup_expired' => 'La configuración de la autenticación en dos pasos ha expirado. Empiece de nuevo.',
    '2fa_verified' => 'El código de autenticación en dos pasos se ha verificado correctamente.',
    '2fa_verified_backup_code_used' => 'Autenticación en dos pasos verificada con código de recuperación. Genere nuevos códigos de recuperación.',
    'invalid_2fa_code' => 'El código de autenticación en dos pasos facilitado no es válido.',
    'password_confirmation_required' => 'Se requiere confirmación de contraseña para esta acción.',
    'invalid_password' => 'La contraseña facilitada es incorrecta.',
    'unauthenticated' => 'Debe estar autenticado para acceder a este recurso.',
    'cannotImpersonate' => 'No puede realizar esta acción mientras suplanta la identidad de otro usuario.',
    'not_impersonating' => 'Actualmente no está suplantando la identidad de otro usuario.',
    'login_successful' => 'Inicio de sesión correcto.',
    'login_session_expired' => 'La sesión de inicio de sesión ha expirado. Inténtelo de nuevo.',
    'registration_successful' => 'Registro correcto.',
    'registration_failed' => 'Se produjo un error durante el registro.',
    'registration_closed' => 'El registro está cerrado en esta instancia. Pida una invitación a un administrador.',
    'social_email_missing' => 'No hemos podido leer ninguna dirección de correo de esta cuenta social.',
    'social_link_already_used' => 'Este perfil social ya está vinculado a otra cuenta.',
    'social_link_required' => 'Ya existe una cuenta con esta dirección de correo. Inicie sesión con su contraseña y, a continuación, vincule este perfil social desde la configuración de su cuenta.',
];

<?php

return [
    'failed' => 'Ces identifiants ne correspondent pas à nos enregistrements.',
    'password' => 'Le mot de passe fourni est incorrect.',
    'throttle' => 'Trop de tentatives de connexion. Veuillez réessayer dans :seconds secondes.',
    'too_many_verification_attempts' => 'Trop de tentatives de vérification ont échoué. Veuillez réessayer dans :seconds secondes.',

    // Password Reset
    'password_reset_link_sent' => 'Si un compte avec cette adresse e-mail existe, nous vous avons envoyé le lien de réinitialisation du mot de passe.',
    'password_reset_successful' => 'Votre mot de passe a été réinitialisé avec succès.',
    'password_reset_failed' => 'Nous n\'avons pas pu réinitialiser votre mot de passe. Veuillez réessayer.',
    'invalid_password_reset_token' => 'Ce jeton de réinitialisation du mot de passe est invalide.',
    'password_reset_throttled' => 'Veuillez patienter avant de demander un nouveau lien de réinitialisation.',

    // Email Verification
    'email_already_verified' => 'Votre adresse e-mail est déjà vérifiée.',
    'email_not_verified' => 'Votre adresse e-mail n\'est pas vérifiée. Un e-mail de vérification a été envoyé.',
    'email_verification_sent' => 'Un lien de vérification a été envoyé à votre adresse e-mail.',
    'email_verification_rate_limit' => 'Veuillez patienter avant de demander un nouvel e-mail de vérification.',
    'email_verified' => 'Votre adresse e-mail a été vérifiée.',
    'invalid_verification_link' => 'Ce lien de vérification est invalide ou a expiré.',

    // Two-Factor Authentication
    '2fa_already_enabled' => 'L\'authentification à deux facteurs est déjà activée sur votre compte.',
    '2fa_not_enabled' => 'L\'authentification à deux facteurs n\'est pas activée sur votre compte.',
    '2fa_enabled' => 'L\'authentification à deux facteurs a été activée avec succès.',
    '2fa_disabled' => 'L\'authentification à deux facteurs a été désactivée avec succès.',
    '2fa_required' => 'Le code d\'authentification à deux facteurs est requis.',
    '2fa_setup_expired' => 'La configuration de l\'authentification à deux facteurs a expiré. Veuillez recommencer.',
    '2fa_verified' => 'Le code d\'authentification à deux facteurs a été vérifié avec succès.',
    '2fa_verified_backup_code_used' => 'Authentification à deux facteurs vérifiée à l\'aide d\'un code de secours. Veuillez générer de nouveaux codes de secours.',
    'invalid_2fa_code' => 'Le code d\'authentification à deux facteurs fourni est invalide.',
    'password_confirmation_required' => 'La confirmation du mot de passe est requise pour cette action.',
    'invalid_password' => 'Le mot de passe fourni est incorrect.',
    'unauthenticated' => 'Vous devez être authentifié pour accéder à cette ressource.',
    'cannotImpersonate' => 'Vous ne pouvez pas effectuer cette action en usurpant l\'identité d\'un autre utilisateur.',
    'not_impersonating' => 'Vous n\'usurpez pas actuellement l\'identité d\'un autre utilisateur.',
    'login_successful' => 'Connexion réussie.',
    'login_session_expired' => 'La session de connexion a expiré. Veuillez réessayer.',
    'registration_successful' => 'Inscription réussie.',
    'registration_failed' => 'Une erreur est survenue lors de l\'inscription.',
    'registration_closed' => 'L\'inscription est fermée sur cette instance. Demandez une invitation à un administrateur.',
    'social_email_missing' => 'Nous n\'avons pas pu lire d\'adresse e-mail à partir de ce compte social.',
    'social_link_already_used' => 'Ce profil social est déjà lié à un autre compte.',
    'social_link_required' => 'Un compte utilise déjà cette adresse e-mail. Connectez-vous avec votre mot de passe, puis liez ce profil social depuis les paramètres de votre compte.',
];

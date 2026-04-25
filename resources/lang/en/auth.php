<?php

return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    // Password Reset
    'password_reset_link_sent' => 'If an account with that email address exists, we have emailed your password reset link.',
    'password_reset_successful' => 'Your password has been reset successfully.',
    'password_reset_failed' => 'We could not reset your password. Please try again.',
    'invalid_password_reset_token' => 'This password reset token is invalid.',
    'password_reset_throttled' => 'Please wait before requesting another password reset link.',

    // Email Verification
    'email_already_verified' => 'Your email address is already verified.',
    'email_not_verified' => 'Your email address is not verified. A verification email has been sent.',
    'email_verification_sent' => 'A verification link has been sent to your email address.',
    'email_verification_rate_limit' => 'Please wait before requesting another verification email.',
    'email_verified' => 'Your email address has been verified.',
    'invalid_verification_link' => 'This verification link is invalid or has expired.',

    // Two-Factor Authentication
    '2fa_already_enabled' => 'Two-factor authentication is already enabled for your account.',
    '2fa_not_enabled' => 'Two-factor authentication is not enabled for your account.',
    '2fa_enabled' => 'Two-factor authentication has been enabled successfully.',
    '2fa_disabled' => 'Two-factor authentication has been disabled successfully.',
    '2fa_required' => 'Two-factor authentication code is required.',
    '2fa_setup_expired' => 'Two-factor authentication setup has expired. Please start again.',
    '2fa_verified' => 'Two-factor authentication code verified successfully.',
    '2fa_verified_backup_code_used' => 'Two-factor authentication verified with backup code. Please generate new backup codes.',
    'invalid_2fa_code' => 'The provided two-factor authentication code is invalid.',
    'password_confirmation_required' => 'Password confirmation is required for this action.',
    'invalid_password' => 'The provided password is incorrect.',
    'unauthenticated' => 'You must be authenticated to access this resource.',
    'cannotImpersonate' => 'You cannot perform this action while impersonating another user.',
    'not_impersonating' => 'You are not currently impersonating another user.',
    'login_successful' => 'Login successful.',
    'login_session_expired' => 'Login session expired. Please try again.',
    'registration_successful' => 'Registration successful.',
    'registration_failed' => 'An error occurred during registration.',
    'social_email_missing' => 'We could not read an email address from this social account.',
    'social_link_already_used' => 'This social profile is already linked to another account.',
];

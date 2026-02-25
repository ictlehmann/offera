<?php
/**
 * Bulk Invite - redirects to mass_invitations.php
 */
require_once __DIR__ . '/../../src/Auth.php';

if (!Auth::check() || !Auth::canManageUsers()) {
    header('Location: ../auth/login.php');
    exit;
}

header('Location: mass_invitations.php');
exit;

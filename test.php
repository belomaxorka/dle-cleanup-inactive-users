<?php
/**
 * ====================================================================
 * Очистка неактивных пользователей с мертвыми почтовыми ящиками
 *
 * @author belomaxorka
 * @version 1.0.0
 * @license MIT
 * @see https://github.com/belomaxorka/dle-cleanup-inactive-users
 * ====================================================================
 */

if (php_sapi_name() !== 'cli') {
    exit;
}

require_once __DIR__ . '/email-validator.php';
$validator = new SMTPEmailValidator();
$validator->setFromEmail('noreply@yourdomain.com');
$validator->setInactiveThresholdDays(365);

// $validator->testSingleEmail('example@example.com');
// $validator->enableRealMode();
// $validator->validateAllEmails(20);

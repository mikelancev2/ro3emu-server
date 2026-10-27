<?php

declare(strict_types=1);

require __DIR__ . '/includes/csrf.php';
site_session_start();
header('Location: panel.php?payment=processing#shop', true, 303);
exit;

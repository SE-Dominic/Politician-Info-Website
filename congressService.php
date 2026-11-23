<?php
/**
 * Routes an older congressional data URL to the current read handler.
 *
 * @package LACE
 */
declare(strict_types=1);
require_once __DIR__ . "/laceDatabase.php";
servePublicCongress_SELECT();

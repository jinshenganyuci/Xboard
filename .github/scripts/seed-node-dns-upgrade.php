<?php
declare(strict_types=1);

// Only the guarded runner should invoke this on an empty, disposable OLD image.
putenv('XBOARD_DNS_SMOKE_STAGE=seed');
require __DIR__ . '/smoke-node-dns.php';

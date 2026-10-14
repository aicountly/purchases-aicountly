<?php

declare(strict_types=1);

// The company-lifecycle worker's Kit (lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php):
// Purchases' own database connection — its name and user come from Console's SaaS Database Details
// (ConsoleDatabaseDetails), which the kit's plain .env reader would not know — and Purchases' manifest.
// Run from the api folder; see docs/COMPANY_LIFECYCLE.md.

require_once __DIR__ . '/../src/Env.php';
require_once __DIR__ . '/../src/Autoload.php';

\Aicountly\Api\Env::load(__DIR__ . '/../.env');

return \Aicountly\Api\CompanyLifecycle::kit();

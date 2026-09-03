<?php

// Each module owns and maintains its own routes.php — this file just wires
// them in. New modules get a single line added here as they're built.
require __DIR__.'/../app/Modules/Auth/routes.php';
require __DIR__.'/../app/Modules/Media/routes.php';
require __DIR__.'/../app/Modules/Product/routes.php';
require __DIR__.'/../app/Modules/Inventory/routes.php';
require __DIR__.'/../app/Modules/Customer/routes.php';
require __DIR__.'/../app/Modules/Order/routes.php';
require __DIR__.'/../app/Modules/Dashboard/routes.php';
require __DIR__.'/../app/Modules/Activity/routes.php';
require __DIR__.'/../app/Modules/Settings/routes.php';

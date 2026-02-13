<?php

return [
    // Set the same token value in OQLook connection wizard.
    'bearer_token' => 'change_me_strong_token',

    // Absolute path to iTop bootstrap or startup file.
    // Examples:
    // '/var/www/itop/application/startup.inc.php'
    // 'C:/inetpub/wwwroot/itop/application/startup.inc.php'
    'itop_bootstrap' => '/path/to/itop/application/startup.inc.php',

    // Optional: allowed CORS origins. Keep empty to deny all cross-origin.
    'cors_allowed_origins' => [],

    // Optional timeout hint for heavy metamodel calls.
    // Raise to 120-180 on large metamodels.
    'max_execution_seconds' => 120,
];

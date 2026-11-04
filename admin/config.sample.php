<?php
/**
 * Copy this file to config.php (same folder) and adjust.
 * config.php is NOT part of the repository and must not be overwritten by deployments.
 */
return [
    // Where users.json, login attempts and news history are stored.
    // Best practice: a folder OUTSIDE the web root that the PHP user can write, e.g. '/var/lib/factai-data'.
    'data_dir' => dirname(__DIR__) . '/_data',

    // Enables the "first admin" form on /admin while there are no users. Remove (set to '') after setup.
    'setup_code' => '',

    'max_upload_mb' => 8,
    'image_max_px' => 1600,
    'session_hours' => 8,
    'min_password' => 12,
];

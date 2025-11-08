<?php
// includes/config.php

// MySQL connection credentials
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'liver_cds');
define('DB_USER', 'liveradmin');
define('DB_PASS', 'LiverDB@123');

// AES-GCM settings (32 bytes key, base64)
# Generate once and keep secret. Example generation (Linux/Powershell):
# php -r "echo base64_encode(random_bytes(32));"
define('AES_KEY_B64', 'GENERATE_YOUR_BASE64_32BYTE_KEY_HERE');

// Flask model server (must be running separately)
define('FLASK_PREDICT_URL', 'http://127.0.0.1:5000/api/predict');


// App settings
// session_start();

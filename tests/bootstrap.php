<?php

require __DIR__ . '/../vendor/autoload.php';

// Load package-specific .env
if (file_exists(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/../', '.env')->load();
}

// Load package-specific .env.testing
if (file_exists(__DIR__ . '/../.env.testing')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/../', '.env.testing')->load();
}

<?php

declare(strict_types=1);

$public = dirname(__DIR__).DIRECTORY_SEPARATOR.'public';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = realpath($public.$path);

if ($path !== '/' && $file !== false && str_starts_with($file, realpath($public)) && is_file($file)) {
    return false;
}

require $public.DIRECTORY_SEPARATOR.'index.php';

<?php

require __DIR__ . '/../vendor/autoload.php';

/*
 * Stub helpers required by OpenWatchServiceProvider::boot() when running
 * tests without a full Laravel application. These are no-ops — they exist
 * only so the service provider can be instantiated and booted.
 */
if (! function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return sys_get_temp_dir() . ($path !== '' ? DIRECTORY_SEPARATOR . $path : '');
    }
}

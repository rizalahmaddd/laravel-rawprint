<?php

declare(strict_types=1);

use RawPrint\Laravel\RawPrintManager;

if (! function_exists('rawprint')) {
    function rawprint(): RawPrintManager
    {
        return app(RawPrintManager::class);
    }
}

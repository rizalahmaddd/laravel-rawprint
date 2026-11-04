<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | "browser" renders the payload on the server and lets the browser send it
    | to the agent. Use this when the Laravel app is hosted on another machine.
    | "direct" sends the payload from PHP. Use this only when the Laravel app
    | runs on the same PC as the printer.
    |
    */

    'driver' => env('RAWPRINT_DRIVER', 'browser'),

    /*
    |--------------------------------------------------------------------------
    | Agent connection
    |--------------------------------------------------------------------------
    */

    'base_url' => env('RAWPRINT_URL', 'http://127.0.0.1:18310'),

    // Required for the "direct" driver. Create it with the agent settings page
    // or `rawprint-agent install --origins=... --auto-approve`.
    'token' => env('RAWPRINT_TOKEN'),

    // Optional Origin header. Leave empty for local, non-browser callers.
    'origin' => env('RAWPRINT_ORIGIN'),

    'timeout' => (int) env('RAWPRINT_TIMEOUT', 30),
    'connect_timeout' => (int) env('RAWPRINT_CONNECT_TIMEOUT', 2),
    'max_payload_bytes' => (int) env('RAWPRINT_MAX_PAYLOAD_BYTES', 2 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Browser bridge
    |--------------------------------------------------------------------------
    */

    'app_name' => env('RAWPRINT_APP_NAME', env('APP_NAME', 'Laravel')),
    'auto_send' => (bool) env('RAWPRINT_AUTO_SEND', true),
    'prompt' => (bool) env('RAWPRINT_PROMPT', true),

    /*
    |--------------------------------------------------------------------------
    | Paper
    |--------------------------------------------------------------------------
    |
    | This profile is used when the server renders the document. In the
    | "direct" driver you can read the live profile from the agent instead.
    |
    */

    'paper_width_mm' => (float) env('RAWPRINT_PAPER_WIDTH_MM', 203.2),
    'line_width' => (int) env('RAWPRINT_LINE_WIDTH', 0), // 0 = derived from paper width and cpi
    'lines_per_inch' => (float) env('RAWPRINT_LINES_PER_INCH', 6.0),

    'profile' => [
        'page_height_mm' => (float) env('RAWPRINT_PAGE_HEIGHT_MM', 279.4),
        'top_margin_lines' => (int) env('RAWPRINT_TOP_MARGIN_LINES', 3),
        'left_margin_cols' => (int) env('RAWPRINT_LEFT_MARGIN_COLS', 0),
        'quality' => env('RAWPRINT_QUALITY', 'draft'),
        'cpi' => (int) env('RAWPRINT_CPI', 12),
    ],

];

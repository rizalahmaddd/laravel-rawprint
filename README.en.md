# RawPrint for Laravel

Laravel package for [RawPrint Agent](https://github.com/rizalahmaddd/raw-print-agent). Render ESC/P documents on the server, send them from the cashier browser, or send directly when Laravel and the printer run on the same PC.

[![CI](https://github.com/rizalahmaddd/laravel-rawprint/actions/workflows/laravel.yml/badge.svg)](https://github.com/rizalahmaddd/laravel-rawprint/actions/workflows/laravel.yml)

## Installation

From Packagist:

```bash
composer require rizalahmaddd/laravel-rawprint
```

Before it is on Packagist, use a VCS repository:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/rizalahmaddd/laravel-rawprint" }
    ]
}
```

```bash
composer require rizalahmaddd/laravel-rawprint:^1.0
```

Then publish the config and assets:

```bash
php artisan vendor:publish --tag=rawprint-config
php artisan vendor:publish --tag=rawprint-assets
```

Package discovery registers the provider and facade automatically.

## Compatibility

| Laravel | PHP | Tested |
|---|---|---|
| 13 | 8.2+ | yes |
| 12 | 8.2+ | yes |
| 11 | 8.2+ | yes |
| 10 | 8.1+ | yes |
| 9 | 8.1+ | yes |

CI runs every generation through the [Laravel workflow](https://github.com/rizalahmaddd/laravel-rawprint/actions/workflows/laravel.yml).

## Two modes

| Driver | Use when | Transport |
|---|---|---|
| `browser` (default) | Laravel is hosted, the printer is on the cashier PC | The browser posts to `127.0.0.1:18310` |
| `direct` | Laravel and the printer are on the same PC | PHP posts over HTTP |

The agent only listens on loopback, so a hosted application must use the `browser` driver.

## Browser mode

1. Register the application origin on the cashier PC:
   ```bash
   rawprint-agent install --origins=https://pos.example.com
   ```
2. Add this once to your layout:
   ```blade
   <x-rawprint />
   ```
3. Build a job and queue it before the view renders:
   ```php
   use RawPrint\Laravel\Facades\RawPrint;

   $job = RawPrint::job('invoice-'.$order->id)
       ->view('rawprint.invoice', ['order' => $order])
       ->name('Invoice '.$order->number)
       ->copies(2);

   RawPrint::queue($job);

   return view('orders.show', compact('order'));
   ```
   Or pass it as a prop: `<x-rawprint :jobs="[$job]" />`.

When the page loads the bridge detects the agent, asks for pairing in a modal with the 6 digit code, then sends the job and waits for the printer status.

### Trigger a print from JavaScript

```js
await RawPrintBridge.send(@json($job->payload()));

window.addEventListener('rawprint:job:done', e => console.log(e.detail));
window.addEventListener('rawprint:job:error', e => console.error(e.detail));
```

Events: `rawprint:unavailable`, `rawprint:job:queued`, `rawprint:job:done`, `rawprint:job:pending`, `rawprint:job:error`.

## Direct mode

```dotenv
RAWPRINT_DRIVER=direct
RAWPRINT_TOKEN=token-from-agent
```

Create a token on the same PC with auto-approve enabled:

```bash
rawprint-agent install --origins=https://pos.example.com --auto-approve
curl -s -X POST http://127.0.0.1:18310/pair \
  -H 'Origin: https://pos.example.com' \
  -H 'Content-Type: application/json' \
  -d '{"name":"Laravel"}'
```

Then print from PHP:

```php
$result = RawPrint::job('invoice-'.$order->id)
    ->view('rawprint.invoice', compact('order'))
    ->send();

$final = RawPrint::client()->wait($result->jobId);
```

To queue it:

```php
use RawPrint\Laravel\Jobs\PrintDocument;

dispatch(PrintDocument::fromPayload($job->payload(), wait: true));
```

## Documents

```php
$doc = RawPrint::document();

$doc->line('ACME STORE')
    ->bold()->line('INVOICE')->bold(false)
    ->row(['Item', 'Qty', 'Total'], [30, 5, 12])
    ->line(str_repeat('-', 47))
    ->align('right')->line('Total: 150.000')
    ->pageBreak();
```

- Top and left margins, CPI, and quality come from `config/rawprint.php`.
- Long lines wrap, including when using `text()` for templates.
- A form feed is emitted when a page is full, calculated from `page_height_mm`, `top_margin_lines`, and `lines_per_inch`.
- Each page ends on the perforation: `FF` for whole-line page heights, `ESC J` for a fractional remainder.
- Blade templates render as plain text. Use `{!! !!}` for values because `{{ }}` escapes `&`, `<`, and `>`.
- Text must use the printer codepage. Characters outside ASCII may print incorrectly.

## Agent limits

- `job_id` is idempotent for 10 minutes; use a natural key such as `invoice-123`, not a random UUID.
- Payload is capped at 2 MiB and `copies` at 1–10.
- The browser token lives in `localStorage` (`rawprint.token`), never on the server.
- Config is delivered in a `<script type="application/json">` block, not inline JavaScript, so it works with a `script-src 'self'` CSP. Make sure `/vendor/rawprint/*.js` is allowed.
- If the bridge says the agent is not running while the service is up, check `allowed_origins`. An unlisted origin makes the browser block the agent response.

## Tests

```bash
composer test
```

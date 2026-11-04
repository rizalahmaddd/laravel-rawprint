# RawPrint untuk Laravel

Package Laravel untuk [RawPrint Agent](https://github.com/rizalahmaddd/raw-print-agent). Render dokumen ESC/P di server, kirim dari browser kasir, atau kirim langsung saat Laravel dan printer ada di PC yang sama.

[![CI](https://github.com/rizalahmaddd/laravel-rawprint/actions/workflows/laravel.yml/badge.svg)](https://github.com/rizalahmaddd/laravel-rawprint/actions/workflows/laravel.yml)

## Instalasi

Dari Packagist:

```bash
composer require rizalahmaddd/laravel-rawprint
```

Sebelum ada di Packagist, pakai VCS repository:

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

Lalu publish config dan asset:

```bash
php artisan vendor:publish --tag=rawprint-config
php artisan vendor:publish --tag=rawprint-assets
```

Provider dan facade terdeteksi otomatis lewat package discovery.

## Kompatibilitas

| Laravel | PHP | Diuji |
|---|---|---|
| 13 | 8.2+ | ya |
| 12 | 8.2+ | ya |
| 11 | 8.2+ | ya |
| 10 | 8.1+ | ya |
| 9 | 8.1+ | ya |

CI menguji setiap generasi lewat [workflow Laravel](https://github.com/rizalahmaddd/laravel-rawprint/actions/workflows/laravel.yml).

## Dua mode

| Driver | Kapan dipakai | Cara kirim |
|---|---|---|
| `browser` (default) | Laravel di server, printer di PC kasir | Browser mengirim ke `127.0.0.1:18310` |
| `direct` | Laravel dan printer di PC yang sama | PHP mengirim lewat HTTP client |

Agent hanya mendengarkan di loopback PC tempat ia terpasang. Jadi untuk aplikasi yang di-host, pakai driver `browser`.

## Mode browser

1. Daftarkan origin aplikasi di PC kasir:
   ```bash
   rawprint-agent install --origins=https://pos.example.com
   ```
2. Tambahkan sekali di layout:
   ```blade
   <x-rawprint />
   ```
3. Buat job lalu antrekan sebelum view dirender:
   ```php
   use RawPrint\Laravel\Facades\RawPrint;

   $job = RawPrint::job('invoice-'.$order->id)
       ->view('rawprint.invoice', ['order' => $order])
       ->name('Invoice '.$order->number)
       ->copies(2);

   RawPrint::queue($job);

   return view('orders.show', compact('order'));
   ```
   Bisa juga lewat prop: `<x-rawprint :jobs="[$job]" />`.

Saat halaman dibuka, bridge mendeteksi agent, meminta pairing lewat modal berisi kode 6 digit, lalu mengirim job dan menunggu status printer.

### Memicu cetak dari JavaScript

```js
await RawPrintBridge.send(@json($job->payload()));

window.addEventListener('rawprint:job:done', e => console.log(e.detail));
window.addEventListener('rawprint:job:error', e => console.error(e.detail));
```

Event yang tersedia: `rawprint:unavailable`, `rawprint:job:queued`, `rawprint:job:done`, `rawprint:job:pending`, `rawprint:job:error`.

## Mode direct

```dotenv
RAWPRINT_DRIVER=direct
RAWPRINT_TOKEN=token-dari-agent
```

Buat token dari PC yang sama, dengan auto-approve aktif:

```bash
rawprint-agent install --origins=https://pos.example.com --auto-approve
curl -s -X POST http://127.0.0.1:18310/pair \
  -H 'Origin: https://pos.example.com' \
  -H 'Content-Type: application/json' \
  -d '{"name":"Laravel"}'
```

Lalu kirim dari PHP:

```php
$result = RawPrint::job('invoice-'.$order->id)
    ->view('rawprint.invoice', compact('order'))
    ->send();

$final = RawPrint::client()->wait($result->jobId);
```

Untuk antrean:

```php
use RawPrint\Laravel\Jobs\PrintDocument;

dispatch(PrintDocument::fromPayload($job->payload(), wait: true));
```

## Dokumen

```php
$doc = RawPrint::document();

$doc->line('TOKO MAJU')
    ->bold()->line('INVOICE')->bold(false)
    ->row(['Item', 'Qty', 'Total'], [30, 5, 12])
    ->line(str_repeat('-', 47))
    ->align('right')->line('Total: 150.000')
    ->pageBreak();
```

- Margin atas/kiri, CPI, dan kualitas diambil dari `config/rawprint.php`.
- Baris panjang di-wrap, termasuk saat memakai `text()` untuk template.
- Form feed otomatis setiap satu halaman penuh, dihitung dari `page_height_mm`, `top_margin_lines`, dan `lines_per_inch`.
- Halaman diakhiri tepat di perforasi: `FF` untuk tinggi halaman bulat, `ESC J` untuk sisa pecahan.
- Template Blade dirender sebagai teks biasa. Pakai `{!! !!}` untuk nilai, karena `{{ }}` meng-escape `&`, `<`, dan `>`.
- Teks harus memakai codepage printer. Karakter di luar ASCII bisa tercetak salah.

## Batasan agent

- `job_id` idempoten 10 menit; pakai natural key seperti `invoice-123`, bukan UUID acak.
- Payload maksimal 2 MiB dan `copies` 1–10.
- Token browser disimpan di `localStorage` (`rawprint.token`), bukan di server.
- Config dikirim lewat `<script type="application/json">`, bukan inline JavaScript, jadi tetap jalan dengan CSP `script-src 'self'`. Pastikan `/vendor/rawprint/*.js` diizinkan.
- Kalau bridge melaporkan agent tidak berjalan padahal service aktif, periksa `allowed_origins`. Origin yang tidak terdaftar membuat browser memblokir respons agent.

## Test

```bash
composer test
```

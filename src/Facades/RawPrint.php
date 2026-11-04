<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use RawPrint\Laravel\RawPrintManager;

/**
 * @method static \RawPrint\Laravel\Agent\AgentClient client()
 * @method static \RawPrint\Laravel\Document\Document document(?\RawPrint\Laravel\Document\PaperProfile $profile = null)
 * @method static \RawPrint\Laravel\Pending\Job job(?string $jobId = null)
 * @method static \RawPrint\Laravel\Document\PaperProfile profile()
 * @method static array agentProfile(?string $printer = null)
 * @method static \RawPrint\Laravel\Pending\Job queue(\RawPrint\Laravel\Pending\Job $job)
 * @method static list<array<string, mixed>> queued()
 * @method static void flush()
 * @method static string driver()
 * @method static bool isDirect()
 * @method static string appName()
 * @method static string baseUrl()
 * @method static array health()
 * @method static array printers()
 * @method static \RawPrint\Laravel\Agent\PrintResult testPrint(?string $printer = null)
 *
 * @see \RawPrint\Laravel\RawPrintManager
 */
class RawPrint extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RawPrintManager::class;
    }
}

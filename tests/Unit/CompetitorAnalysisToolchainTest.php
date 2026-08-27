<?php

use App\Console\Commands\RunCompetitorAnalysisCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The nightly run is invoked from cron, whose PATH is not the shell's. Resolving
 * `node` / `npm` against that PATH is what silently broke the scheduled run: the
 * scraper depends on better-sqlite3, a native addon compiled against a single
 * Node major, while prod's ambient PATH may lead with an ancient Node.
 *
 * These tests pin the properties that keep it from regressing: the toolchain is
 * put in front of PATH, and the schedule always leaves a log behind.
 */

/**
 * A throwaway bin directory holding a `node` shim that records the PATH and the
 * argv it was invoked with, so a test can prove which binary actually ran.
 */
function fakeNodeToolchain(string $probe): string
{
    $bin = sys_get_temp_dir().'/toolchain-'.bin2hex(random_bytes(6));

    mkdir($bin, 0o777, true);

    file_put_contents(
        $bin.'/node',
        "#!/bin/sh\nprintf '%s\\n' \"\$PATH\" \"\$0\" > ".escapeshellarg($probe)."\n"
    );

    chmod($bin.'/node', 0o755);

    return $bin;
}

function runScraperProcess(array $command, string $cwd): bool
{
    $instance = app(RunCompetitorAnalysisCommand::class);

    $instance->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    $process = new ReflectionMethod($instance, 'process');

    return $process->invoke($instance, $command, $cwd, 30);
}

it('reads the pinned toolchain directory from config without a trailing slash', function () {
    config()->set('competitor_pricing.node_bin', '/usr/local/node-24/bin/');

    $command = app(RunCompetitorAnalysisCommand::class);

    expect((new ReflectionMethod($command, 'nodeBin'))->invoke($command))
        ->toBe('/usr/local/node-24/bin');
});

it('runs the pinned node binary and puts its directory in front of PATH', function () {
    $probe = sys_get_temp_dir().'/toolchain-probe-'.bin2hex(random_bytes(6));
    $bin = fakeNodeToolchain($probe);

    config()->set('competitor_pricing.node_bin', $bin);

    expect(runScraperProcess([$bin.'/node', 'catalog-volledig/run.js'], sys_get_temp_dir()))->toBeTrue();

    [$path, $argv0] = explode("\n", trim(file_get_contents($probe)));

    expect($argv0)->toBe($bin.'/node')
        ->and($path)->toStartWith($bin.':');
})->skipOnWindows();

it('never leaves the scheduled run without an output log', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'pricing:run-competitor-analysis'));

    expect($event)->not->toBeNull()
        ->and($event->output)->not->toBe($event->getDefaultOutput());
});

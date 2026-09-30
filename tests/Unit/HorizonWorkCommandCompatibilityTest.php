<?php

use Illuminate\Queue\Console\WorkCommand;
use Laravel\Horizon\Console\WorkCommand as HorizonWorkCommand;

/**
 * horizon:work inherits gatherWorkerOptions() from the framework's queue:work
 * but declares its own signature. When the framework starts reading an option
 * that the installed Horizon does not declare (framework 12.69 added
 * --stop-when-empty-for, Horizon 5.45 lacked it), every worker dies on start-up
 * and no queue is processed at all.
 */
it('declares every option the framework queue worker reads', function () {
    $frameworkOptions = array_keys(app(WorkCommand::class)->getDefinition()->getOptions());
    $horizonOptions = array_keys(app(HorizonWorkCommand::class)->getDefinition()->getOptions());

    expect(array_values(array_diff($frameworkOptions, $horizonOptions)))->toBe([]);
});

it('starts a Horizon worker without an option error', function () {
    $this->artisan('horizon:work', [
        'connection'        => 'redis',
        '--queue'           => 'horizon-compatibility-test-'.uniqid(),
        '--stop-when-empty' => true,
    ])->assertSuccessful();
});

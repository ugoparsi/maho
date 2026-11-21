<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Mage_Cron
 */

declare(strict_types=1);

uses(Tests\MahoBackendTestCase::class);

final class CronManualOnly_Model_Job
{
    public function execute(Mage_Cron_Model_Schedule $schedule): void {}
}

final class CronManualOnly_Model_Logger extends Mage_Core_Model_Logger
{
    /** @var list<string> */
    public static array $warnings = [];

    #[\Override]
    public function log($message, $level = null, $file = '', $forceLog = false): void
    {
        if ($level === Mage::LOG_WARNING) {
            self::$warnings[] = (string) $message;
        }
    }
}

final class ManualCronJobObserver extends Mage_Cron_Model_Observer
{
    /** @var list<string> */
    public array $dispatched = [];

    /** @param list<Mage_Cron_Model_Schedule> $pending */
    public function __construct(private readonly array $pending = []) {}

    #[\Override]
    public function getPendingSchedules(): ArrayObject
    {
        return new ArrayObject($this->pending);
    }

    #[\Override]
    protected function _processCompiledJob(Mage_Cron_Model_Schedule $schedule, array $jobDef, bool $isAlways = false): self
    {
        $this->dispatched[] = $schedule->getJobCode();
        return $this;
    }

    #[\Override]
    protected function _processJob($schedule, $jobConfig, $isAlways = false): self
    {
        $this->dispatched[] = $schedule->getJobCode();
        return $this;
    }

    #[\Override]
    protected function _getAlwaysJobSchedule($jobCode): Mage_Cron_Model_Schedule
    {
        return Mage::getModel('cron/schedule')->setJobCode($jobCode);
    }

    #[\Override]
    public function generate(): self
    {
        return $this;
    }

    #[\Override]
    public function reapStuckJobs(): self
    {
        return $this;
    }

    #[\Override]
    public function cleanup(): self
    {
        return $this;
    }
}

beforeEach(function (): void {
    $this->compiledAttributes = new ReflectionProperty(Maho::class, 'compiledAttributes');
    $this->originalAttributes = Maho::getCompiledAttributes();
    $job = ['alias' => 'cronmanualonly/job', 'method' => 'execute', 'config_path' => null];
    $attributes = $this->originalAttributes;
    $attributes['crontab'] = [
        'cron_manual_only_test' => $job + ['schedule' => null],
        'cron_manual_only_timed_control' => $job + ['schedule' => '* * * * *'],
        'cron_manual_only_disabled_control' => $job + ['schedule' => '* * * * *'],
        'cron_manual_only_config_control' => ['config_path' => 'cron_manual_only_probe/unconfigured'] + $job + ['schedule' => null],
    ];
    $this->compiledAttributes->setValue(null, $attributes);

    $config = Mage::getConfig();
    $config->setNode('global/models/cronmanualonly/class', 'CronManualOnly_Model');
    $config->setNode('crontab/jobs/cron_manual_only_disabled_control/schedule/enabled', '0');
});

afterEach(function (): void {
    $this->compiledAttributes->setValue(null, $this->originalAttributes);
});

it('resolves a manual-only job without dispatching an obsolete pending run', function (): void {
    $manual = Mage::getModel('cron/schedule')->setJobCode('cron_manual_only_test')->setStatus(Mage_Cron_Model_Schedule::STATUS_PENDING);
    $timed = Mage::getModel('cron/schedule')->setJobCode('cron_manual_only_timed_control')->setStatus(Mage_Cron_Model_Schedule::STATUS_PENDING);
    $disabled = Mage::getModel('cron/schedule')->setJobCode('cron_manual_only_disabled_control')->setStatus(Mage_Cron_Model_Schedule::STATUS_PENDING);
    $observer = new ManualCronJobObserver([$manual, $timed, $disabled]);

    $callback = new ReflectionMethod(Mage_Cron_Service_Job::class, 'getCallback')
        ->invoke(Mage::getService('cron/job'), 'cron_manual_only_test');
    expect($callback[0])->toBeInstanceOf(CronManualOnly_Model_Job::class)
        ->and($callback[1])->toBe('execute');

    $observer->dispatch(new \Maho\Event\Observer());

    expect($observer->dispatched)->toBe(['cron_manual_only_timed_control'])
        ->and($manual->getStatus())->toBe(Mage_Cron_Model_Schedule::STATUS_PENDING);
});

it('keeps manual-only jobs out of the generated schedule even with older XML config', function (): void {
    $config = Mage::getConfig();
    $config->setNode('global/models/core/rewrite/logger', CronManualOnly_Model_Logger::class);
    CronManualOnly_Model_Logger::$warnings = [];
    Mage::app()->getStore()->setConfig(Mage_Cron_Model_Observer::XML_PATH_SCHEDULE_AHEAD_FOR, '1');
    $xml = simplexml_load_string('<jobs><cron_manual_only_test><schedule><cron_expr>* * * * *</cron_expr></schedule></cron_manual_only_test></jobs>');
    $observer = new ManualCronJobObserver();
    $resource = Mage::getSingleton('core/resource');
    $adapter = $resource->getConnection('core_write');
    $table = $resource->getTableName('cron/schedule');
    $jobCodes = ['cron_manual_only_test', 'cron_manual_only_timed_control', 'cron_manual_only_disabled_control', 'cron_manual_only_config_control'];

    $adapter->beginTransaction();
    try {
        new ReflectionMethod($observer, '_generateJobs')->invoke($observer, $xml->children(), []);
        new ReflectionMethod($observer, '_generateCompiledJobs')->invoke($observer, []);

        expect($adapter->fetchCol($adapter->select()->from($table, 'job_code')->where('job_code IN (?)', $jobCodes)))
            ->toBe(['cron_manual_only_timed_control'])
            ->and(CronManualOnly_Model_Logger::$warnings)->toHaveCount(1)
            ->and(CronManualOnly_Model_Logger::$warnings[0])->toContain("'cron_manual_only_config_control'");
    } finally {
        $adapter->rollBack();
    }
});

it('does not dispatch an older always schedule for a manual-only attribute job', function (): void {
    $observer = new ManualCronJobObserver();
    $xml = simplexml_load_string('<job><schedule><cron_expr>always</cron_expr></schedule><run><model>cronmanualonly/job::execute</model></run></job>');
    $dispatch = new ReflectionMethod($observer, '_processAlwaysTask');
    $dispatch->invoke($observer, 'cron_manual_only_test', $xml);
    $dispatch->invoke($observer, 'cron_manual_only_legacy_always_control', $xml);

    expect($observer->dispatched)->toBe(['cron_manual_only_legacy_always_control']);
});

<?php

namespace Dynamic\FoxyStripe\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;

/**
 * Class ClearPasswordLogTask
 * @package Dynamic\FoxyStripe\Tasks
 */
class ClearPasswordLogTask extends BuildTask
{
    /**
     * @var string
     */
    protected $title = 'Foxy - Clear Password Log Task';

    /**
     * @var string
     */
    private static $segment = 'foxy-clear-password-log-task';

    /**
     * @param \SilverStripe\Control\HTTPRequest $request
     */
    public function run($request)
    {
        $this->resetLog();
    }

    /**
     *
     */
    protected function resetLog()
    {
        foreach ($this->getLogs() as $log) {
            DB::prepared_query("DELETE FROM `MemberPassword` WHERE `ID` = ?", [$log['ID']]);
        }
    }

    /**
     * @return \Generator
     */
    protected function getLogs()
    {
        foreach (DB::query("Select * FROM `MemberPassword`") as $passwordLog) {
            yield $passwordLog;
        }
    }
}

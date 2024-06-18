<?php

namespace Dynamic\FoxyStripe\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;

/**
 * Class EncryptionTask
 * @package Dynamic\FoxyStripe\Tasks
 */
class EncryptionTask extends BuildTask
{
    /**
     * @var string
     */
    protected $title = 'Foxy - Encryption Task';

    /**
     * @var string
     */
    private static $segment = 'foxy-encryption-task';

    /**
     * @param \SilverStripe\Control\HTTPRequest $request
     */
    public function run($request)
    {
        $this->updateEncryption();
    }

    /**
     *
     */
    protected function updateEncryption()
    {
        /** @var Member $member */
        foreach ($this->getMembers() as $member) {
            DB::prepared_query("UPDATE `Member` SET `PasswordEncryption` = ? WHERE `ID` = ?", ['sha1_v2.4', $member->ID]);
        }
    }

    /**
     * @return \Generator
     */
    protected function getMembers()
    {
        foreach (Member::get()->filter('PasswordEncryption:not', 'sha1_v2.4')->sort('ID') as $member) {
            yield $member;
        }
    }
}

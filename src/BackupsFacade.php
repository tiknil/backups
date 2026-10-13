<?php

namespace Tiknil\Backups;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Tiknil\Backups\Skeleton\SkeletonClass
 */
class BackupsFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'backups';
    }
}

<?php

namespace Tiknil\Backups\Core\Dumpers;

use Spatie\DbDumper\Databases\MySql;

class MySqlDumper extends MySql
{
    public function __construct()
    {
        parent::__construct();

        $config = config('tk-backups.dumper_settings.mysql');

        if ($config['quick'] ?? true) {
            $this->useQuick();
        } else {
            $this->doNotUseQuick();
        }

        if ($config['single-transaction'] ?? true) {
            $this->useSingleTransaction();
        } else {
            $this->doNotUseSingleTransaction();
        }

        if ($config['skip-comments'] ?? true) {
            $this->skipComments();
        } else {
            $this->doNotSkipComments();
        }

        $this->setGtidPurged($config['gtid-purged'] ?? 'AUTO');

        foreach ($config['extra_options'] ?? [] as $extra) {
            $this->addExtraOption($extra);
        }
    }
}

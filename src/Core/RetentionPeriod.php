<?php

namespace Tiknil\Backups\Core;

use DateTimeInterface;

enum RetentionPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function retentionDays()
    {
        return config('tk-backups.retention_days.'.$this->value);
    }

    /** @return RetentionPeriod[] */
    public static function promotable(): array
    {
        return [self::Weekly, self::Monthly, self::Yearly];
    }

    public function basePath(DateTimeInterface $at): string
    {
        $folder = match ($this) {
            self::Daily => $at->format('Y-m-d'),
            self::Weekly => $at->format('o-\WW'),
            self::Monthly => $at->format('Y-m'),
            self::Yearly => $at->format('Y'),
        };

        return $this->value.'/'.$folder;
    }
}

<?php

namespace App\Modules\HR\Payroll\Enums;

enum PayslipStatus: string
{
    case Draft     = 'draft';
    case Finalised = 'finalised';
    case Viewed    = 'viewed';
}

<?php

namespace App\Modules\Agri\Satellite;

/** The organisation's satellite requests for today are used up; nothing more is asked for until tomorrow. */
class SatelliteBudgetExhausted extends \RuntimeException {}

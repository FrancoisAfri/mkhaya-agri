<?php

/*
| Business Intelligence.
|
| `included`: BI is part of every company's system (the default) — the Analytics ribbon shows for
| everyone the access screens allow, on the hosted service and on self-hosted installs alike. Set
| BI_INCLUDED=false to sell it as a paid add-on again: it then shows only for companies whose
| subscription (or licence) includes `business_intelligence`.
*/
return [
    'included' => (bool) env('BI_INCLUDED', true),
];

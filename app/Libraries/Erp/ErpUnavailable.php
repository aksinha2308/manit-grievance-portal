<?php

namespace App\Libraries\Erp;

use RuntimeException;

/** The ERP could not be reached or did not answer (connection or query failed). Never shown to people as is. */
class ErpUnavailable extends RuntimeException
{
}

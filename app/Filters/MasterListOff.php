<?php

namespace App\Filters;

use App\Libraries\Erp\ErpDirectory;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * When people come from the ERP, the old master list screens (Admin > Master list) are retired: anyone opening one
 * is sent to the read-only list of people from the ERP.
 */
class MasterListOff implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (ErpDirectory::enabled()) {
            return redirect()->to(site_url('admin/erp/people'))->with('success', 'People now come from the institute ERP, so the master list is no longer used. This is the list of people read from the ERP.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

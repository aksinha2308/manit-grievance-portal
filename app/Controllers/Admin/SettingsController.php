<?php

namespace App\Controllers\Admin;

use App\Libraries\AuditLogger;
use App\Libraries\Settings;
use App\Libraries\SettingsDefinition;
use CodeIgniter\HTTP\RedirectResponse;

class SettingsController extends AdminController
{
    public function index(): string
    {
        return view('admin/settings', [
            'groups'  => SettingsDefinition::groups(),
            'values'  => Settings::all(),
            'filetypes' => SettingsDefinition::ATTACHMENT_TYPES,
        ]);
    }

    public function save(): RedirectResponse
    {
        [$clean, $errors] = SettingsDefinition::clean($this->request->getPost());

        if ($errors !== []) {
            return $this->failed('admin/settings', $errors);
        }

        $before = [];
        $after  = [];
        foreach ($clean as $key => $value) {
            $old = Settings::get($key);
            if ($old !== $value) {
                $before[$key] = $old;
                $after[$key]  = $value;
            }
        }

        if ($after === []) {
            return $this->done('admin/settings', 'Nothing was changed.');
        }

        $db = \Config\Database::connect();
        $db->transStart();
        foreach ($after as $key => $value) {
            Settings::set($key, $value);
        }
        AuditLogger::log('update', 'settings', null, $before, $after);
        $db->transComplete();

        return $this->done('admin/settings', count($after) . ' setting(s) saved.');
    }
}

<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// ---------------------------------------------------------------------
// Public
// ---------------------------------------------------------------------
$routes->get('/', 'Home::index');

// Sign in: Google for submitters and authorities; administrator sign-in is separate.
$routes->get('login', 'AuthController::login');
$routes->get('admin-login', 'AuthController::adminLogin');
$routes->post('admin-login', 'AuthController::adminAttempt');
$routes->post('logout', 'AuthController::logout');
$routes->get('auth/google', 'AuthController::google');
$routes->get('auth/admin/google', 'AuthController::adminGoogle');
$routes->get('auth/google/callback', 'AuthController::googleCallback');
$routes->get('auth/dev', 'AuthController::devForm');          // development only (google.devLogin)
$routes->post('auth/dev', 'AuthController::devLogin');

// ---------------------------------------------------------------------
// Students and staff on the master list (signed in with Google)
// ---------------------------------------------------------------------
$routes->group('', ['filter' => 'submitter'], static function ($routes) {
    $routes->get('submit', 'Submit::form');
    $routes->post('submit', 'Submit::submit');
    $routes->post('submit/otp', 'Submit::sendOtp');
    $routes->get('submit/done', 'Submit::done');
    $routes->get('my', 'My::index');
    $routes->get('my/(:num)', 'My::show/$1');
    $routes->post('my/(:num)/reply', 'My::reply/$1');
    $routes->get('my/(:num)/attachments/(:num)', 'My::attachment/$1/$2');
});

// ---------------------------------------------------------------------
// The admin's own password (only the admin has one)
// ---------------------------------------------------------------------
$routes->group('account', ['filter' => ['auth', 'role:admin']], static function ($routes) {
    $routes->get('password', 'Account::password');
    $routes->post('password', 'Account::updatePassword');
});

// ---------------------------------------------------------------------
// Admin (configuration only; no grievance actions)
// ---------------------------------------------------------------------
$routes->group('admin', ['filter' => ['auth', 'role:admin']], static function ($routes) {
    $routes->get('/', 'Admin\Dashboard::index');

    // Lists: departments / hostels / semesters
    $routes->get('lists/(:segment)', 'Admin\Lists::index/$1');
    $routes->post('lists/(:segment)/add', 'Admin\Lists::add/$1');
    $routes->post('lists/(:segment)/(:num)/rename', 'Admin\Lists::rename/$1/$2');
    $routes->post('lists/(:segment)/(:num)/toggle', 'Admin\Lists::toggle/$1/$2');
    $routes->post('lists/(:segment)/(:num)/delete', 'Admin\Lists::delete/$1/$2');

    // Posts
    $routes->get('posts', 'Admin\Posts::index');
    $routes->post('posts/add', 'Admin\Posts::add');
    $routes->post('posts/(:num)/update', 'Admin\Posts::update/$1');
    $routes->post('posts/(:num)/toggle', 'Admin\Posts::toggle/$1');
    $routes->post('posts/(:num)/delete', 'Admin\Posts::delete/$1');

    // Grievance types and escalation matrix
    $routes->get('types', 'Admin\GrievanceTypes::index');
    $routes->post('types/add', 'Admin\GrievanceTypes::add');
    $routes->post('types/(:num)/update', 'Admin\GrievanceTypes::update/$1');
    $routes->post('types/(:num)/toggle', 'Admin\GrievanceTypes::toggle/$1');
    $routes->post('types/(:num)/delete', 'Admin\GrievanceTypes::delete/$1');
    $routes->get('types/(:num)/matrix', 'Admin\GrievanceTypes::matrix/$1');
    $routes->post('types/(:num)/matrix/add', 'Admin\GrievanceTypes::addLevel/$1');
    $routes->post('types/(:num)/matrix/(:num)/remove', 'Admin\GrievanceTypes::removeLevel/$1/$2');
    $routes->post('types/(:num)/matrix/(:num)/move', 'Admin\GrievanceTypes::moveLevel/$1/$2');
    $routes->post('types/(:num)/matrix/(:num)/days', 'Admin\GrievanceTypes::levelDays/$1/$2');
    $routes->post('types/(:num)/matrix/(:num)/skip', 'Admin\GrievanceTypes::levelSkip/$1/$2');

    // Authority accounts (fixed paths first)
    $routes->get('users', 'Admin\Users::index');
    $routes->get('users/new', 'Admin\Users::create');
    $routes->post('users', 'Admin\Users::store');
    $routes->get('users/import', 'Admin\Users::import');
    $routes->get('users/import/template', 'Admin\Users::template');
    $routes->post('users/import', 'Admin\Users::upload');
    $routes->post('users/import/confirm', 'Admin\Users::confirm');
    $routes->post('users/import/cancel', 'Admin\Users::cancel');
    $routes->get('users/(:num)/edit', 'Admin\Users::edit/$1');
    $routes->post('users/(:num)/update', 'Admin\Users::update/$1');
    $routes->post('users/(:num)/toggle', 'Admin\Users::toggle/$1');
    $routes->post('users/(:num)/unlock', 'Admin\Users::unlock/$1');
    $routes->post('users/(:num)/delete', 'Admin\Users::delete/$1');

    // Post holders
    $routes->get('post-holders', 'Admin\PostHolders::index');
    $routes->get('post-holders/(:num)', 'Admin\PostHolders::show/$1');
    $routes->post('post-holders/(:num)/assign', 'Admin\PostHolders::assign/$1');
    $routes->post('post-holders/(:num)/vacate', 'Admin\PostHolders::vacate/$1');

    // Submission form fields
    $routes->get('fields', 'Admin\FormFields::index');
    $routes->get('fields/(:segment)', 'Admin\FormFields::index/$1');
    $routes->post('fields/(:segment)/add', 'Admin\FormFields::add/$1');
    $routes->post('fields/(:segment)/(:num)/update', 'Admin\FormFields::update/$1/$2');
    $routes->post('fields/(:segment)/(:num)/move', 'Admin\FormFields::move/$1/$2');
    $routes->post('fields/(:segment)/(:num)/delete', 'Admin\FormFields::delete/$1/$2');

    // Master list (students, faculty, non-teaching); fixed paths first
    // (retired when people come from the ERP: the masterlistoff filter sends the admin to Admin > ERP > People)
    $routes->post('people/import/confirm', 'Admin\MasterList::confirm', ['filter' => 'masterlistoff']);
    $routes->post('people/import/cancel', 'Admin\MasterList::cancel', ['filter' => 'masterlistoff']);
    $routes->get('people', 'Admin\MasterList::index', ['filter' => 'masterlistoff']);
    $routes->get('people/(:segment)', 'Admin\MasterList::index/$1', ['filter' => 'masterlistoff']);
    $routes->get('people/(:segment)/new', 'Admin\MasterList::create/$1', ['filter' => 'masterlistoff']);
    $routes->post('people/(:segment)', 'Admin\MasterList::store/$1', ['filter' => 'masterlistoff']);
    $routes->get('people/(:segment)/import', 'Admin\MasterList::import/$1', ['filter' => 'masterlistoff']);
    $routes->get('people/(:segment)/import/template', 'Admin\MasterList::template/$1', ['filter' => 'masterlistoff']);
    $routes->post('people/(:segment)/import', 'Admin\MasterList::upload/$1', ['filter' => 'masterlistoff']);
    $routes->get('people/(:segment)/(:num)/edit', 'Admin\MasterList::edit/$1/$2', ['filter' => 'masterlistoff']);
    $routes->post('people/(:segment)/(:num)/update', 'Admin\MasterList::update/$1/$2', ['filter' => 'masterlistoff']);
    $routes->post('people/(:segment)/(:num)/toggle', 'Admin\MasterList::toggle/$1/$2', ['filter' => 'masterlistoff']);
    $routes->post('people/(:segment)/(:num)/delete', 'Admin\MasterList::delete/$1/$2', ['filter' => 'masterlistoff']);

    // ERP: job titles, people read from the ERP (read only), sync
    $routes->get('erp', 'Admin\ErpAdmin::index');
    $routes->post('erp/title', 'Admin\ErpAdmin::setTitle');
    $routes->post('erp/title/remove', 'Admin\ErpAdmin::removeTitle');
    $routes->get('erp/people', 'Admin\ErpAdmin::people');
    $routes->get('erp/sync', 'Admin\ErpAdmin::sync');
    $routes->post('erp/sync', 'Admin\ErpAdmin::runSync');

    // Grievances (view only), export, report, archive
    $routes->get('grievances', 'Admin\Grievances::index');
    $routes->get('grievances/export', 'Admin\Grievances::export');
    $routes->get('grievances/(:num)', 'Admin\Grievances::show/$1');
    $routes->get('grievances/(:num)/attachments/(:num)', 'Admin\Grievances::attachment/$1/$2');
    $routes->post('grievances/(:num)/archive', 'Admin\Grievances::archive/$1');
    $routes->post('grievances/(:num)/restore', 'Admin\Grievances::restore/$1');
    $routes->get('report', 'ReportController::index');
    $routes->get('report/xlsx', 'ReportController::xlsx');
    $routes->get('archive', 'Admin\Archive::index');
    $routes->post('archive', 'Admin\Archive::run');

    // Settings and audit log
    $routes->get('settings', 'Admin\SettingsController::index');
    $routes->post('settings', 'Admin\SettingsController::save');
    $routes->get('audit', 'Admin\Audit::index');
});

// ---------------------------------------------------------------------
// Authorities (HOD, Warden, Dean, Chairman, Registrar, Director...)
// ---------------------------------------------------------------------
$routes->group('authority', ['filter' => ['auth', 'role:authority']], static function ($routes) {
    $routes->get('/', 'Authority\Dashboard::index');
    $routes->get('export', 'Authority\Dashboard::export');
    $routes->get('report', 'ReportController::index');
    $routes->get('report/xlsx', 'ReportController::xlsx');
    $routes->get('grievances/(:num)', 'Authority\Grievances::show/$1');
    $routes->post('grievances/(:num)/act', 'Authority\Grievances::act/$1');
    $routes->get('grievances/(:num)/attachments/(:num)', 'Authority\Grievances::attachment/$1/$2');
});

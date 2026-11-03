<?php
declare(strict_types=1);

/**
 * Daftar rute. Format: [metode, pola, 'Controller@aksi', akses]
 * akses: 'public' (tanpa login) | 'any' (viewer & super_admin) | 'admin' (super_admin saja)
 * Rute yang tidak terdaftar di sini otomatis 404; akses tidak terdeklarasi tidak ada (deny by default).
 */
return [
    // --- auth ---
    ['GET',  '/login',  'AuthController@showLogin', 'public'],
    ['POST', '/login',  'AuthController@login',     'public'],
    ['POST', '/logout', 'AuthController@logout',    'any'],

    // --- PWA (manifest, service worker, halaman offline; tanpa data sensitif) ---
    ['GET', '/manifest.webmanifest', 'PwaController@manifest', 'public'],
    ['GET', '/sw.js',                'PwaController@worker',   'public'],
    ['GET', '/offline',              'PwaController@offline',  'public'],

    // --- dashboard ---
    ['GET', '/', 'DashboardController@index', 'any'],
    ['GET', '/api/dashboard', 'DashboardController@data', 'any'],
    ['GET', '/api/changes', 'DashboardController@changes', 'any'],

    // --- transaksi ---
    ['GET',  '/transactions',              'TransactionController@index',   'any'],
    ['GET',  '/transactions/new',          'TransactionController@create',  'admin'],
    ['POST', '/transactions',              'TransactionController@store',   'admin'],
    ['GET',  '/api/refs',                  'TransactionController@refs',    'admin'],
    ['GET',  '/transactions/{id}',         'TransactionController@show',    'any'],
    ['GET',  '/transactions/{id}/edit',    'TransactionController@edit',    'admin'],
    ['POST', '/transactions/{id}',         'TransactionController@update',  'admin'],
    ['POST', '/transactions/{id}/void',    'TransactionController@void',    'admin'],
    ['POST', '/transactions/{id}/restore', 'TransactionController@restore', 'admin'],

    // --- rekening & laporan ---
    ['GET', '/accounts',       'AccountsController@index', 'any'],
    ['GET', '/reports',        'ReportController@index',   'any'],
    ['GET', '/api/reports',    'ReportController@data',    'any'],

    // --- piutang proyek (invoice) ---
    ['GET',  '/invoices',                'InvoiceController@index',  'any'],
    ['GET',  '/invoices/new',            'InvoiceController@create', 'admin'],
    ['GET',  '/invoices/{id}',           'InvoiceController@show',   'any'],
    ['POST', '/invoices/{id}/link',      'InvoiceController@linkForecast',   'admin'],
    ['POST', '/invoices/{id}/tx',        'InvoiceController@linkTx',         'admin'],
    ['POST', '/invoices/{id}/unlink',    'InvoiceController@unlinkForecast', 'admin'],
    ['POST', '/invoices',                'InvoiceController@store',  'admin'],
    ['GET',  '/invoices/{id}/edit',      'InvoiceController@edit',   'admin'],
    ['POST', '/invoices/{id}',           'InvoiceController@update', 'admin'],
    ['POST', '/invoices/{id}/delete',    'InvoiceController@delete', 'admin'],

    // --- hutang (kewajiban ke supplier/vendor) ---
    ['GET',  '/debts',                         'DebtController@index',         'any'],
    ['GET',  '/debts/new',                     'DebtController@create',        'admin'],
    ['POST', '/debts',                         'DebtController@store',         'admin'],
    ['GET',  '/debts/{id}',                    'DebtController@show',          'any'],
    ['GET',  '/debts/{id}/edit',               'DebtController@edit',          'admin'],
    ['POST', '/debts/{id}',                    'DebtController@update',        'admin'],
    ['POST', '/debts/{id}/void',               'DebtController@void',          'admin'],
    ['POST', '/debts/{id}/restore',            'DebtController@restore',       'admin'],
    ['POST', '/debts/{id}/link',               'DebtController@linkForecast',  'admin'],
    ['POST', '/debts/{id}/unlink',             'DebtController@unlinkForecast','admin'],
    ['POST', '/debts/{id}/payments/{pid}/delete', 'DebtController@deletePayment', 'admin'],

    // --- proyeksi ---
    ['GET',  '/forecast',                'ForecastController@index',  'any'],
    ['GET',  '/forecast/new',            'ForecastController@create', 'admin'],
    ['GET',  '/forecast/{id}',           'ForecastController@show',   'any'],
    ['POST', '/forecast',                'ForecastController@store',  'admin'],
    ['GET',  '/forecast/{id}/edit',      'ForecastController@edit',   'admin'],
    ['POST', '/forecast/{id}',           'ForecastController@update', 'admin'],
    ['POST', '/forecast/{id}/delete',    'ForecastController@delete', 'admin'],
    ['POST', '/forecast/{id}/unpay',     'ForecastController@unpay',  'admin'],
    ['POST', '/forecast/{id}/close',     'ForecastController@close',  'admin'],
    ['POST', '/forecast/{id}/reopen',    'ForecastController@reopen', 'admin'],
    ['POST', '/forecast/{id}/link-debt',    'ForecastController@linkDebt',    'admin'],
    ['POST', '/forecast/{id}/link-invoice', 'ForecastController@linkInvoice', 'admin'],
    ['POST', '/forecast/{id}/make-debt',    'ForecastController@makeDebt',    'admin'],
    ['POST', '/forecast/{id}/unlink',       'ForecastController@unlink',      'admin'],

    // --- pengingat pembayaran (dashboard & ikon lonceng) ---
    ['GET',  '/api/reminders',           'ReminderController@data',   'any'],

    // --- catatan ---
    ['GET',  '/notes',                   'NoteController@index',  'any'],
    ['GET',  '/api/notes/{id}',          'NoteController@show',   'any'],
    ['POST', '/notes',                   'NoteController@store',  'admin'],
    ['POST', '/notes/{id}',              'NoteController@update', 'admin'],
    ['POST', '/notes/{id}/delete',       'NoteController@delete', 'admin'],

    // --- cetak: pratinjau (PDF/Excel) lalu unduh ---
    ['GET', '/export/{doc}/preview',  'ExportController@preview',  'any'],
    ['GET', '/export/{doc}/download', 'ExportController@download', 'any'],

    // --- administrasi (Super Admin saja) ---
    ['GET',  '/master',                  'MasterController@index',        'admin'],
    ['POST', '/master/categories',       'MasterController@saveCategory', 'admin'],
    ['POST', '/master/categories/{id}',  'MasterController@saveCategory', 'admin'],
    ['POST', '/master/projects',         'MasterController@saveProject',  'admin'],
    ['POST', '/master/projects/{id}',    'MasterController@saveProject',  'admin'],
    ['POST', '/master/accounts',         'MasterController@saveAccount',  'admin'],
    ['POST', '/master/accounts/{id}',    'MasterController@saveAccount',  'admin'],
    ['POST', '/master/accounts/{id}/delete', 'MasterController@deleteAccount', 'admin'],
    ['POST', '/master/counterparties',   'MasterController@saveCounterparty', 'admin'],
    ['POST', '/master/counterparties/{id}', 'MasterController@saveCounterparty', 'admin'],
    ['POST', '/master/parties',          'MasterController@saveParty',    'admin'],
    ['POST', '/master/parties/{id}',     'MasterController@saveParty',    'admin'],
    ['POST', '/api/parties',             'MasterController@createPartyApi', 'admin'],
    ['POST', '/master/suppliers',        'MasterController@saveSupplier', 'admin'],
    ['POST', '/master/suppliers/{id}',   'MasterController@saveSupplier', 'admin'],
    ['POST', '/master/opening/{id}',    'MasterController@saveOpening',  'admin'],
    ['POST', '/master/mapping/{id}',     'MasterController@saveMapping',  'admin'],

    ['GET',  '/import',                  'ImportController@index',     'admin'],
    // Impor Excel/CSV berpemetaan (unggah -> pemetaan -> pratinjau -> konfirmasi -> laporan)
    ['POST', '/import/upload',           'ImportController@upload',    'admin'],
    ['GET',  '/import/map',              'ImportController@map',       'admin'],
    ['POST', '/import/review',           'ImportController@review',    'admin'],
    ['GET',  '/import/review',           'ImportController@reviewShow','admin'],
    ['POST', '/import/run',              'ImportController@run',       'admin'],
    ['POST', '/import/abort',            'ImportController@abort',     'admin'],
    ['GET',  '/import/{id}/report',      'ImportController@report',    'admin'],
    ['GET',  '/import/{id}/errors',      'ImportController@errors',    'admin'],
    ['POST', '/import/preview',          'ImportController@preview',   'admin'],
    ['POST', '/import/commit',           'ImportController@commit',    'admin'],
    ['POST', '/import/cancel',           'ImportController@cancel',    'admin'],
    ['POST', '/import/{id}/void',        'ImportController@voidBatch', 'admin'],

    ['GET',  '/audit',                   'AuditController@index', 'admin'],

    ['GET',  '/users',                   'UserController@index',         'admin'],
    ['POST', '/users',                   'UserController@create',        'admin'],
    ['POST', '/users/{id}/role',         'UserController@setRole',       'admin'],
    ['POST', '/users/{id}/status',       'UserController@setStatus',     'admin'],
    ['POST', '/users/{id}/reset',        'UserController@resetPassword', 'admin'],

    ['GET',  '/settings',                'SettingsController@index',    'admin'],
    ['POST', '/settings',                'SettingsController@save',     'admin'],
    ['POST', '/settings/backup',         'SettingsController@backup',   'admin'],
    ['GET',  '/settings/backup/{file}',  'SettingsController@download', 'admin'],

    // --- akun sendiri ---
    ['GET',  '/password', 'AccountController@showPassword', 'any'],
    ['POST', '/password', 'AccountController@savePassword', 'any'],
];

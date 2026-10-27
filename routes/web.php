<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $info = [
        'message' => 'API is running',
        'env_host' => env('DB_HOST'),
        'env_port' => env('DB_PORT'),
        'env_database' => env('DB_DATABASE'),
        'env_user' => env('DB_USERNAME'),
        'env_ssl_ca' => env('MYSQL_ATTR_SSL_CA'),
        'storage_cert' => storage_path('certs/isrgrootx1.pem'),
        'storage_cert_exists' => file_exists(storage_path('certs/isrgrootx1.pem')),
        'database_cert' => base_path('database/isrgrootx1.pem'),
        'database_cert_exists' => file_exists(base_path('database/isrgrootx1.pem')),
    ];

    try {
        $count = \Illuminate\Support\Facades\DB::table('bidangs')->count();
        $info['db_status'] = 'CONNECTED';
        $info['bidangs_count'] = $count;
        return response()->json($info);
    } catch (\Throwable $e) {
        $info['db_status'] = 'FAILED';
        $info['error_message'] = $e->getMessage();
        $info['error_code'] = $e->getCode();
        return response()->json($info, 200);
    }
});

// Fallback route untuk menyajikan file dari storage/app/public jika symlink belum aktif di server/cloud deployment
Route::get('/storage/{path}', function ($path) {
    $filePath = storage_path('app/public/' . $path);
    if (!file_exists($filePath)) {
        abort(404, 'File not found');
    }
    return response()->file($filePath);
})->where('path', '.*');

require __DIR__.'/auth.php';

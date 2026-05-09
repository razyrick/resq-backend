<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Router;
use App\Core\Request;

date_default_timezone_set('Asia/Manila');
 
header("Access-Control-Allow-Origin: *"); 
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token");
header("Access-Control-Allow-Credentials: true");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/');
$dotenv->load();

$request = new Request();
$router = new Router($request);

// ====== AUTH START ====== //
$router->post('/auth/login', [App\Controllers\AuthController::class, 'login']);
$router->post('/auth/register', [App\Controllers\AuthController::class, 'register']);
$router->post('/auth/forgot-password', [App\Controllers\AuthController::class, 'forgotPassword']);
$router->post('/auth/reset-password', [App\Controllers\AuthController::class, 'resetPassword']);
$router->get('/auth/verify', [App\Controllers\AuthController::class, 'verifyEmail']);
// ====== AUTH END ====== //

// ====== USER START ====== //
$router->get('/user/profile', [App\Controllers\UserController::class, 'getProfile']);
$router->put('/user/profile', [App\Controllers\UserController::class, 'updateProfile']);
$router->get('/user/barangay', [App\Controllers\UserController::class, 'getBarangay']);

$router->post('/user/incident', [App\Controllers\UserController::class, 'createIncident']);
$router->get('/user/incident', [App\Controllers\UserController::class, 'getIncidents']);
$router->get('/user/incidents', [App\Controllers\UserController::class, 'getIncidentsByBaranggay']);

$router->get('/user/dashboard', [App\Controllers\UserController::class, 'dashboardStats']);
// ====== USER END ====== //

// ====== BARANGAY START ====== //
$router->get('/barangay/dashboard', [App\Controllers\BarangayController::class, 'getDashboardStats']);
$router->get('/barangay/profile', [App\Controllers\BarangayController::class, 'getProfile']);
$router->put('/barangay/profile', [App\Controllers\BarangayController::class, 'updateProfile']);
$router->get('/barangay/barangay', [App\Controllers\BarangayController::class, 'getBarangay']);
$router->get('/barangay/agency', [App\Controllers\BarangayController::class, 'getAgencies']);
$router->get('/barangay/incidents', [App\Controllers\BarangayController::class, 'getIncidents']);
$router->put('/barangay/incidents', [App\Controllers\BarangayController::class, 'updateIncidentStatus']);
$router->post('/barangay/officials', [App\Controllers\BarangayController::class, 'createBarangayOfficial']);
$router->get('/barangay/officials', [App\Controllers\BarangayController::class, 'getOfficials']);
$router->put('/barangay/officials', [App\Controllers\BarangayController::class, 'updateBarangayOfficial']);
$router->delete('/barangay/officials', [App\Controllers\BarangayController::class, 'deleteBarangayOfficial']);
$router->get('/barangay/users', [App\Controllers\BarangayController::class, 'getBarangayUsers']);
$router->put('/barangay/users', [App\Controllers\BarangayController::class, 'updateBarangayUserStatus']);
// ====== BARANGAY END ====== //

// ====== NOTIFICATIONS START ====== //
$router->get('/user/notifications', [App\Controllers\UserController::class, 'getNotifications']);
$router->get('/user/notifications/unread-count', [App\Controllers\UserController::class, 'getUnreadNotificationsCount']);
$router->put('/user/notifications/mark-read', [App\Controllers\UserController::class, 'markNotificationAsRead']);
$router->put('/user/notifications/mark-all-read', [App\Controllers\UserController::class, 'markAllNotificationsAsRead']);
$router->delete('/user/notifications/delete', [App\Controllers\UserController::class, 'deleteNotification']);
// ====== NOTIFICATIONS START ====== //

// ====== DISPATCHER START ====== //
$router->get('/dispatcher/incidents', [App\Controllers\DispatcherController::class, 'getIncidents']);
$router->put('/dispatcher/incidents', [App\Controllers\DispatcherController::class, 'updateIncidentStatus']);
$router->post('/dispatcher/agency', [App\Controllers\DispatcherController::class, 'createAgency']);
$router->get('/dispatcher/agency', [App\Controllers\DispatcherController::class, 'getAgencies']);
$router->put('/dispatcher/agency', [App\Controllers\DispatcherController::class, 'updateAgency']);
$router->delete('/dispatcher/agency', [App\Controllers\DispatcherController::class, 'deleteAgency']);
$router->get('/dispatcher/dashboard', [App\Controllers\DispatcherController::class, 'dashboardStats']);
$router->get('/dispatcher/users', [App\Controllers\DispatcherController::class, 'getDispatcherUsers']);
$router->put('/dispatcher/users', [App\Controllers\DispatcherController::class, 'updateDispatcherUserStatus']);
$router->post('/dispatcher/patients', [App\Controllers\DispatcherController::class, 'createPatient']);
// ====== DISPATCHER END ====== //

// ====== AGENCY START ====== //
$router->get('/agency/profile', [App\Controllers\AgencyController::class, 'getProfile']);
$router->put('/agency/profile', [App\Controllers\AgencyController::class, 'updateProfile']);
$router->get('/agency/agencies', [App\Controllers\AgencyController::class, 'getAgencies']);
$router->get('/agency/reports', [App\Controllers\AgencyController::class, 'getIncidents']);
$router->put('/agency/reports', [App\Controllers\AgencyController::class, 'updateIncidentStatus']);
$router->get('/agency/patients', [App\Controllers\AgencyController::class, 'getPatients']);
$router->put('/agency/patients', [App\Controllers\AgencyController::class, 'updatePatientStatus']);
$router->get('/agency/dashboard', [App\Controllers\AgencyController::class, 'getDashboardStats']);
// ====== AGENCY END ====== //

// ====== ADMIN START ====== //
$router->get('/admin/users', [App\Controllers\AdminController::class, 'getPendingUsers']);
$router->put('/admin/users', [App\Controllers\AdminController::class, 'updateUserStatus']);
$router->get('/admin/incidents', [App\Controllers\AdminController::class, 'getIncidents']);
// ====== ADMIN END ====== //


$router->resolve();

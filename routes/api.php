<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SpecialBillController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\HouseOccupantReplacementController;
use Illuminate\Support\Facades\Route;

Route::post('v1/login',[ApiController::class,'login'])->middleware('throttle:5,1');
Route::prefix('v1')->middleware('auth:sanctum')->group(function(){
    Route::post('logout',[ApiController::class,'logout']);
    Route::get('me', function (\Illuminate\Http\Request $request) {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ]);
    });
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::get('dashboard',[ApiController::class,'dashboard'])->middleware('permission:dashboard.view');
    Route::get('reports/{type}/export/{format}',[ReportController::class,'export'])->middleware('permission:reports.export');
    Route::get('reports/{type}',[ReportController::class,'show'])->middleware('permission:reports.view');
    Route::get('payments',[PaymentController::class,'index'])->middleware('permission:payments.view');
    Route::post('payments',[PaymentController::class,'store'])->middleware('permission:payments.create');
    Route::get('payments/{payment}',[PaymentController::class,'show'])->middleware('permission:payments.view');
    Route::post('payments/{payment}/cancel',[PaymentController::class,'cancel'])->middleware('permission:payments.cancel');
    Route::get('payments/{payment}/replacement-prefill',[PaymentController::class,'replacementPrefill'])->middleware('permission:payments.create');
    Route::post('payments/{payment}/replacement',[PaymentController::class,'replacement'])->middleware('permission:payments.create');
    Route::get('payment-proofs/{proof}/download',[PaymentController::class,'download'])->middleware('permission:payments.view');
    Route::get('expenses',[ExpenseController::class,'index'])->middleware('permission:expenses.view');
    Route::post('expenses',[ExpenseController::class,'store'])->middleware('permission:expenses.create');
    Route::get('expenses/{expense}',[ExpenseController::class,'show'])->middleware('permission:expenses.view');
    Route::post('expenses/{expense}/cancel',[ExpenseController::class,'cancel'])->middleware('permission:expenses.cancel');
    Route::get('expenses/{expense}/replacement-prefill',[ExpenseController::class,'replacementPrefill'])->middleware('permission:expenses.create');
    Route::post('expenses/{expense}/replacement',[ExpenseController::class,'replacement'])->middleware('permission:expenses.create');
    Route::get('expense-proofs/{proof}/download',[ExpenseController::class,'download'])->middleware('permission:expenses.view');
    Route::middleware('permission:roles.manage')->group(function () {
        Route::get('roles',[RolePermissionController::class,'index']);
        Route::post('roles',[RolePermissionController::class,'store']);
        Route::get('roles/{role}',[RolePermissionController::class,'show']);
        Route::put('roles/{role}',[RolePermissionController::class,'update']);
        Route::delete('roles/{role}',[RolePermissionController::class,'destroy']);
        Route::get('permissions',[RolePermissionController::class,'permissions']);
        Route::put('roles/{role}/permissions',[RolePermissionController::class,'updatePermissions']);
    });
    Route::get('settings',[SettingController::class,'index'])->middleware('permission:settings.manage');
    Route::put('settings',[SettingController::class,'update'])->middleware('permission:settings.manage');
    Route::get('tariffs/monthly',[ApiController::class,'monthlyTariffs']);
    Route::post('bills/generate-monthly',[ApiController::class,'generateMonthlyBills']);
    Route::get('bills/{bill}',[BillController::class,'show'])->middleware('permission:bills.view');
    Route::get('special-bills',[SpecialBillController::class,'index'])->middleware('permission:bills.view');
    Route::post('special-bills',[SpecialBillController::class,'store'])->middleware('permission:bills.create_special');
    Route::get('special-bills/{specialBill}',[SpecialBillController::class,'show'])->middleware('permission:bills.view');
    Route::post('special-bills/{specialBill}/approve',[SpecialBillController::class,'approve'])->middleware('permission:bills.approve_special');
    Route::post('special-bills/{specialBill}/cancel',[SpecialBillController::class,'cancel'])->middleware('permission:bills.cancel');
    Route::get('special-bill-documents/{document}/download',[SpecialBillController::class,'download'])->middleware('permission:bills.view');
    Route::get('residents/{resident}',[ResidentController::class,'show'])->middleware('permission:residents.view');
    Route::patch('residents/{resident}/personal',[ResidentController::class,'updatePersonal'])->middleware('permission:residents.update');
    Route::post('residents/{resident}/deactivate',[ResidentController::class,'deactivate'])->middleware('permission:residents.deactivate');
    Route::post('residents/{resident}/reactivate',[ResidentController::class,'reactivate'])->middleware('permission:residents.deactivate');
    Route::post('residents/{resident}/documents',[ApiController::class,'document'])->middleware('permission:residents.view_sensitive_documents');
    Route::get('houses/{house}/occupant-replacement',[HouseOccupantReplacementController::class,'context'])->middleware('permission:houses.manage_occupants');
    Route::post('houses/{house}/replace-occupants',[HouseOccupantReplacementController::class,'replace'])->middleware('permission:houses.manage_occupants');
    Route::get('documents/{document}/download',[ApiController::class,'download'])->middleware('permission:residents.view_sensitive_documents');
    Route::get('{entity}',[ApiController::class,'index']);
    Route::post('{entity}',[ApiController::class,'store'])->where('entity','^(?!bills$).*$');
    Route::get('{entity}/{id}',[ApiController::class,'show']);
    Route::match(['put','patch'],'{entity}/{id}',[ApiController::class,'update'])->where('entity','(?!bills(?:/|$)|special-bills(?:/|$)).*');
    Route::delete('{entity}/{id}',[ApiController::class,'destroy'])->where('entity','(?!residents(?:/|$)|bills(?:/|$)|special-bills(?:/|$)).*');
});

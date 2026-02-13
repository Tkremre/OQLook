<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OQLike\DashboardController;
use App\Http\Controllers\OQLike\ConnectionWizardController;
use App\Http\Controllers\OQLike\IssueController;
use App\Http\Controllers\OQLike\IssueAcknowledgementController;
use App\Http\Controllers\OQLike\ScanController;
use App\Http\Controllers\OQLike\ExportController;
use App\Http\Controllers\OQLike\SettingsController;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::get('/connections/wizard', [ConnectionWizardController::class, 'index'])->name('connections.wizard');
Route::post('/connections', [ConnectionWizardController::class, 'store'])->name('connections.store');
Route::put('/connections/{connection}', [ConnectionWizardController::class, 'update'])->name('connections.update');
Route::post('/connections/test-itop-draft', [ConnectionWizardController::class, 'testDraftItop'])->name('connections.test-itop-draft');
Route::post('/connections/test-connector-draft', [ConnectionWizardController::class, 'testDraftConnector'])->name('connections.test-connector-draft');
Route::post('/connections/{connection}/test-itop', [ConnectionWizardController::class, 'testItop'])->name('connections.test-itop');
Route::post('/connections/{connection}/test-connector', [ConnectionWizardController::class, 'testConnector'])->name('connections.test-connector');
Route::post('/connections/{connection}/scan', [ScanController::class, 'store'])->name('connections.scan');
Route::post('/connections/{connection}/discover-classes', [ScanController::class, 'discoverClasses'])->name('connections.discover-classes');
Route::get('/connections/{connection}/scan-log', [ScanController::class, 'scanLog'])->name('connections.scan-log');
Route::post('/scans/{scan}/resume', [ScanController::class, 'resume'])->whereNumber('scan')->name('scans.resume');
Route::delete('/scans/{scan}', [ScanController::class, 'destroy'])->whereNumber('scan')->name('scans.destroy');

Route::get('/issues/{scan?}', [IssueController::class, 'index'])->whereNumber('scan')->name('issues.index');
Route::get('/issue/{issue}', [IssueController::class, 'show'])->whereNumber('issue')->name('issues.show');
Route::get('/issue/{issue}/objects', [IssueController::class, 'impactedObjects'])->whereNumber('issue')->name('issues.objects');
Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::post('/issues/{issue}/acknowledge', [IssueAcknowledgementController::class, 'acknowledgeIssue'])->whereNumber('issue')->name('issues.acknowledge');
Route::delete('/issues/{issue}/acknowledge', [IssueAcknowledgementController::class, 'deacknowledgeIssue'])->whereNumber('issue')->name('issues.deacknowledge');
Route::post('/issues/{issue}/objects/acknowledge', [IssueAcknowledgementController::class, 'acknowledgeObject'])->whereNumber('issue')->name('issues.objects.acknowledge');
Route::delete('/issues/{issue}/objects/acknowledge', [IssueAcknowledgementController::class, 'deacknowledgeObject'])->whereNumber('issue')->name('issues.objects.deacknowledge');
Route::delete('/acknowledgements/{acknowledgement}', [IssueAcknowledgementController::class, 'destroy'])->whereNumber('acknowledgement')->name('acknowledgements.destroy');

Route::get('/scans/{scan}/export/json', [ExportController::class, 'json'])->whereNumber('scan')->name('exports.json');
Route::get('/scans/{scan}/export/csv', [ExportController::class, 'csv'])->whereNumber('scan')->name('exports.csv');
Route::get('/scans/{scan}/export/pdf', [ExportController::class, 'pdf'])->whereNumber('scan')->name('exports.pdf');

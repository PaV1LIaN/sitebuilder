<?php

global $USER;

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/OutboxService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/ExternalJobWorker.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/QueueMonitorService.php';

if (!function_exists('sb_job_require_site_manager')) {
    function sb_job_require_site_manager(int $siteId): void
    {
        global $USER;
        if ($USER && $USER->IsAdmin()) {
            return;
        }
        if ($siteId <= 0) {
            sb_json_error('SITE_ID_REQUIRED', 422);
        }
        sb_require_site_role($siteId, 3);
    }
}

if (!function_exists('sb_job_require_access')) {
    function sb_job_require_access(array $job): void
    {
        global $USER;
        if ($USER && $USER->IsAdmin()) {
            return;
        }
        sb_job_require_site_manager((int)($job['siteId'] ?? 0));
    }
}

if ($action === 'job.health') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if (!$USER->IsAdmin()) {
        sb_job_require_site_manager($siteId);
    }
    sb_json_ok(['health' => QueueMonitorService::health($siteId)]);
}

if ($action === 'job.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if (!$USER->IsAdmin() && $siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }
    if ($siteId > 0) {
        sb_job_require_site_manager($siteId);
    }

    try {
        sb_json_ok(OutboxService::list([
            'siteId' => $siteId,
            'status' => (string)($_POST['status'] ?? ''),
            'jobType' => (string)($_POST['jobType'] ?? ''),
            'limit' => (int)($_POST['limit'] ?? 100),
            'offset' => (int)($_POST['offset'] ?? 0),
        ]));
    } catch (InvalidArgumentException $e) {
        sb_json_error($e->getMessage(), 422);
    }
}

if ($action === 'job.get') {
    $jobId = (int)($_POST['jobId'] ?? 0);
    if ($jobId <= 0) {
        sb_json_error('JOB_ID_REQUIRED', 422);
    }
    $job = OutboxService::get($jobId);
    if (!$job) {
        sb_json_error('JOB_NOT_FOUND', 404);
    }
    sb_job_require_access($job);
    sb_json_ok(['job' => $job, 'events' => OutboxService::events($jobId)]);
}

if ($action === 'job.retry') {
    $jobId = (int)($_POST['jobId'] ?? 0);
    if ($jobId <= 0) {
        sb_json_error('JOB_ID_REQUIRED', 422);
    }
    $job = OutboxService::get($jobId);
    if (!$job) {
        sb_json_error('JOB_NOT_FOUND', 404);
    }
    sb_job_require_access($job);
    try {
        sb_json_ok(['job' => OutboxService::retry($jobId, (int)$USER->GetID())]);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'JOB_NOT_RETRYABLE') {
            sb_json_error('JOB_NOT_RETRYABLE', 409);
        }
        throw $e;
    }
}

if ($action === 'job.cancel') {
    $jobId = (int)($_POST['jobId'] ?? 0);
    if ($jobId <= 0) {
        sb_json_error('JOB_ID_REQUIRED', 422);
    }
    $job = OutboxService::get($jobId);
    if (!$job) {
        sb_json_error('JOB_NOT_FOUND', 404);
    }
    sb_job_require_access($job);
    try {
        sb_json_ok(['job' => OutboxService::cancel($jobId, (int)$USER->GetID())]);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'JOB_NOT_CANCELLABLE') {
            sb_json_error('JOB_NOT_CANCELLABLE', 409);
        }
        throw $e;
    }
}

if ($action === 'job.run') {
    if (!$USER->IsAdmin()) {
        sb_json_error('BITRIX_ADMIN_REQUIRED', 403);
    }
    $limit = (int)($_POST['limit'] ?? 20);
    sb_json_ok(['result' => ExternalJobWorker::runBatch($limit)]);
}

sb_json_error('NOT_MOVED_YET', 501, ['handler' => 'job', 'action' => $action]);

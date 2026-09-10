<?php

declare(strict_types=1);

namespace App\Controllers;

use App\BigStore\BigStoreClient;
use App\BigStore\BigStoreException;
use App\Core\Request;
use App\Core\Response;
use Exception;

class FileController
{
    private BigStoreClient $client;

    public function __construct(?BigStoreClient $client = null)
    {
        $this->client = $client ?? new BigStoreClient();
    }

    public function list(Request $request): void
    {
        $user = $request->getUser();
        $directoryId = $request->getQuery('directory_id');
        if ($directoryId === '') {
            $directoryId = null;
        }

        try {
            $directories = $this->client->listDirectories('user', $user['id'], $directoryId);
            $files = $this->client->listFiles('user', $user['id'], $directoryId);
            $quota = $this->client->getStorageQuota('user', $user['id']);

            Response::success([
                'current_directory_id' => $directoryId,
                'directories' => $directories,
                'files' => $files,
                'quota' => $quota,
            ]);
        } catch (BigStoreException $e) {
            Response::error($e->getErrorCode(), $e->getMessage(), $e->getCode());
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    private function checkSubscriptionAccess(array $user): bool
    {
        $roles = $user['roles'] ?? [];
        if (in_array('ADMIN', $roles, true) || in_array('PARTNER', $roles, true)) {
            return true;
        }

        $subscriptionService = new \App\Services\SubscriptionService();
        return $subscriptionService->hasActiveSubscription($user['id']);
    }

    public function createDirectory(Request $request): void
    {
        $user = $request->getUser();
        if (!$this->checkSubscriptionAccess($user)) {
            Response::error('SUBSCRIPTION_REQUIRED', 'An active subscription is required to store files.', 403);
            return;
        }

        $name = trim((string) ($request->getBody('name') ?? ''));
        $parentId = $request->getBody('parent_id') ?: null;

        if (empty($name)) {
            Response::error('VALIDATION_ERROR', 'Directory name is required.', 400);
        }

        try {
            $dir = $this->client->createDirectory('user', $user['id'], $name, $parentId);
            Response::success($dir, 201);
        } catch (BigStoreException $e) {
            Response::error($e->getErrorCode(), $e->getMessage(), $e->getCode());
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function deleteDirectory(Request $request, array $params): void
    {
        $user = $request->getUser();
        $id = $params['id'] ?? '';

        if (empty($id)) {
            Response::error('VALIDATION_ERROR', 'Directory ID is required.', 400);
        }

        try {
            $result = $this->client->deleteDirectory($id, 'user', $user['id']);
            Response::success($result);
        } catch (BigStoreException $e) {
            Response::error($e->getErrorCode(), $e->getMessage(), $e->getCode());
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function initUpload(Request $request): void
    {
        $user = $request->getUser();
        if (!$this->checkSubscriptionAccess($user)) {
            Response::error('SUBSCRIPTION_REQUIRED', 'An active subscription is required to store files.', 403);
            return;
        }

        $originalName = trim((string) ($request->getBody('original_name') ?? $request->getBody('name') ?? ''));
        $totalSizeBytes = $request->getBody('total_size_bytes') ?? $request->getBody('size');
        $totalChunks = (int) ($request->getBody('total_chunks') ?? 1);
        $mimeType = (string) ($request->getBody('mime_type') ?? 'application/octet-stream');
        $directoryId = $request->getBody('directory_id') ?: null;
        $sha256 = $request->getBody('sha256') ?: null;

        if (empty($originalName)) {
            Response::error('VALIDATION_ERROR', 'File name is required.', 400);
        }

        if ($totalSizeBytes === null || !is_numeric($totalSizeBytes) || (int) $totalSizeBytes < 0) {
            Response::error('VALIDATION_ERROR', 'Valid file size in bytes is required.', 400);
        }

        try {
            $session = $this->client->initUpload([
                'owner_type' => 'user',
                'owner_id' => $user['id'],
                'directory_id' => $directoryId,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'total_size_bytes' => (int) $totalSizeBytes,
                'total_chunks' => max(1, $totalChunks),
                'sha256' => $sha256,
            ]);

            Response::success($session, 201);
        } catch (BigStoreException $e) {
            Response::error($e->getErrorCode(), $e->getMessage(), $e->getCode(), $e->getDetails());
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function uploadChunk(Request $request, array $params): void
    {
        $user = $request->getUser();
        $uploadId = $params['id'] ?? '';
        $chunkIndex = (int) ($params['index'] ?? 0);
        $chunkData = $request->getRawBody();

        if (empty($uploadId)) {
            Response::error('VALIDATION_ERROR', 'Upload session ID is required.', 400);
        }

        try {
            $result = $this->client->uploadChunk($uploadId, $chunkIndex, $chunkData);
            Response::success($result);
        } catch (BigStoreException $e) {
            Response::error($e->getErrorCode(), $e->getMessage(), $e->getCode());
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function finalizeUpload(Request $request, array $params): void
    {
        $user = $request->getUser();
        $uploadId = $params['id'] ?? '';
        $expectedSha256 = $request->getBody('expected_sha256') ?: null;

        if (empty($uploadId)) {
            Response::error('VALIDATION_ERROR', 'Upload session ID is required.', 400);
        }

        try {
            $file = $this->client->finalizeUpload($uploadId, $expectedSha256);
            Response::success($file);
        } catch (BigStoreException $e) {
            Response::error($e->getErrorCode(), $e->getMessage(), $e->getCode());
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function directUpload(Request $request): void
    {
        $user = $request->getUser();
        if (!$this->checkSubscriptionAccess($user)) {
            Response::error('SUBSCRIPTION_REQUIRED', 'An active subscription is required to store files.', 403);
            return;
        }

        if (empty($_FILES['file']) || !isset($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('UPLOAD_FAILED', 'No file was uploaded or file upload encountered an error.', 400);
        }

        $uploaded = $_FILES['file'];
        $tmpPath = $uploaded['tmp_name'];
        $originalName = basename($uploaded['name']);
        $sizeBytes = (int) $uploaded['size'];
        $mimeType = $uploaded['type'] ?: 'application/octet-stream';
        $directoryId = $_POST['directory_id'] ?? null;
        if ($directoryId === '') {
            $directoryId = null;
        }

        try {
            // 1. Init session
            $session = $this->client->initUpload([
                'owner_type' => 'user',
                'owner_id' => $user['id'],
                'directory_id' => $directoryId,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'total_size_bytes' => $sizeBytes,
                'total_chunks' => 1,
            ]);

            // 2. Upload sole chunk
            $fileContent = file_get_contents($tmpPath);
            $this->client->uploadChunk($session['upload_id'], 0, $fileContent);

            // 3. Finalize session
            $file = $this->client->finalizeUpload($session['upload_id']);

            Response::success($file, 201);
        } catch (BigStoreException $e) {
            Response::error($e->getErrorCode(), $e->getMessage(), $e->getCode());
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function show(Request $request, array $params): void
    {
        $user = $request->getUser();
        $id = $params['id'] ?? '';

        try {
            $file = $this->client->getFile($id, 'user', $user['id']);
            if (!$file) {
                Response::error('FILE_NOT_FOUND', 'File not found.', 404);
            }
            Response::success($file);
        } catch (BigStoreException $e) {
            Response::error($e->getErrorCode(), $e->getMessage(), $e->getCode());
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function download(Request $request, array $params): void
    {
        $user = $request->getUser();
        $id = $params['id'] ?? '';

        $file = $this->client->getFile($id, 'user', $user['id']);
        if (!$file) {
            Response::error('FILE_NOT_FOUND', 'File not found.', 404);
        }

        $this->client->streamFile($id, 'user', $user['id'], true, $request->getHeader('range'));
        exit;
    }

    public function stream(Request $request, array $params): void
    {
        $user = $request->getUser();
        $id = $params['id'] ?? '';

        $file = $this->client->getFile($id, 'user', $user['id']);
        if (!$file) {
            Response::error('FILE_NOT_FOUND', 'File not found.', 404);
        }

        $this->client->streamFile($id, 'user', $user['id'], false, $request->getHeader('range'));
        exit;
    }

    public function delete(Request $request, array $params): void
    {
        $user = $request->getUser();
        $id = $params['id'] ?? '';

        try {
            $result = $this->client->deleteFile($id, 'user', $user['id']);
            Response::success($result);
        } catch (BigStoreException $e) {
            Response::error($e->getErrorCode(), $e->getMessage(), $e->getCode());
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    public function quota(Request $request): void
    {
        $user = $request->getUser();

        try {
            $quota = $this->client->getStorageQuota('user', $user['id']);
            Response::success($quota);
        } catch (BigStoreException $e) {
            Response::error($e->getErrorCode(), $e->getMessage(), $e->getCode());
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\BigStore\BigStoreClient;
use App\Core\Request;
use App\Core\Response;
use App\Services\ShareService;
use Exception;
use InvalidArgumentException;

class ShareController
{
    private ShareService $shareService;
    private BigStoreClient $bigStore;

    public function __construct(?ShareService $shareService = null, ?BigStoreClient $bigStore = null)
    {
        $this->shareService = $shareService ?? new ShareService();
        $this->bigStore = $bigStore ?? new BigStoreClient();
    }

    /**
     * Create a new share link (Authenticated)
     * POST /api/v1/shares
     */
    public function create(Request $request): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHORIZED', 'Authentication required.', 401);
            return;
        }

        $body = $request->getBody() ?? [];

        try {
            $share = $this->shareService->createShare($user['id'], $body);
            Response::success($share, 201);
        } catch (InvalidArgumentException $e) {
            Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * List user's created shares (Authenticated)
     * GET /api/v1/shares
     */
    public function list(Request $request): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHORIZED', 'Authentication required.', 401);
            return;
        }

        $limit = (int) ($request->getQuery('limit') ?: 50);
        $offset = (int) ($request->getQuery('offset') ?: 0);

        try {
            $result = $this->shareService->listUserShares($user['id'], $limit, $offset);
            Response::success($result);
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Get details of a single share (Authenticated owner or admin)
     * GET /api/v1/shares/{id}
     */
    public function show(Request $request, array $params): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHORIZED', 'Authentication required.', 401);
            return;
        }

        $shareId = $params['id'] ?? '';
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $share = $this->shareService->getShare($shareId, $user['id'], $isAdmin);
            if (!$share) {
                Response::error('NOT_FOUND', 'Share link not found.', 404);
                return;
            }
            Response::success($share);
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Update share configuration (Authenticated owner or admin)
     * PATCH /api/v1/shares/{id}
     */
    public function update(Request $request, array $params): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHORIZED', 'Authentication required.', 401);
            return;
        }

        $shareId = $params['id'] ?? '';
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);
        $body = $request->getBody() ?? [];

        try {
            $updated = $this->shareService->updateShare($shareId, $user['id'], $body, $isAdmin);
            Response::success($updated);
        } catch (InvalidArgumentException $e) {
            Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Revoke a share link (Authenticated owner or admin)
     * DELETE /api/v1/shares/{id}
     */
    public function revoke(Request $request, array $params): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHORIZED', 'Authentication required.', 401);
            return;
        }

        $shareId = $params['id'] ?? '';
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $revoked = $this->shareService->revokeShare($shareId, $user['id'], $isAdmin);
            if (!$revoked) {
                Response::error('NOT_FOUND', 'Share link not found or already revoked.', 404);
                return;
            }
            Response::success(['revoked' => true, 'share_id' => $shareId]);
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Public share landing metadata
     * GET /api/v1/s/{token}
     */
    public function getPublic(Request $request, array $params): void
    {
        $token = $params['token'] ?? '';
        if (empty($token)) {
            Response::error('VALIDATION_ERROR', 'Share token is required.', 400);
            return;
        }

        try {
            $result = $this->shareService->resolvePublicShare($token);
            switch ($result['status']) {
                case 'NOT_FOUND':
                    Response::error('NOT_FOUND', 'Share link does not exist.', 404);
                    return;
                case 'REVOKED':
                    Response::error('SHARE_REVOKED', 'This share link has been revoked by the owner.', 410);
                    return;
                case 'EXPIRED':
                    Response::error('SHARE_EXPIRED', 'This share link has expired.', 410);
                    return;
                case 'LIMIT_EXCEEDED':
                    Response::error('DOWNLOAD_LIMIT_EXCEEDED', 'The maximum download limit for this link has been reached.', 410);
                    return;
                case 'ACTIVE':
                    Response::success($result['metadata']);
                    return;
                default:
                    Response::error('UNKNOWN_STATUS', 'Unable to resolve share link.', 500);
                    return;
            }
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Unlock password-protected share
     * POST /api/v1/s/{token}/unlock
     */
    public function unlock(Request $request, array $params): void
    {
        $token = $params['token'] ?? '';
        $body = $request->getBody() ?? [];
        $password = (string) ($body['password'] ?? '');

        if (empty($token)) {
            Response::error('VALIDATION_ERROR', 'Share token is required.', 400);
            return;
        }

        try {
            $result = $this->shareService->unlockWithPassword($token, $password);
            if (!$result['valid']) {
                Response::error($result['error'], $result['message'], 401);
                return;
            }

            Response::success($result);
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Stream file download for a share link
     * GET /api/v1/s/{token}/download
     */
    public function download(Request $request, array $params): void
    {
        $token = $params['token'] ?? '';
        $rawPassword = $request->getHeader('x-share-password') ?: $request->getQuery('password');
        $unlockToken = $request->getHeader('x-share-token') ?: $request->getQuery('token');

        $auth = $this->shareService->authorizeAccess($token, $rawPassword, $unlockToken, true);
        if (!$auth['allowed']) {
            Response::error($auth['code'], $auth['message'], $auth['status']);
            return;
        }

        $share = $auth['share'];
        if (!$share['file_id']) {
            Response::error('NOT_A_FILE', 'Direct file download is not available for folder shares.', 400);
            return;
        }

        // Increment download counter
        $this->shareService->recordDownload($share['id']);

        // Proxy file download from BigStore directly to client
        $this->bigStore->streamFile(
            $share['file_id'],
            'user',
            $share['user_id'],
            true,
            $request->getHeader('range')
        );
        exit;
    }

    /**
     * Stream media inline for a share link
     * GET /api/v1/s/{token}/stream
     */
    public function stream(Request $request, array $params): void
    {
        $token = $params['token'] ?? '';
        $rawPassword = $request->getHeader('x-share-password') ?: $request->getQuery('password');
        $unlockToken = $request->getHeader('x-share-token') ?: $request->getQuery('token');

        $auth = $this->shareService->authorizeAccess($token, $rawPassword, $unlockToken, false);
        if (!$auth['allowed']) {
            Response::error($auth['code'], $auth['message'], $auth['status']);
            return;
        }

        $share = $auth['share'];
        if (!$share['file_id']) {
            Response::error('NOT_A_FILE', 'Media stream is only available for individual files.', 400);
            return;
        }

        // Stream inline from BigStore
        $this->bigStore->streamFile(
            $share['file_id'],
            'user',
            $share['user_id'],
            false,
            $request->getHeader('range')
        );
        exit;
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\BigStore\BigStoreClient;
use App\Core\Request;
use App\Core\Response;
use App\Services\MovieService;
use Exception;

class MovieController
{
    private MovieService $movieService;
    private BigStoreClient $bigStore;

    public function __construct(?MovieService $movieService = null, ?BigStoreClient $bigStore = null)
    {
        $this->movieService = $movieService ?? new MovieService();
        $this->bigStore = $bigStore ?? new BigStoreClient();
    }

    /**
     * List user movies
     * GET /api/v1/movies
     */
    public function index(Request $request): void
    {
        $user = $request->getUser();
        $search = $request->getQuery('search');
        $genre = $request->getQuery('genre');
        $sort = $request->getQuery('sort', 'created_at');
        $order = $request->getQuery('order', 'DESC');
        $limit = (int)$request->getQuery('limit', 50);
        $offset = (int)$request->getQuery('offset', 0);

        try {
            $result = $this->movieService->listMovies(
                $user['id'],
                $search,
                $genre,
                $sort,
                $order,
                $limit,
                $offset
            );
            Response::success($result);
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Get single movie details
     * GET /api/v1/movies/{id}
     */
    public function show(Request $request, array $params): void
    {
        $user = $request->getUser();
        $movieId = $params['id'] ?? '';
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $movie = $this->movieService->getMovie($user['id'], $movieId, $isAdmin);
            if (!$movie) {
                Response::error('NOT_FOUND', 'Movie not found or access denied.', 404);
                return;
            }
            Response::success($movie);
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Scan user storage for media files and auto-detect metadata
     * POST /api/v1/movies/scan
     */
    public function scan(Request $request): void
    {
        $user = $request->getUser();
        $body = $request->getBody() ?? [];
        $directoryId = $body['directory_id'] ?? null;

        try {
            $result = $this->movieService->scanUserMedia($user['id'], $directoryId);
            Response::success($result);
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Update/correct movie metadata
     * PATCH /api/v1/movies/{id} or POST /api/v1/movies/{id}/metadata
     */
    public function update(Request $request, array $params): void
    {
        $user = $request->getUser();
        $movieId = $params['id'] ?? '';
        $data = $request->getBody() ?? [];
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $updated = $this->movieService->updateMovie($user['id'], $movieId, $data, $isAdmin);
            if (!$updated) {
                Response::error('NOT_FOUND', 'Movie not found or access denied.', 404);
                return;
            }
            Response::success($updated);
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Delete movie entry
     * DELETE /api/v1/movies/{id}
     */
    public function destroy(Request $request, array $params): void
    {
        $user = $request->getUser();
        $movieId = $params['id'] ?? '';
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $deleted = $this->movieService->deleteMovie($user['id'], $movieId, $isAdmin);
            if (!$deleted) {
                Response::error('NOT_FOUND', 'Movie not found or access denied.', 404);
                return;
            }
            Response::success(['deleted' => true, 'movie_id' => $movieId]);
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Generate short-lived streaming token
     * POST /api/v1/movies/{id}/stream-token
     */
    public function createStreamToken(Request $request, array $params): void
    {
        $user = $request->getUser();
        $movieId = $params['id'] ?? '';

        try {
            $tokenInfo = $this->movieService->createStreamToken($user['id'], $movieId);
            Response::success($tokenInfo);
        } catch (Exception $e) {
            Response::error('STREAM_TOKEN_ERROR', $e->getMessage(), 403);
        }
    }

    /**
     * Public/Authorized HTTP Range Stream via Token (Spec Section 19)
     * GET /api/v1/media/stream/{token}
     */
    public function streamMedia(Request $request, array $params): void
    {
        $token = $params['token'] ?? '';
        if (empty($token)) {
            Response::error('INVALID_TOKEN', 'Streaming token is required.', 400);
            return;
        }

        $tokenData = $this->movieService->verifyStreamToken($token);
        if (!$tokenData) {
            Response::error('TOKEN_EXPIRED', 'Streaming token has expired or is invalid.', 403);
            return;
        }

        // Stream direct from BigStore via HTTP Range
        $this->bigStore->streamFile(
            $tokenData['file_id'],
            $tokenData['owner_type'],
            $tokenData['owner_id'],
            false,
            $request->getHeader('range')
        );
        exit;
    }

    /**
     * Designate Movie Folder
     * POST /api/v1/movies/folders
     */
    public function addFolder(Request $request): void
    {
        $user = $request->getUser();
        $body = $request->getBody() ?? [];
        $dirId = $body['directory_id'] ?? '';

        if (empty($dirId)) {
            Response::error('VALIDATION_ERROR', 'directory_id is required.', 400);
            return;
        }

        $this->movieService->designateMovieFolder($user['id'], $dirId);
        Response::success(['designated' => true, 'directory_id' => $dirId]);
    }

    /**
     * List designated Movie Folders
     * GET /api/v1/movies/folders
     */
    public function listFolders(Request $request): void
    {
        $user = $request->getUser();
        $folders = $this->movieService->listMovieFolders($user['id']);
        Response::success(['folders' => $folders]);
    }
}

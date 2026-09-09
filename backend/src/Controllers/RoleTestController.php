<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;

class RoleTestController
{
    public function customerPing(Request $request): void
    {
        $user = $request->getUser();
        Response::success([
            'message' => 'Customer area access verified.',
            'user_id' => $user['id'] ?? null,
            'username' => $user['username'] ?? null,
            'roles' => $user['roles'] ?? [],
        ]);
    }

    public function partnerPing(Request $request): void
    {
        $user = $request->getUser();
        Response::success([
            'message' => 'Partner area access verified.',
            'user_id' => $user['id'] ?? null,
            'username' => $user['username'] ?? null,
            'roles' => $user['roles'] ?? [],
        ]);
    }

    public function adminPing(Request $request): void
    {
        $user = $request->getUser();
        Response::success([
            'message' => 'Admin area access verified.',
            'user_id' => $user['id'] ?? null,
            'username' => $user['username'] ?? null,
            'roles' => $user['roles'] ?? [],
        ]);
    }

    public function listUsers(Request $request): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('
            SELECT u.id, u.username, u.email, u.status, u.created_at,
                   GROUP_CONCAT(ur.role_id, ",") as roles_csv
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $users = array_map(function ($row) {
            $row['roles'] = $row['roles_csv'] ? explode(',', $row['roles_csv']) : [];
            unset($row['roles_csv']);
            return $row;
        }, $rows);

        Response::success([
            'total' => count($users),
            'users' => $users,
        ]);
    }
}

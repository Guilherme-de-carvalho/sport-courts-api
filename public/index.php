<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Controllers\SportsController;
use App\Controllers\AvailabilityController;
use App\Controllers\AuthController;
use App\Controllers\ReservationsController;

// CORS para consumo pelo Android/Frontend
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

// Responder preflight do navegador/Android
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Normalize quando projeto está em subpasta (e.g. /sport-courts-api/public)
$script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if ($script !== '/' && strpos($uri, $script) === 0) {
    $uri = substr($uri, strlen($script));
}
$uri = '/' . trim($uri, '/');

try {
    $db = new Database();
    $pdo = $db->getConnection();

    // Healthcheck opcional na raiz
    if ($method === 'GET' && ($uri === '/' || $uri === '')) {
        echo json_encode([
            'status' => 'ok',
            'service' => 'sport-courts-api',
            'time' => date('c'),
        ]);
        exit;
    }

    // Sports - listagem
    if ($method === 'GET' && $uri === '/sports') {
        $ctrl = new SportsController($pdo);
        echo json_encode(['status' => 'success', 'data' => $ctrl->index()]);
        exit;
    }

    // Availability
    if ($method === 'GET' && $uri === '/availability') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $clubId = isset($_GET['club_id']) ? (int)$_GET['club_id'] : null;
        $sportId = isset($_GET['sport_id']) ? (int)$_GET['sport_id'] : null;
        $ctrl = new AvailabilityController($pdo);
        echo json_encode(['status' => 'success', 'data' => $ctrl->getAvailability($date, $clubId, $sportId)]);
        exit;
    }

    // Auth register
    if ($method === 'POST' && $uri === '/auth/register') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $ctrl = new AuthController($pdo);
        echo json_encode($ctrl->register($input));
        exit;
    }

    // Auth login
    if ($method === 'POST' && $uri === '/auth/login') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $ctrl = new AuthController($pdo);
        echo json_encode($ctrl->login($input));
        exit;
    }

    // Create reservation
    if ($method === 'POST' && $uri === '/reservations') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $ctrl = new ReservationsController($pdo);
        echo json_encode($ctrl->create($input));
        exit;
    }

    // List "my" reservations (mantém comportamento existente)
    if ($method === 'GET' && $uri === '/reservations') {
        $mine = isset($_GET['mine']) && ($_GET['mine'] === 'true' || $_GET['mine'] === '1');
        if ($mine) {
            $ctrl = new ReservationsController($pdo);
            echo json_encode(['status' => 'success', 'data' => $ctrl->mine()]);
            exit;
        }

        // NOVO: lista geral (sem mine=true)
        // Filtros opcionais: user_id, date_from, date_to
        $where = [];
        $params = [];

        if (isset($_GET['user_id'])) {
            $where[] = 'r.user_id = ?';
            $params[] = (int)$_GET['user_id'];
        }
        if (isset($_GET['date_from'])) {
            $where[] = 'r.start_datetime >= ?';
            $params[] = $_GET['date_from'];
        }
        if (isset($_GET['date_to'])) {
            $where[] = 'r.end_datetime <= ?';
            $params[] = $_GET['date_to'];
        }

        $sql = 'SELECT r.* FROM reservations r';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY r.start_datetime DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        echo json_encode(['status' => 'success', 'data' => $rows]);
        exit;
    }

    // NOVO: detalhe de reserva /reservations/{id}
    if ($method === 'GET' && preg_match('#^/reservations/(\d+)$#', $uri, $m)) {
        $id = (int)$m[1];
        $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'error' => ['code' => 'NOT_FOUND', 'message' => 'Reserva não encontrada']]);
            exit;
        }
        echo json_encode(['status' => 'success', 'data' => $row]);
        exit;
    }

    // Cancel reservation - mantém
    if ($method === 'PUT' && preg_match('#^/reservations/(\d+)/cancel$#', $uri, $m)) {
        $id = (int)$m[1];
        $ctrl = new ReservationsController($pdo);
        echo json_encode($ctrl->cancel($id));
        exit;
    }

    // NOVO: PUT /reservations/{id} (atualização completa)
    if ($method === 'PUT' && preg_match('#^/reservations/(\d+)$#', $uri, $m)) {
        $id = (int)$m[1];
        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        $required = ['user_id','court_id','start_datetime','end_datetime'];
        foreach ($required as $k) {
            if (!isset($input[$k]) || $input[$k] === '') {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'error' => ['code' => 'VALIDATION', 'message' => "$k é obrigatório"]]);
                exit;
            }
        }

        // status e total são opcionais
        $status = $input['status'] ?? null;
        $total  = $input['total']  ?? null;

        $sql = 'UPDATE reservations SET user_id = ?, court_id = ?, start_datetime = ?, end_datetime = ?';
        $params = [(int)$input['user_id'], (int)$input['court_id'], $input['start_datetime'], $input['end_datetime']];

        if ($status !== null) {
            $sql .= ', status = ?';
            $params[] = $status;
        }
        if ($total !== null) {
            $sql .= ', total = ?';
            $params[] = $total;
        }
        $sql .= ' WHERE id = ?';
        $params[] = $id;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['status' => 'success', 'data' => ['id' => $id]]);
        exit;
    }

    // NOVO: PATCH /reservations/{id} (atualização parcial)
    if ($method === 'PATCH' && preg_match('#^/reservations/(\d+)$#', $uri, $m)) {
        $id = (int)$m[1];
        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        $allowed = ['user_id','court_id','start_datetime','end_datetime','status','total'];
        $set = [];
        $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $set[] = "$field = ?";
                $params[] = $input[$field];
            }
        }
        if (empty($set)) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'error' => ['code' => 'VALIDATION', 'message' => 'Nenhum campo para atualizar']]);
            exit;
        }
        $params[] = $id;
        $sql = 'UPDATE reservations SET ' . implode(', ', $set) . ' WHERE id = ?';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['status' => 'success', 'data' => ['id' => $id]]);
        exit;
    }

    // NOVO: DELETE /reservations/{id}
    if ($method === 'DELETE' && preg_match('#^/reservations/(\d+)$#', $uri, $m)) {
        $id = (int)$m[1];

        $stmt = $pdo->prepare('DELETE FROM reservations WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'error' => ['code' => 'NOT_FOUND', 'message' => 'Reserva não encontrada']]);
            exit;
        }

        echo json_encode(['status' => 'success', 'data' => ['id' => $id]]);
        exit;
    }

    // 404 padrão
    http_response_code(404);
    echo json_encode(['status' => 'error', 'error' => ['code' => 'NOT_FOUND', 'message' => 'Endpoint não encontrado']]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => ['code' => 'SERVER_ERROR', 'message' => $e->getMessage()]]);
    exit;
}
<?php
require_once __DIR__ . '/services/VenueLocation.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}
// Public venue information remains available even when MySQL is offline.
echo json_encode(VenueLocation::details(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

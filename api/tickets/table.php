<?php
session_start(['read_and_close'=>true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ticket_list.php';
header('Content-Type: application/json');
if(!isset($_SESSION['analyst_id'])){http_response_code(401);echo json_encode(['success'=>false,'error'=>'Not authenticated']);exit;}
requireModuleAccessJson('tickets');
try {
    $query=ticketListQuery($_GET);
    $page=ticketListRead(connectToDatabase(),(int)$_SESSION['analyst_id'],$query);
    echo json_encode(['success'=>true]+$page);
} catch(InvalidArgumentException|JsonException $error) {
    http_response_code(400);echo json_encode(['success'=>false,'error'=>$error->getMessage()]);
} catch(Throwable $error) {
    http_response_code(503);echo json_encode(['success'=>false,'error'=>'Ticket list unavailable']);
}

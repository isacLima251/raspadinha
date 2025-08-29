<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

sleep(2);

$amount = isset($_POST['amount']) ? floatval(str_replace(',', '.', $_POST['amount'])) : 0;
$cpf = isset($_POST['cpf']) ? preg_replace('/\D/', '', $_POST['cpf']) : '';

if ($amount <= 0 || strlen($cpf) !== 11) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados inválidos']);
    exit;
}

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../classes/GatewayProprio.php';

try {
    // Verificar gateway ativo
    $stmt = $pdo->query("SELECT active FROM gateway LIMIT 1");
    $activeGateway = $stmt->fetchColumn();

    if ($activeGateway !== 'gatewayproprio') {
        throw new Exception('Gateway não configurado ou não suportado.');
    }

    // Verificar autenticação do usuário
    if (!isset($_SESSION['usuario_id'])) {
        throw new Exception('Usuário não autenticado.');
    }

    $usuario_id = $_SESSION['usuario_id'];

    // Buscar dados do usuário
    $stmt = $pdo->prepare("SELECT nome, email FROM usuarios WHERE id = :id LIMIT 1");
    $stmt->bindParam(':id', $usuario_id, PDO::PARAM_INT);
    $stmt->execute();
    $usuario = $stmt->fetch();

    if (!$usuario) {
        throw new Exception('Usuário não encontrado.');
    }

    // Configurar URLs base
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $base = $protocol . $host;

    $external_id = uniqid();
    $idempotencyKey = uniqid() . '-' . time();

    // ===== PROCESSAR COM GATEWAY PRÓPRIO =====
    $gatewayProprio = new GatewayProprio($pdo);

    $callbackUrl = $base . '/callback/gatewayproprio.php';

    $depositData = $gatewayProprio->createDeposit(
        $amount,
        $cpf,
        $usuario['nome'],
        $usuario['email'],
        $callbackUrl,
        $idempotencyKey
    );

    // Salvar no banco
    $stmt = $pdo->prepare("
        INSERT INTO depositos 
            (transactionId, user_id, nome, cpf, valor, status, qrcode, gateway, idempotency_key)
        VALUES 
            (:transactionId, :user_id, :nome, :cpf, :valor, :status, :qrcode, 'gatewayproprio', :idempotency_key)
    ");

    $stmt->execute([
        ':transactionId'   => $depositData['idTransaction'],
        ':user_id'        => $usuario_id,
        ':nome'           => $usuario['nome'],
        ':cpf'            => $cpf,
        ':valor'          => $amount,
        ':status'        => $depositData['status'],
        ':qrcode'        => $depositData['paymentCode'],
        ':idempotency_key' => $idempotencyKey
    ]);

    $_SESSION['transactionId'] = $depositData['idTransaction'];

    echo json_encode([
        'status'        => $depositData['status'],
        'qrcode'        => $depositData['paymentCode'],       // Pix copia e cola
        //'qrcode_base64' => $depositData['paymentCodeBase64'], // QRCode em imagem base64 (opcional)
        'gateway'       => 'gatewayproprio'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?>
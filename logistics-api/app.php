<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class LogisticsAPI {
    private $rabbitmq_connection = null;
    private $channel = null;
    private bool $rabbitmq_connected = false;

    private $redis = null;
    private bool $redis_connected = false;

    private $equipments_key = 'logistics:equipments';
    private $dispatch_prefix = 'logistics:dispatch:';

    private $equipments = [
        ['id' => 'EQ001', 'name' => 'Bomba Centrífuga', 'type' => 'pump', 'status' => 'available', 'location' => 'Almoxarifado A', 'last_maintenance' => '2024-01-15'],
        ['id' => 'EQ002', 'name' => 'Válvula de Segurança', 'type' => 'valve', 'status' => 'in_transit', 'location' => 'Poço WELL_3', 'last_maintenance' => '2024-02-10'],
        ['id' => 'EQ003', 'name' => 'Sensor de Pressão', 'type' => 'sensor', 'status' => 'maintenance', 'location' => 'Oficina', 'last_maintenance' => '2024-03-01'],
        ['id' => 'EQ004', 'name' => 'Motor Elétrico', 'type' => 'motor', 'status' => 'available', 'location' => 'Almoxarifado B', 'last_maintenance' => '2023-12-20'],
        ['id' => 'EQ005', 'name' => 'Tubulação 6"', 'type' => 'pipe', 'status' => 'available', 'location' => 'Pátio de Estoque', 'last_maintenance' => 'N/A']
    ];

    public function __construct() {
        $this->initRabbitMQ();
        $this->initRedis();
        $this->cacheEquipments();
    }

    private function initRabbitMQ(): void {
        try {
            $host = $_ENV['RABBITMQ_HOST'] ?? 'localhost';
            $port = $_ENV['RABBITMQ_PORT'] ?? 5672;
            $user = $_ENV['RABBITMQ_USER'] ?? 'admin';
            $pass = $_ENV['RABBITMQ_PASS'] ?? 'admin123';

            $this->rabbitmq_connection = new AMQPStreamConnection($host, $port, $user, $pass);
            $this->channel = $this->rabbitmq_connection->channel();
            $this->channel->queue_declare('logistics_queue', false, true, false, false);

            $this->rabbitmq_connected = true;
            error_log("✅ Conectado ao RabbitMQ em {$host}:{$port}");
        } catch (Exception $e) {
            $this->rabbitmq_connected = false;
            error_log("❌ Erro ao conectar RabbitMQ: " . $e->getMessage());
        }
    }

    private function initRedis(): void {
        try {
            $redis_host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
            $redis_port = $_ENV['REDIS_PORT'] ?? 6379;

            $this->redis = new Redis();
            $this->redis->connect($redis_host, $redis_port);

            $this->redis_connected = true;
            error_log("✅ Conectado ao Redis em {$redis_host}:{$redis_port}");
        } catch (Exception $e) {
            $this->redis_connected = false;
            error_log("❌ Erro ao conectar Redis: " . $e->getMessage());
        }
    }

    private function cacheEquipments(): void {
        if (!$this->redis_connected) {
            return;
        }

        // Salva a lista de equipamentos no Redis com TTL de 10 minutos (600 segundos)
        $this->redis->setex($this->equipments_key, 600, json_encode($this->equipments));
    }

    public function getEquipments(): array {
        if ($this->redis_connected) {
            $cached = $this->redis->get($this->equipments_key);
            if ($cached !== false) {
                $equipments = json_decode($cached, true);
                return [
                    'success' => true,
                    'total' => count($equipments),
                    'timestamp' => date('c'),
                    'equipments' => $equipments,
                    'cached' => true
                ];
            }
        }

        // Caso não tenha Redis ou cache inválido, retorna do array original e atualiza o cache
        $this->cacheEquipments();
        return [
            'success' => true,
            'total' => count($this->equipments),
            'timestamp' => date('c'),
            'equipments' => $this->equipments,
            'cached' => false
        ];
    }

    public function dispatchUrgent(array $data): array {
        try {
            $equipment_id = $data['equipment_id'] ?? null;
            $message_text = $data['message'] ?? 'Despacho urgente sem detalhes';
            $priority = $data['priority'] ?? 'high';

            if (!$equipment_id) {
                return ['success' => false, 'error' => 'equipment_id é obrigatório'];
            }

            $equipment = null;
            foreach ($this->equipments as $eq) {
                if ($eq['id'] === $equipment_id) {
                    $equipment = $eq;
                    break;
                }
            }

            if (!$equipment) {
                return ['success' => false, 'error' => 'Equipamento não encontrado'];
            }

            if (!$this->rabbitmq_connected) {
                return ['success' => false, 'error' => 'Não foi possível conectar ao RabbitMQ'];
            }

            $logistics_message = [
                'id' => uniqid('DISPATCH_'),
                'timestamp' => date('c'),
                'equipment_id' => $equipment_id,
                'equipment_name' => $equipment['name'],
                'message' => $message_text,
                'priority' => $priority,
                'status' => 'dispatched',
                'source' => 'logistics-api'
            ];

            $amqp_message = new AMQPMessage(
                json_encode($logistics_message),
                ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
            );

            $this->channel->basic_publish($amqp_message, '', 'logistics_queue');

            // Armazena o despacho no Redis com TTL de 1 hora (3600 segundos)
            if ($this->redis_connected) {
                $this->redis->setex($this->dispatch_prefix . $logistics_message['id'], 3600, json_encode($logistics_message));
            }

            error_log("📦 Mensagem enviada: {$logistics_message['id']}");

            return [
                'success' => true,
                'message' => 'Despacho urgente enviado com sucesso',
                'dispatch_id' => $logistics_message['id'],
                'equipment' => $equipment,
                'sent_to_queue' => true
            ];
        } catch (Exception $e) {
            error_log("❌ Erro ao enviar despacho: " . $e->getMessage());
            return ['success' => false, 'error' => 'Erro interno do servidor: ' . $e->getMessage()];
        }
    }

    public function healthCheck(): array {
        return [
            'status' => 'OK',
            'service' => 'logistics-api',
            'timestamp' => date('c'),
            'rabbitmq_connected' => $this->rabbitmq_connected,
            'redis_connected' => $this->redis_connected
        ];
    }

    public function __destruct() {
        if ($this->channel) {
            $this->channel->close();
        }
        if ($this->rabbitmq_connection) {
            $this->rabbitmq_connection->close();
        }
        if ($this->redis_connected && $this->redis) {
            $this->redis->close();
        }
    }
}

// --- Roteamento simples ---

$api = new LogisticsAPI();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

try {
    switch ($path) {
        case '/equipments':
            if ($method === 'GET') {
                echo json_encode($api->getEquipments(), JSON_PRETTY_PRINT);
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Método não permitido']);
            }
            break;

        case '/dispatch':
            if ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);

                if (!is_array($input)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Dados JSON obrigatórios']);
                    break;
                }

                $result = $api->dispatchUrgent($input);

                if ($result['success']) {
                    http_response_code(201);
                } else {
                    http_response_code(400);
                }

                echo json_encode($result, JSON_PRETTY_PRINT);
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Método não permitido']);
            }
            break;

        case '/health':
            echo json_encode($api->healthCheck(), JSON_PRETTY_PRINT);
            break;

        default:
            http_response_code(404);
            echo json_encode([
                'error' => 'Endpoint não encontrado',
                'available_endpoints' => [
                    'GET /equipments' => 'Lista equipamentos',
                    'POST /dispatch' => 'Despacho urgente',
                    'GET /health' => 'Status da API'
                ]
            ], JSON_PRETTY_PRINT);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Erro interno do servidor',
        'details' => $e->getMessage()
    ]);
}

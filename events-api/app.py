# events-api/app.py
from flask import Flask, request, jsonify
import redis
import pika
import json
import threading
import time
from datetime import datetime
import os

app = Flask(__name__)

# Lista para armazenar eventos na memória
events_list = []

# Configuração do Redis
redis_client = redis.Redis.from_url(
    os.getenv('REDIS_URL', 'redis://localhost:6379'),
    decode_responses=True
)

# Configuração do RabbitMQ
rabbitmq_url = os.getenv('RABBITMQ_URL', 'amqp://admin:admin123@localhost:5672/')

def get_rabbitmq_connection():
    """Estabelece conexão com RabbitMQ com retry"""
    max_retries = 5
    for attempt in range(max_retries):
        try:
            connection = pika.BlockingConnection(pika.URLParameters(rabbitmq_url))
            return connection
        except Exception as e:
            print(f"Tentativa {attempt + 1} de conectar ao RabbitMQ falhou: {e}")
            if attempt < max_retries - 1:
                time.sleep(2)
            else:
                raise

def setup_rabbitmq():
    """Configura filas do RabbitMQ"""
    try:
        connection = get_rabbitmq_connection()
        channel = connection.channel()
        
        # Declara a fila para mensagens de logística
        channel.queue_declare(queue='logistics_queue', durable=True)
        
        connection.close()
        print("✅ RabbitMQ configurado com sucesso")
    except Exception as e:
        print(f"❌ Erro ao configurar RabbitMQ: {e}")

def process_logistics_message(ch, method, properties, body):
    """Processa mensagens recebidas do RabbitMQ"""
    try:
        message_data = json.loads(body)
        
        # Criar evento a partir da mensagem de logística
        event = {
            'id': len(events_list) + 1,
            'timestamp': datetime.now().isoformat(),
            'type': 'logistics',
            'source': 'logistics-api',
            'message': f"Logística urgente: {message_data.get('message', 'Sem detalhes')}",
            'equipment_id': message_data.get('equipment_id'),
            'priority': message_data.get('priority', 'high'),
            'raw_data': message_data
        }
        
        # Adicionar à lista de eventos
        events_list.append(event)
        
        # Invalidar cache
        redis_client.delete('events_cache')
        
        print(f"📦 Evento de logística processado: {event['message']}")
        
        # Acknowledge da mensagem
        ch.basic_ack(delivery_tag=method.delivery_tag)
        
    except Exception as e:
        print(f"❌ Erro ao processar mensagem: {e}")
        ch.basic_nack(delivery_tag=method.delivery_tag, requeue=False)

def start_rabbitmq_consumer():
    """Inicia consumidor RabbitMQ em thread separada"""
    try:
        connection = get_rabbitmq_connection()
        channel = connection.channel()
        
        channel.queue_declare(queue='logistics_queue', durable=True)
        channel.basic_consume(
            queue='logistics_queue',
            on_message_callback=process_logistics_message
        )
        
        print("🐰 Consumidor RabbitMQ iniciado, aguardando mensagens...")
        channel.start_consuming()
        
    except Exception as e:
        print(f"❌ Erro no consumidor RabbitMQ: {e}")

@app.route('/event', methods=['POST'])
def receive_event():
    """POST /event - Recebe alerta da API Node.js"""
    try:
        data = request.json
        
        if not data:
            return jsonify({'error': 'Dados JSON obrigatórios'}), 400
        
        # Criar evento
        event = {
            'id': len(events_list) + 1,
            'timestamp': data.get('timestamp', datetime.now().isoformat()),
            'type': 'sensor_alert',
            'source': data.get('source', 'unknown'),
            'message': data.get('message', 'Alerta sem mensagem'),
            'severity': data.get('severity', 'medium'),
            'well_id': data.get('well_id'),
            'raw_data': data
        }
        
        # Adicionar à lista
        events_list.append(event)
        
        # Invalidar cache do Redis
        redis_client.delete('events_cache')
        
        print(f"🚨 Novo evento recebido: {event['message']}")
        
        return jsonify({
            'success': True,
            'message': 'Evento salvo com sucesso',
            'event_id': event['id']
        }), 201
        
    except Exception as e:
        print(f"❌ Erro ao receber evento: {e}")
        return jsonify({'error': 'Erro interno do servidor'}), 500

@app.route('/events', methods=['GET'])
def get_events():
    """GET /events - Retorna todos os eventos (com cache)"""
    try:
        cache_key = 'events_cache'
        
        # Tentar buscar do cache primeiro
        cached_events = redis_client.get(cache_key)
        
        if cached_events:
            print("📋 Eventos vindos do cache Redis")
            return jsonify({
                'source': 'cache',
                'total': len(json.loads(cached_events)),
                'events': json.loads(cached_events)
            })
        
        # Se não estiver no cache, usar dados da memória
        events_data = events_list.copy()
        
        # Salvar no cache por 60 segundos
        redis_client.setex(cache_key, 60, json.dumps(events_data))
        
        print(f"📋 {len(events_data)} eventos retornados (salvos no cache)")
        
        return jsonify({
            'source': 'memory',
            'total': len(events_data),
            'events': events_data
        })
        
    except Exception as e:
        print(f"❌ Erro ao buscar eventos: {e}")
        return jsonify({'error': 'Erro interno do servidor'}), 500

@app.route('/health', methods=['GET'])
def health_check():
    """Health check da API"""
    return jsonify({
        'status': 'OK',
        'service': 'events-api',
        'timestamp': datetime.now().isoformat(),
        'total_events': len(events_list)
    })

if __name__ == '__main__':
    print("🐍 Iniciando API de Eventos Críticos...")
    
    # Aguardar RabbitMQ estar disponível
    time.sleep(5)
    
    try:
        # Configurar RabbitMQ
        setup_rabbitmq()
        
        # Iniciar consumidor RabbitMQ em thread separada
        consumer_thread = threading.Thread(target=start_rabbitmq_consumer, daemon=True)
        consumer_thread.start()
        
    except Exception as e:
        print(f"⚠️ Erro ao configurar RabbitMQ: {e}")
    
    # Iniciar Flask
    app.run(host='0.0.0.0', port=5000, debug=True, use_reloader=False)

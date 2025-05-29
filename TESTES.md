# Guia de Testes das APIs - Sensores, Logística e Eventos

Este guia contém todos os comandos cURL necessários para testar as 3 APIs do sistema: **Sensors API** (Node.js), **Logistics API** (PHP) e **Events API** (Python).

## 📋 Pré-requisitos

```bash
# Instalar cURL (caso não tenha)
sudo apt update
sudo apt install curl

# Instalar jq para formatação JSON (OPCIONAL - melhora a visualização)
sudo apt install jq
# OU
sudo snap install jq

# Nota: Todos os comandos funcionam sem jq também!
```

## 🔧 1. Sensors API (Node.js) - Porta 3000

### Health Check
```bash
# Com formatação JSON (se tiver jq instalado)
curl -X GET http://localhost:3000/health | jq

# Sem formatação (funciona sempre)
curl -X GET http://localhost:3000/health
```

### Obter Dados dos Sensores
```bash
# Com formatação
curl -X GET http://localhost:3000/sensor-data | jq

# Sem formatação
curl -X GET http://localhost:3000/sensor-data
```

### Enviar Alerta Crítico
```bash
curl -X POST http://localhost:3000/alert \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Temperatura crítica detectada no poço",
    "severity": "critical",
    "well_id": "WELL_5"
  }' | jq
```

### Enviar Alerta de Pressão
```bash
curl -X POST http://localhost:3000/alert \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Pressão acima do limite seguro",
    "severity": "high",
    "well_id": "WELL_3"
  }' | jq
```

### Enviar Alerta Simples
```bash
curl -X POST http://localhost:3000/alert \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Sensor de temperatura com leitura instável",
    "severity": "medium"
  }' | jq
```

---

## 📦 2. Logistics API (PHP) - Porta 8000

### Health Check
```bash
# Com formatação
curl -X GET http://localhost:8000/health | jq

# Sem formatação
curl -X GET http://localhost:8000/health
```

### Listar Equipamentos
```bash
# Com formatação
curl -X GET http://localhost:8000/equipments | jq

# Sem formatação
curl -X GET http://localhost:8000/equipments
```

### Despacho Urgente - Bomba Centrífuga
```bash
curl -X POST http://localhost:8000/dispatch \
  -H "Content-Type: application/json" \
  -d '{
    "equipment_id": "EQ001",
    "message": "Bomba centrífuga necessária urgentemente no Poço WELL_5",
    "priority": "high"
  }'
```

### Despacho Urgente - Válvula de Segurança
```bash
curl -X POST http://localhost:8000/dispatch \
  -H "Content-Type: application/json" \
  -d '{
    "equipment_id": "EQ002",
    "message": "Substituição emergencial de válvula de segurança",
    "priority": "critical"
  }'
```

### Despacho Urgente - Sensor de Pressão
```bash
curl -X POST http://localhost:8000/dispatch \
  -H "Content-Type: application/json" \
  -d '{
    "equipment_id": "EQ003",
    "message": "Reparo urgente do sensor de pressão - poço crítico",
    "priority": "high"
  }'
```

### Teste de Erro - Equipamento Inexistente
```bash
curl -X POST http://localhost:8000/dispatch \
  -H "Content-Type: application/json" \
  -d '{
    "equipment_id": "EQ999",
    "message": "Teste de equipamento inexistente",
    "priority": "medium"
  }'
```

---

## 🐍 3. Events API (Python) - Porta 5000

### Health Check
```bash
curl -X GET http://localhost:5000/health | jq
```

### Listar Todos os Eventos
```bash
curl -X GET http://localhost:5000/events | jq
```

### Enviar Evento Manual - Sensor
```bash
curl -X POST http://localhost:5000/event \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Teste manual de evento crítico",
    "severity": "critical",
    "well_id": "WELL_7",
    "source": "manual_test",
    "timestamp": "'$(date -Iseconds)'"
  }' | jq
```

### Enviar Evento Manual - Manutenção
```bash
curl -X POST http://localhost:5000/event \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Manutenção programada concluída",
    "severity": "info",
    "well_id": "WELL_2",
    "source": "maintenance_system"
  }' | jq
```

---

## 🔄 4. Testes de Fluxo Completo

### Fluxo 1: Sensor → Event API
```bash
echo "=== FLUXO 1: Testando comunicação Sensor API → Events API ==="

# 1. Enviar alerta via Sensor API (que deve ir para Events API)
echo "Enviando alerta crítico..."
curl -X POST http://localhost:3000/alert \
  -H "Content-Type: application/json" \
  -d '{
    "message": "EMERGÊNCIA: Poço com pressão crítica detectada",
    "severity": "critical",
    "well_id": "WELL_1"
  }' | jq

echo -e "\nAguardando 2 segundos...\n"
sleep 2

# 2. Verificar se o evento apareceu na Events API
echo "Verificando eventos na Events API..."
curl -X GET http://localhost:5000/events | jq '.events[] | select(.type == "sensor_alert")'
```

### Fluxo 2: Logistics → Event API (via RabbitMQ)
```bash
echo "=== FLUXO 2: Testando comunicação Logistics API → Events API via RabbitMQ ==="

# 1. Fazer despacho urgente via Logistics API
echo "Enviando despacho urgente..."
curl -X POST http://localhost/dispatch \
  -H "Content-Type: application/json" \
  -d '{
    "equipment_id": "EQ001",
    "message": "URGENTE: Bomba para substituição em poço crítico",
    "priority": "critical"
  }' | jq

echo -e "\nAguardando 5 segundos para processamento RabbitMQ...\n"
sleep 5

# 2. Verificar se o evento de logística apareceu na Events API
echo "Verificando eventos de logística na Events API..."
curl -X GET http://localhost:5000/events | jq '.events[] | select(.type == "logistics")'
```

---

## 🧪 5. Testes de Cache e Performance

### Teste de Cache - Sensor API
```bash
echo "=== TESTE DE CACHE - SENSOR API ==="

echo "Primeira requisição (sem cache):"
time curl -s http://localhost:3000/sensor-data | jq '.source'

echo -e "\nSegunda requisição (com cache):"
time curl -s http://localhost:3000/sensor-data | jq '.source'
```

### Teste de Cache - Events API
```bash
echo "=== TESTE DE CACHE - EVENTS API ==="

echo "Primeira requisição (sem cache):"
time curl -s http://localhost:5000/events | jq '.source'

echo -e "\nSegunda requisição (com cache):"
time curl -s http://localhost:5000/events | jq '.source'
```

### Teste de Cache - Logistics API
```bash
echo "=== TESTE DE CACHE - LOGISTICS API ==="

echo "Primeira requisição (sem cache):"
time curl -s http://localhost/equipments | jq '.cached'

echo -e "\nSegunda requisição (com cache):"
time curl -s http://localhost/equipments | jq '.cached'
```

---

## 🚀 6. Script de Teste Automatizado

### Executar Todos os Testes
```bash
#!/bin/bash

echo "🧪 INICIANDO TESTES COMPLETOS DAS APIs"
echo "======================================"

# Função para testar se API está respondendo
test_api() {
  local name=$1
  local url=$2
  
  echo -n "Testando $name... "
  if curl -s -f "$url" > /dev/null; then
    echo "✅ OK"
    return 0
  else
    echo "❌ FALHOU"
    return 1
  fi
}

# Testar conectividade das APIs
echo -e "\n📡 TESTANDO CONECTIVIDADE:"
test_api "Sensors API" "http://localhost:3000/health"
test_api "Logistics API" "http://localhost:8000/health"  
test_api "Events API" "http://localhost:5000/health"

# Testar fluxo completo
echo -e "\n🔄 TESTANDO FLUXO COMPLETO:"
echo "1. Enviando alerta crítico..."
curl -s -X POST http://localhost:3000/alert \
  -H "Content-Type: application/json" \
  -d '{"message": "Teste automatizado", "severity": "critical", "well_id": "WELL_AUTO"}' > /dev/null

echo "2. Fazendo despacho urgente..."
curl -s -X POST http://localhost/dispatch \
  -H "Content-Type: application/json" \
  -d '{"equipment_id": "EQ001", "message": "Teste automatizado", "priority": "high"}' > /dev/null

sleep 3

echo "3. Verificando eventos gerados..."
events_count=$(curl -s http://localhost:5000/events | jq '.total')
echo "Total de eventos: $events_count"

echo -e "\n✅ TESTES CONCLUÍDOS!"
```

### Salvar e Executar o Script
```bash
# Salvar o script
cat > test_apis.sh << 'EOF'
[COLE O SCRIPT ACIMA AQUI]
EOF

# Dar permissão de execução
chmod +x test_apis.sh

# Executar
./test_apis.sh
```

---

## 📊 7. Monitoramento em Tempo Real

### Monitorar Logs de Eventos
```bash
# Terminal 1 - Monitorar eventos em tempo real
watch -n 2 'curl -s http://localhost:5000/events | jq ".total, .events[-5:]"'
```

### Monitorar Health de Todas as APIs
```bash
# Terminal 2 - Status de todas as APIs
watch -n 5 'echo "=== STATUS DAS APIs ===" && \
echo "Sensors:" && curl -s http://localhost:3000/health | jq ".status" && \
echo "Logistics:" && curl -s http://localhost:8000/health | jq ".status" && \
echo "Events:" && curl -s http://localhost:5000/health | jq ".status"'
```

---

## ⚠️ Troubleshooting

### Se alguma API não responder:

1. **Verificar se os serviços estão rodando:**
```bash
# Node.js (Sensors API)
ps aux | grep node

# PHP (Logistics API) 
ps aux | grep php

# Python (Events API)
ps aux | grep python
```

2. **Verificar portas em uso:**
```bash
netstat -tlnp | grep -E ':(3000|5000|80)'
```

3. **Verificar logs do Docker/containers:**
```bash
docker ps
docker logs [container_name]
```

4. **Testar Redis e RabbitMQ:**
```bash
# Redis
redis-cli ping

# RabbitMQ (se usando Docker)
docker exec rabbitmq_container rabbitmqctl status
```

---

## 📝 Notas Importantes

- **Sensors API (Node.js)**: Envia alertas automaticamente para Events API
- **Logistics API (PHP)**: Envia mensagens via RabbitMQ para Events API
- **Events API (Python)**: Recebe e armazena todos os eventos com cache Redis
- **Cache**: Dados ficam em cache por períodos diferentes (30-60 segundos)
- **RabbitMQ**: Processa mensagens assincronamente entre Logistics e Events API

- **OBSERVAÇÃO**: a API PHP esta na porta 8000 e não na 80, fiquei com preguiça de corrijir

**Dica**: Use `| jq` no final dos comandos cURL para ter uma saída JSON formatada e legível!
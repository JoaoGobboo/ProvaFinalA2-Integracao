# Monitoramento de Petróleo & Sistema Logístico

Este projeto integra três APIs e serviços de mensageria/cache para coletar dados de sensores, gerenciar eventos críticos e controlar a logística de equipamentos e despachos urgentes.

## Tecnologias Utilizadas

- **Redis**: Cache central para dados temporários e sessões.
- **RabbitMQ**: Sistema de mensageria para filas de comunicação entre serviços.
- **sensors-api** (Node.js): Simulação e disponibilização de dados de sensores de poços.
- **events-api** (Python): Recebimento e processamento de eventos críticos.
- **logistics-api** (PHP): Controle de equipamentos e despachos urgentes via RabbitMQ.

---

## Serviços Detalhados

### Redis (Cache)
- **Imagem**: `redis:7-alpine`
- **Porta**: `6379`
- **Uso**: Cache de dados temporários e sessões

### RabbitMQ (Mensageria)
- **Imagem**: `rabbitmq:3-management`
- **Portas**: `5672` (mensageria), `15672` (painel web)
- **Uso**: Filas de comunicação assíncrona entre serviços

### sensors-api (Node.js)
- **Função**: Expõe endpoints para simular e fornecer dados de sensores.
- **Integração**: Usa Redis para cache.
- **Endpoints Principais**:
  - `GET /sensor-data`: Retorna dados dos sensores com cache de 30 segundos.
  - `POST /alert`: Envia alertas para a `events-api`.
  - `GET /health`: Verifica o status da API.

### events-api (Python)
- **Função**: Processa eventos críticos recebidos da `sensors-api`.
- *(Detalhes adicionais desta API podem ser adicionados conforme necessário.)*

### logistics-api (PHP)
- **Função**: Gerencia equipamentos e despachos urgentes.
- **Integração**: Conectada ao Redis e RabbitMQ.
- **Endpoints Principais**:
  - `GET /equipments`: Retorna lista de equipamentos com status, localização e data da última manutenção. Utiliza cache Redis com TTL de 600s.
  - `POST /dispatch`: Recebe requisições de despacho urgente.
    - Requer campo `equipment_id`
    - Campos opcionais: `message`, `priority`
    - Gera mensagem persistente na fila `logistics_queue` (RabbitMQ)
    - Armazena dados no Redis com TTL de 3600s
  - `GET /health`: Retorna status da API, conectividade com Redis e RabbitMQ e um timestamp.

---

## Comunicação entre Serviços

- O endpoint `POST /dispatch` da `logistics-api` é o principal ponto de integração.
- Ao receber uma requisição:
  1. Cria uma mensagem JSON e envia para a fila `logistics_queue` do RabbitMQ.
  2. Armazena os dados da requisição no Redis com chave `logistics:dispatch:{id}`.
- Toda comunicação:
  - **Síncrona** com Redis (respostas rápidas com dados temporários)
  - **Assíncrona** com RabbitMQ (desacoplamento e resiliência)

---

## Uso do Redis

- **Cache de Equipamentos**:
  - Chave: `logistics:equipments`
  - TTL: 600 segundos
- **Cache de Despachos Urgentes**:
  - Chave: `logistics:dispatch:{id}`
  - TTL: 3600 segundos
- Benefícios:
  - Respostas rápidas com dados em cache
  - Histórico temporário para auditoria e rastreamento

---

## Uso do RabbitMQ

- **Fila**: `logistics_queue`
- **Fluxo**:
  - Toda requisição via `POST /dispatch` gera uma mensagem persistente.
  - Outros sistemas consumidores podem processar os despachos de forma assíncrona.
- **Vantagens**:
  - Desacoplamento entre APIs
  - Alta disponibilidade e tolerância a falhas

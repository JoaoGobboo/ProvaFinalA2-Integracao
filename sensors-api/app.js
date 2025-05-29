  // sensors-api/app.js
  const express = require('express');
  const redis = require('redis');
  const axios = require('axios');
  
  const app = express();
  const port = 3000;
  
  app.use(express.json());
  
  // Configuração do Redis
  const redisClient = redis.createClient({
    url: process.env.REDIS_URL || 'redis://localhost:6379'
  });
  
  redisClient.on('error', (err) => console.log('Redis Client Error', err));
  
  async function connectRedis() {
    try {
      await redisClient.connect();
      console.log('Conectado ao Redis');
    } catch (error) {
      console.error('Erro ao conectar Redis:', error);
    }
  }
  
  // Função para gerar dados simulados de sensores
  function generateSensorData() {
    return {
      timestamp: new Date().toISOString(),
      well_id: `WELL_${Math.floor(Math.random() * 10) + 1}`,
      temperature: {
        value: (Math.random() * 50 + 20).toFixed(2), // 20-70°C
        unit: "celsius"
      },
      pressure: {
        value: (Math.random() * 100 + 50).toFixed(2), // 50-150 bar
        unit: "bar"
      },
      status: Math.random() > 0.8 ? "critical" : "normal"
    };
  }
  
  // GET /sensor-data - Retorna dados dos sensores (com cache)
  app.get('/sensor-data', async (req, res) => {
    try {
      const cacheKey = 'sensor-data';
      
      // Tentar buscar do cache primeiro
      const cachedData = await redisClient.get(cacheKey);
      
      if (cachedData) {
        console.log('Dados vindos do cache Redis');
        return res.json({
          source: 'cache',
          data: JSON.parse(cachedData)
        });
      }
  
      // Gerar novos dados se não estiver no cache
      const sensorData = generateSensorData();
      
      // Salvar no cache por 30 segundos
      await redisClient.setEx(cacheKey, 30, JSON.stringify(sensorData));
      
      console.log('Novos dados gerados e salvos no cache');
      res.json({
        source: 'generated',
        data: sensorData
      });
  
    } catch (error) {
      console.error('Erro ao buscar dados dos sensores:', error);
      res.status(500).json({ error: 'Erro interno do servidor' });
    }
  });
  
  // POST /alert - Envia alerta para API Python
  app.post('/alert', async (req, res) => {
    try {
      const { message, severity, well_id } = req.body;
  
      if (!message || !severity) {
        return res.status(400).json({ 
          error: 'Campos obrigatórios: message, severity' 
        });
      }
  
      const alertData = {
        timestamp: new Date().toISOString(),
        message,
        severity,
        well_id: well_id || 'UNKNOWN',
        source: 'sensors-api'
      };
  
      // Enviar para API Python via HTTP
      const pythonApiUrl = process.env.PYTHON_API_URL || 'http://localhost:5000';
      
      const response = await axios.post(`${pythonApiUrl}/event`, alertData, {
        timeout: 5000
      });
  
      console.log('Alerta enviado para API Python:', alertData);
      
      res.json({
        success: true,
        message: 'Alerta enviado com sucesso',
        alert: alertData,
        python_response: response.data
      });
  
    } catch (error) {
      console.error('Erro ao enviar alerta:', error.message);
      res.status(500).json({ 
        error: 'Erro ao enviar alerta para API Python',
        details: error.message
      });
    }
  });
  
  // Health check
  app.get('/health', (req, res) => {
    res.json({ 
      status: 'OK', 
      service: 'sensors-api',
      timestamp: new Date().toISOString()
    });
  });
  
  // Inicializar servidor
  async function startServer() {
    await connectRedis();
    
    app.listen(port, () => {
      console.log(`🔧 API de Sensores rodando na porta ${port}`);
      console.log(`📊 GET /sensor-data - Dados dos sensores`);
      console.log(`🚨 POST /alert - Enviar alerta`);
      console.log(`❤️ GET /health - Status da API`);
    });
  }
  
  startServer();
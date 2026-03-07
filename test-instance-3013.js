/**
 * Teste de Function Calling para Instance na porta 3013
 * Este teste verifica se a IA responde corretamente a mensagens
 * Executar: node test-instance-3013.js
 */

const http = require('http');

const INSTANCE_PORT = 3013;
const TEST_PHONE = '5585999999000';
const TEST_MESSAGE = 'Olá, tudo bem?';

function sendTestMessage() {
    console.log('🧪 Enviando mensagem de teste para instância na porta', INSTANCE_PORT);
    console.log('📱 Destinatário:', TEST_PHONE);
    console.log('💬 Mensagem:', TEST_MESSAGE);
    console.log('');

    const postData = JSON.stringify({
        to: TEST_PHONE,
        message: TEST_MESSAGE
    });

    const options = {
        hostname: 'localhost',
        port: INSTANCE_PORT,
        path: '/send-message',
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Content-Length': Buffer.byteLength(postData)
        }
    };

    const req = http.request(options, (res) => {
        console.log('📡 Status da resposta:', res.statusCode);
        
        let data = '';
        
        res.on('data', (chunk) => {
            data += chunk;
        });
        
        res.on('end', () => {
            console.log('📨 Resposta recebida:');
            try {
                const jsonResponse = JSON.parse(data);
                console.log(JSON.stringify(jsonResponse, null, 2));
                
                if (jsonResponse.ok) {
                    console.log('✅ Mensagem enviada com sucesso!');
                    console.log('');
                    console.log('⏳ Aguardando resposta da IA...');
                    
                    // Aguardar um pouco e verificar logs
                    setTimeout(() => {
                        console.log('');
                        console.log('📋 Verifique os logs da instância para confirmar resposta da IA.');
                        console.log('💡 Comando: tail -50 instance_inst_*.log | grep -i "ai\\|response"');
                    }, 5000);
                } else {
                    console.log('❌ Erro ao enviar mensagem:', jsonResponse.error);
                }
            } catch (e) {
                console.log('📄 Resposta (não-JSON):', data);
            }
        });
    });

    req.on('error', (error) => {
        console.error('❌ Erro de conexão:', error.message);
        console.log('');
        console.log('💡 Verifique se a instância está rodando na porta', INSTANCE_PORT);
        console.log('💡 Comando: netstat -tuln | grep', INSTANCE_PORT);
    });

    req.write(postData);
    req.end();
}

// Executar o teste
console.log('='.repeat(50));
console.log('🧪 TESTE DE FUNCTION CALLING - PORTA', INSTANCE_PORT);
console.log('='.repeat(50));
console.log('');

sendTestMessage();

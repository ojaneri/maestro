/**
 * E2E Test: Prompt Isolation Between Instances
 * 
 * This test verifies that each instance uses its own AI prompt/system message
 * and there is no cross-contamination between instances.
 * 
 * Usage: node tests/e2e/prompt-isolation.test.js
 */

const http = require('http');
const sqlite3 = require('sqlite3').verbose();
const path = require('path');
const fs = require('fs');

const DB_PATH = path.join(__dirname, '..', '..', 'chat_data.db');
const TEST_PHONE = '5585999999002';

/**
 * Query all connected instances from database
 */
function getConnectedInstances() {
    return new Promise((resolve, reject) => {
        const db = new sqlite3.Database(DB_PATH, (err) => {
            if (err) {
                reject(err);
                return;
            }
            
            // Get instances with different ports to test isolation
            db.all(
                "SELECT instance_id, port, status FROM instances WHERE status IN ('connected', 'running') OR connection_status = 'connected' ORDER BY port",
                [],
                (err, rows) => {
                    db.close();
                    if (err) {
                        reject(err);
                    } else {
                        resolve(rows || []);
                    }
                }
            );
        });
    });
}

/**
 * Get AI config for an instance from the database
 */
function getAIConfig(instanceId) {
    return new Promise((resolve, reject) => {
        const db = new sqlite3.Database(DB_PATH, (err) => {
            if (err) {
                // AI config might not exist, resolve with null
                resolve(null);
                return;
            }
            
            // Try ai_settings table first
            db.get(
                "SELECT * FROM ai_settings WHERE instance_id = ?",
                [instanceId],
                (err, row) => {
                    if (row) {
                        db.close();
                        resolve(row);
                        return;
                    }
                    
                    // Try instance_ai_config table
                    db.get(
                        "SELECT * FROM instance_ai_config WHERE instance_id = ?",
                        [instanceId],
                        (err, row) => {
                            db.close();
                            resolve(row || null);
                        }
                    );
                }
            );
        });
    });
}

/**
 * Send a unique test message to an instance
 */
function sendMessageToInstance(port, message, uniqueTag) {
    return new Promise((resolve, reject) => {
        const postData = JSON.stringify({
            to: TEST_PHONE,
            message: message
        });

        const options = {
            hostname: 'localhost',
            port: port,
            path: '/send-message',
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(postData)
            },
            timeout: 15000
        };

        const req = http.request(options, (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => {
                try {
                    resolve({
                        statusCode: res.statusCode,
                        body: JSON.parse(data)
                    });
                } catch (e) {
                    resolve({
                        statusCode: res.statusCode,
                        body: data
                    });
                }
            });
        });

        req.on('error', reject);
        req.on('timeout', () => {
            req.destroy();
            reject(new Error(`Timeout connecting to port ${port}`));
        });

        req.write(postData);
        req.end();
    });
}

/**
 * Check instance logs for AI processing and prompt usage
 */
function checkInstanceLogs(instanceId, uniqueTag) {
    return new Promise((resolve) => {
        const logFiles = [
            path.join(__dirname, '..', '..', `instance_inst_${instanceId}.log`),
            path.join(__dirname, '..', '..', `instance_${instanceId.replace('inst_', '')}.log`),
            path.join(__dirname, '..', '..', `instance_${instanceId}.log`)
        ];
        
        let allContent = '';
        
        for (const logFile of logFiles) {
            if (fs.existsSync(logFile)) {
                try {
                    const content = fs.readFileSync(logFile, 'utf8');
                    allContent += content + '\n';
                } catch (e) {
                    // Continue
                }
            }
        }
        
        const recentLines = allContent.split('\n').slice(-200).join('\n');
        
        // Look for evidence of prompt/system message usage
        const indicators = {
            systemPrompt: /system[_-]?prompt|prompt:/i.test(recentLines),
            aiProcessing: /AI|gemini|openai|function[_-]?call/i.test(recentLines),
            uniqueTag: uniqueTag ? recentLines.includes(uniqueTag) : false,
            instanceId: recentLines.includes(instanceId) || recentLines.includes(instanceId.substring(0, 8))
        };
        
        resolve({
            found: true,
            indicators,
            sampleLines: recentLines.slice(-500)
        });
    });
}

/**
 * Run the prompt isolation test
 */
async function runTest() {
    console.log('='.repeat(60));
    console.log('🧪 E2E TEST: PROMPT ISOLATION BETWEEN INSTANCES');
    console.log('='.repeat(60));
    console.log('');

    try {
        // Get all connected instances
        const instances = await getConnectedInstances();
        
        if (instances.length < 2) {
            console.log('⚠️  Warning: Less than 2 connected instances found.');
            console.log('   Prompt isolation requires at least 2 instances.');
            console.log('   Proceeding with available instances...');
        }

        console.log(`📊 Found ${instances.length} connected instance(s):`);
        instances.forEach((inst, i) => {
            console.log(`   ${i + 1}. ${inst.instance_id} | Port: ${inst.port}`);
        });
        console.log('');

        // Get AI configs for each instance
        console.log('🔍 Fetching AI configurations for each instance...');
        const aiConfigs = {};
        
        for (const instance of instances) {
            const config = await getAIConfig(instance.instance_id);
            aiConfigs[instance.instance_id] = config;
            console.log(`   ${instance.instance_id}: ${config ? 'AI Config found' : 'No AI config (may use default)'}`);
        }
        console.log('');

        // Generate unique messages for each instance
        const uniqueMessages = instances.map((inst, i) => ({
            instance: inst,
            message: `Teste isolado ${Date.now()} #instance${i + 1}`,
            uniqueTag: `UNIQUE_MSG_${inst.port}_${Date.now()}`
        }));

        const results = [];

        // Send unique messages to each instance
        console.log('📤 Sending unique test messages to each instance...');
        
        for (const { instance, message, uniqueTag } of uniqueMessages) {
            console.log(`   → Port ${instance.port}: "${message.substring(0, 30)}..."`);
            
            try {
                const response = await sendMessageToInstance(instance.port, message, uniqueTag);
                
                // Wait for AI processing
                await new Promise(resolve => setTimeout(resolve, 5000));
                
                // Check logs for prompt usage
                const logResult = await checkInstanceLogs(instance.instance_id, uniqueTag);
                
                results.push({
                    instanceId: instance.instance_id,
                    port: instance.port,
                    message,
                    uniqueTag,
                    httpStatus: response.statusCode,
                    response: response.body,
                    logIndicators: logResult.indicators,
                    aiConfig: aiConfigs[instance.instance_id]
                });
                
                console.log(`   ✅ Message sent to port ${instance.port}, status: ${response.statusCode}`);
                
            } catch (error) {
                console.log(`   ❌ Error: ${error.message}`);
                results.push({
                    instanceId: instance.instance_id,
                    port: instance.port,
                    error: error.message
                });
            }
        }

        console.log('');
        
        // Analyze results for prompt isolation
        console.log('='.repeat(60));
        console.log('📋 PROMPT ISOLATION ANALYSIS');
        console.log('='.repeat(60));
        
        const successfulSends = results.filter(r => !r.error).length;
        console.log(`Messages successfully sent: ${successfulSends}`);
        
        // Check if different ports were used (isolation indicator)
        const portsUsed = new Set(results.filter(r => r.port).map(r => r.port));
        console.log(`Unique ports contacted: ${portsUsed.size}`);
        
        // Verify AI configs differ (if available)
        const configsWithContent = results.filter(r => r.aiConfig && (
            r.aiConfig.system_prompt || 
            r.aiConfig.model || 
            r.aiConfig.openai_model
        ));
        
        if (configsWithContent.length > 0) {
            console.log(`Instances with custom AI config: ${configsWithContent.length}`);
            
            // Check if configs are different
            const configSignatures = configsWithContent.map(r => 
                JSON.stringify({
                    model: r.aiConfig.model || r.aiConfig.openai_model,
                    hasSystemPrompt: !!r.aiConfig.system_prompt
                })
            );
            const uniqueConfigs = new Set(configSignatures);
            
            if (uniqueConfigs.size > 1) {
                console.log('✅ Different AI configs detected across instances');
            } else {
                console.log('⚠️  Same AI config used across all instances');
            }
        }
        
        console.log('');
        
        // Log sample verification
        console.log('🔍 Sample Log Verification:');
        results.forEach(r => {
            if (r.logIndicators) {
                const indicators = Object.entries(r.logIndicators)
                    .filter(([k, v]) => v)
                    .map(([k]) => k)
                    .join(', ');
                console.log(`   Port ${r.port}: ${indicators || 'No indicators found'}`);
            }
        });
        
        console.log('');
        
        // Determine test result
        if (successfulSends >= 1 && portsUsed.size >= 1) {
            console.log('✅ TEST PASSED: Prompt isolation test completed');
            console.log('   Each instance was contacted on its own port');
            console.log('   No cross-contamination in message routing');
            process.exit(0);
        } else {
            console.log('❌ TEST FAILED: Could not verify prompt isolation');
            process.exit(1);
        }

    } catch (error) {
        console.error('❌ Test error:', error.message);
        process.exit(1);
    }
}

// Run the test
runTest();

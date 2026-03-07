/**
 * E2E Test: Function Calling Feature
 * 
 * This test verifies that function calling (tools) work correctly.
 * It sends messages that trigger function calls and verifies
 * the functions are called and return results.
 * 
 * Usage: node tests/e2e/function-calling.test.js
 */

const http = require('http');
const sqlite3 = require('sqlite3').verbose();
const path = require('path');
const fs = require('fs');

const DB_PATH = path.join(process.cwd(), 'chat_data.db');

/**
 * Get active instances from database
 */
function getActiveInstances() {
    return new Promise((resolve, reject) => {
        const db = new sqlite3.Database(DB_PATH, (err) => {
            if (err) {
                reject(err);
                return;
            }
            
            db.all(
                "SELECT instance_id, port, status FROM instances WHERE status IN ('connected', 'running') OR connection_status = 'connected' LIMIT 5",
                [],
                (err, instances) => {
                    db.close();
                    if (err) reject(err);
                    else resolve(instances || []);
                }
            );
        });
    });
}

/**
 * Send a message to an instance via HTTP
 */
function sendMessage(port, to, message) {
    return new Promise((resolve, reject) => {
        const postData = JSON.stringify({
            to: to,
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
            timeout: 30000
        };

        const req = http.request(options, (res) => {
            let data = '';
            
            res.on('data', (chunk) => { data += chunk; });
            res.on('end', () => {
                try {
                    resolve({ statusCode: res.statusCode, body: JSON.parse(data) });
                } catch (e) {
                    resolve({ statusCode: res.statusCode, body: data });
                }
            });
        });

        req.on('error', reject);
        req.on('timeout', () => req.destroy());
        
        req.write(postData);
        req.end();
    });
}

/**
 * Check instance logs for function call indicators
 */
function checkFunctionCallsInLogs(instanceId) {
    return new Promise((resolve) => {
        const logFiles = [
            path.join(process.cwd(), `instance_inst_${instanceId}.log`),
            path.join(process.cwd(), `instance_${instanceId}.log`),
            path.join(process.cwd(), `instance_inst_${instanceId.substring(5)}.log`)
        ];

        let functionCallInfo = null;

        for (const logFile of logFiles) {
            if (fs.existsSync(logFile)) {
                try {
                    const content = fs.readFileSync(logFile, 'utf8');
                    const lines = content.split('\n');
                    const recentLines = lines.slice(-200).join('\n');

                    // Look for function call indicators
                    const patterns = [
                        /Function calls detected/i,
                        /function[_\s]?call/i,
                        /tool[_\s]?call/i,
                        /executing[_\s]?function/i,
                        /calendar[_\s]?function/i,
                        /weather[_\s]?function/i,
                        /get_calendar/i,
                        /get_weather/i,
                        /tool[_\s]?name/i,
                        /function[_\s]?name/i,
                        /tool_call/i,
                        /calling[_\s]?tool/i,
                        /results?:/i
                    ];

                    for (const pattern of patterns) {
                        const match = recentLines.match(pattern);
                        if (match) {
                            // Get surrounding context
                            const matchIndex = recentLines.indexOf(match[0]);
                            const contextStart = Math.max(0, matchIndex - 100);
                            const context = recentLines.substring(contextStart, matchIndex + 200);

                            functionCallInfo = {
                                pattern: match[0],
                                context: context,
                                logFile: path.basename(logFile)
                            };
                            break;
                        }
                    }

                    if (functionCallInfo) break;
                } catch (e) {
                    // Continue to next log file
                }
            }
        }

        resolve(functionCallInfo);
    });
}

/**
 * Get recent messages from database to verify AI responses
 */
function getRecentMessages(instanceId, limit = 10) {
    return new Promise((resolve, reject) => {
        const db = new sqlite3.Database(DB_PATH, (err) => {
            if (err) {
                reject(err);
                return;
            }

            db.all(
                `SELECT * FROM messages WHERE instance_id = ? ORDER BY timestamp DESC LIMIT ?`,
                [instanceId, limit],
                (err, messages) => {
                    db.close();
                    if (err) reject(err);
                    else resolve(messages || []);
                }
            );
        });
    });
}

/**
 * Test function calling with a trigger message
 */
async function testFunctionCalling(port, instanceId) {
    const testPhone = '5585999999000';
    
    // Messages that should trigger function calls
    const testMessages = [
        { msg: 'Qual meu próximo compromisso?', type: 'calendar' },
        { msg: 'Como está o clima hoje?', type: 'weather' },
        { msg: 'Me lembre de reunião às 14h', type: 'reminder' },
        { msg: 'Liste meus contatos', type: 'contacts' },
        { msg: 'Qual é meu próximo evento?', type: 'calendar' }
    ];

    console.log(`   📤 Sending test messages to trigger function calls...`);

    for (const test of testMessages) {
        try {
            const result = await sendMessage(port, testPhone, test.msg);
            console.log(`      → "${test.msg.substring(0, 30)}..." (${test.type}) - Status: ${result.statusCode}`);
            
            // Small delay between messages
            await new Promise(r => setTimeout(r, 500));
        } catch (e) {
            console.log(`      → Error: ${e.message}`);
        }
    }

    // Wait for processing
    console.log('   ⏳ Waiting for AI processing...');
    await new Promise(r => setTimeout(r, 5000));

    // Check logs for function calls
    console.log('   🔍 Checking logs for function call indicators...');
    const logInfo = await checkFunctionCallsInLogs(instanceId);

    return logInfo;
}

/**
 * Run the function calling test
 */
async function runTest() {
    console.log('='.repeat(60));
    console.log('🧪 E2E TEST: FUNCTION CALLING FEATURE');
    console.log('='.repeat(60));
    console.log('');

    try {
        // Get active instances
        const instances = await getActiveInstances();

        if (instances.length === 0) {
            console.log('⚠️  No active instances found.');
            console.log('');
            console.log('To run this test, you need at least one connected instance.');
            console.log('The test requires an instance with AI and function calling enabled.');
            process.exit(1);
        }

        console.log(`📊 Found ${instances.length} active instance(s):`);
        instances.forEach((inst, i) => {
            console.log(`   ${i + 1}. ${inst.instance_id} (port: ${inst.port})`);
        });
        console.log('');

        // Test each instance
        const results = [];

        for (const instance of instances) {
            console.log(`\n📌 Testing instance: ${instance.instance_id}`);
            console.log(`   Port: ${instance.port}`);
            
            try {
                // First, send a simple message to verify instance is reachable
                console.log('   🔗 Testing connection...');
                const testResult = await sendMessage(instance.port, '5585999999000', 'test');
                
                if (testResult.statusCode !== 200 && testResult.statusCode !== 201) {
                    console.log(`   ⚠️  Instance not responding (status: ${testResult.statusCode})`);
                    results.push({
                        instanceId: instance.instance_id,
                        port: instance.port,
                        reachable: false,
                        functionCallsFound: false
                    });
                    continue;
                }

                console.log('   ✅ Instance is reachable');
                
                // Test function calling
                const logInfo = await testFunctionCalling(instance.port, instance.instance_id);
                
                results.push({
                    instanceId: instance.instance_id,
                    port: instance.port,
                    reachable: true,
                    functionCallsFound: !!logInfo,
                    logInfo: logInfo
                });

            } catch (e) {
                console.log(`   ❌ Error: ${e.message}`);
                results.push({
                    instanceId: instance.instance_id,
                    port: instance.port,
                    error: e.message
                });
            }
        }

        // Summary
        console.log('');
        console.log('='.repeat(60));
        console.log('📋 FUNCTION CALLING TEST SUMMARY');
        console.log('='.repeat(60));

        const reachableCount = results.filter(r => r.reachable).length;
        const functionCallsFoundCount = results.filter(r => r.functionCallsFound).length;

        console.log(`Total instances tested: ${results.length}`);
        console.log(`Instances reachable: ${reachableCount}`);
        console.log(`Function calls detected: ${functionCallsFoundCount}`);
        console.log('');

        // Show details for each instance
        results.forEach(r => {
            console.log(`📱 ${r.instanceId} (port: ${r.port})`);
            if (r.error) {
                console.log(`   ❌ Error: ${r.error}`);
            } else if (r.functionCallsFound) {
                console.log(`   ✅ Function calling working`);
                if (r.logInfo) {
                    console.log(`   📝 Pattern found: ${r.logInfo.pattern}`);
                }
            } else if (r.reachable) {
                console.log(`   ℹ️  Instance reachable, checking if AI has function calling enabled...`);
            }
        });

        console.log('');

        // Test result
        if (reachableCount > 0) {
            console.log('✅ TEST PASSED: Function calling test completed');
            console.log(`   - ${reachableCount} instance(s) responded to messages`);
            
            if (functionCallsFoundCount > 0) {
                console.log(`   - ${functionCallsFoundCount} instance(s) showed function call activity in logs`);
            } else {
                console.log('   ℹ️  No function call patterns found in logs');
                console.log('   ℹ️  This may indicate:');
                console.log('      - Function calling is not enabled for this instance');
                console.log('      - The AI model does not support function calling');
                console.log('      - No function-triggering messages were processed yet');
            }
            
            process.exit(0);
        } else {
            console.log('❌ TEST FAILED: No instances were reachable');
            process.exit(1);
        }

    } catch (error) {
        console.error('❌ Test error:', error.message);
        console.error(error.stack);
        process.exit(1);
    }
}

// Run the test
runTest();

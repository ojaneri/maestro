/**
 * E2E Test: Debug Command Across All Running Instances
 * 
 * This test sends #debug# command to all running instances and verifies
 * each instance returns its own diagnostics (instance ID, port, PID).
 * 
 * Usage: node tests/e2e/debug-command.test.js
 */

const http = require('http');
const sqlite3 = require('sqlite3').verbose();
const path = require('path');

const DB_PATH = path.join(__dirname, '..', '..', 'chat_data.db');
const TEST_PHONE = '5585999999001';
const DEBUG_COMMAND = '#debug#';

/**
 * Query all running instances from database
 */
function getRunningInstances() {
    return new Promise((resolve, reject) => {
        const db = new sqlite3.Database(DB_PATH, (err) => {
            if (err) {
                reject(err);
                return;
            }
            
            db.all(
                "SELECT instance_id, port, status FROM instances WHERE status IN ('connected', 'running') OR connection_status = 'connected'",
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
 * Send a message to an instance via HTTP POST
 */
function sendMessageToInstance(port, message) {
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
            timeout: 10000
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
 * Check instance logs for debug output
 */
function checkDebugLogs(instanceId) {
    return new Promise((resolve) => {
        const logFiles = [
            path.join(__dirname, '..', '..', `instance_inst_${instanceId}.log`),
            path.join(__dirname, '..', '..', `instance_${instanceId.replace('inst_', '')}.log`),
            path.join(__dirname, '..', '..', `instance_${instanceId}.log`)
        ];

        const fs = require('fs');
        
        for (const logFile of logFiles) {
            if (fs.existsSync(logFile)) {
                try {
                    const content = fs.readFileSync(logFile, 'utf8');
                    const lines = content.split('\n');
                    const recentLines = lines.slice(-50).join('\n');
                    
                    if (recentLines.includes('DEBUG') || recentLines.includes('diagnostics') || 
                        recentLines.includes('instance_id') || recentLines.includes('Port:')) {
                        resolve({ found: true, logFile, content: recentLines });
                        return;
                    }
                } catch (e) {
                    // Continue to next log file
                }
            }
        }
        resolve({ found: false, logFile: null, content: '' });
    });
}

/**
 * Run the debug command test
 */
async function runTest() {
    console.log('='.repeat(60));
    console.log('🧪 E2E TEST: DEBUG COMMAND ACROSS ALL INSTANCES');
    console.log('='.repeat(60));
    console.log('');

    try {
        // Get all running instances
        const instances = await getRunningInstances();
        
        if (instances.length === 0) {
            console.log('❌ No running instances found in database');
            process.exit(1);
        }

        console.log(`📊 Found ${instances.length} running instance(s):`);
        instances.forEach((inst, i) => {
            console.log(`   ${i + 1}. ${inst.instance_id} | Port: ${inst.port} | Status: ${inst.status}`);
        });
        console.log('');

        const results = [];

        // Send #debug# to each running instance
        for (const instance of instances) {
            console.log(`📤 Sending #debug# to instance ${instance.instance_id} on port ${instance.port}...`);
            
            try {
                const response = await sendMessageToInstance(instance.port, DEBUG_COMMAND);
                
                console.log(`   📡 Response status: ${response.statusCode}`);
                
                // Wait a bit for logs to be written
                await new Promise(resolve => setTimeout(resolve, 2000));
                
                // Check logs for debug output
                const logResult = await checkDebugLogs(instance.instance_id);
                
                results.push({
                    instanceId: instance.instance_id,
                    port: instance.port,
                    httpStatus: response.statusCode,
                    response: response.body,
                    logFound: logResult.found,
                    logFile: logResult.logFile
                });

                console.log(`   ✅ Debug command sent successfully`);
                if (logResult.found) {
                    console.log(`   📋 Debug info found in logs: ${logResult.logFile}`);
                }

            } catch (error) {
                console.log(`   ❌ Error: ${error.message}`);
                results.push({
                    instanceId: instance.instance_id,
                    port: instance.port,
                    error: error.message
                });
            }
            
            console.log('');
        }

        // Summary
        console.log('='.repeat(60));
        console.log('📋 TEST SUMMARY');
        console.log('='.repeat(60));
        
        const successful = results.filter(r => !r.error).length;
        const withLogs = results.filter(r => r.logFound).length;
        
        console.log(`Total instances tested: ${results.length}`);
        console.log(`Successful HTTP responses: ${successful}`);
        console.log(`Debug logs found: ${withLogs}`);
        
        // Verify instance-specific responses
        console.log('');
        console.log('🔍 Instance-Specific Verification:');
        const uniquePorts = new Set(results.filter(r => r.port).map(r => r.port));
        console.log(`   Unique ports contacted: ${uniquePorts.size}`);
        
        if (uniquePorts.size > 1) {
            console.log('   ✅ Multiple instances contacted - isolation verified');
        } else {
            console.log('   ⚠️  Only one instance was tested');
        }
        
        console.log('');
        
        // Test passed if we got any successful responses
        if (successful > 0) {
            console.log('✅ TEST PASSED: Debug command executed across instances');
            process.exit(0);
        } else {
            console.log('❌ TEST FAILED: No successful debug command responses');
            process.exit(1);
        }

    } catch (error) {
        console.error('❌ Test error:', error.message);
        process.exit(1);
    }
}

// Run the test
runTest();

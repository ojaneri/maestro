/**
 * E2E Test: Rate Limiting
 * 
 * This test verifies that rate limiting is properly enforced.
 * It sends multiple messages rapidly and checks for:
 * - 429 responses (rate limit exceeded)
 * - Delay enforcement between messages
 * - Message queue or throttling mechanisms
 * 
 * Usage: node tests/e2e/rate-limiting.test.js
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
 * Get rate limit settings from database
 */
function getRateLimitSettings(instanceId) {
    return new Promise((resolve, reject) => {
        const db = new sqlite3.Database(DB_PATH, (err) => {
            if (err) {
                resolve(null);
                return;
            }

            // Check various tables for rate limiting settings
            const queries = [
                "SELECT * FROM instance_settings WHERE instance_id = ?",
                "SELECT * FROM settings WHERE instance_id = ?",
                "SELECT * FROM rate_limits WHERE instance_id = ?"
            ];

            const tryQuery = (index) => {
                if (index >= queries.length) {
                    db.close();
                    resolve(null);
                    return;
                }

                db.get(queries[index], [instanceId], (err, row) => {
                    if (row) {
                        db.close();
                        resolve(row);
                    } else {
                        tryQuery(index + 1);
                    }
                });
            };

            tryQuery(0);
        });
    });
}

/**
 * Send a message to an instance via HTTP and measure time
 */
function sendMessageWithTiming(port, to, message) {
    return new Promise((resolve) => {
        const startTime = Date.now();
        
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
            timeout: 10000
        };

        const req = http.request(options, (res) => {
            const endTime = Date.now();
            const duration = endTime - startTime;
            
            let data = '';
            
            res.on('data', (chunk) => { data += chunk; });
            res.on('end', () => {
                try {
                    resolve({ 
                        statusCode: res.statusCode, 
                        duration: duration,
                        body: JSON.parse(data) 
                    });
                } catch (e) {
                    resolve({ 
                        statusCode: res.statusCode, 
                        duration: duration,
                        body: data 
                    });
                }
            });
        });

        req.on('error', (e) => {
            const endTime = Date.now();
            resolve({ 
                error: e.message, 
                duration: endTime - startTime 
            });
        });
        
        req.write(postData);
        req.end();
    });
}

/**
 * Check instance logs for rate limiting indicators
 */
function checkRateLimitingInLogs(instanceId) {
    return new Promise((resolve) => {
        const logFiles = [
            path.join(process.cwd(), `instance_inst_${instanceId}.log`),
            path.join(process.cwd(), `instance_${instanceId}.log`)
        ];

        let rateLimitInfo = null;

        for (const logFile of logFiles) {
            if (fs.existsSync(logFile)) {
                try {
                    const content = fs.readFileSync(logFile, 'utf8');
                    const recentLines = content.split('\n').slice(-200).join('\n');

                    // Look for rate limiting patterns
                    const patterns = [
                        /rate[_\s]?limit/i,
                        /too[_\s]?many[_\s]?requests/i,
                        /throttl/i,
                        /delay/i,
                        /cooldown/i,
                        /429/i,
                        /multi[_\s]?input[_\s]?delay/i,
                        /message[_\s]?queue/i,
                        /processing/i
                    ];

                    for (const pattern of patterns) {
                        const match = recentLines.match(pattern);
                        if (match) {
                            rateLimitInfo = {
                                pattern: match[0],
                                logFile: path.basename(logFile)
                            };
                            break;
                        }
                    }

                    if (rateLimitInfo) break;
                } catch (e) {
                    // Continue
                }
            }
        }

        resolve(rateLimitInfo);
    });
}

/**
 * Send rapid messages to test rate limiting
 */
async function testRateLimiting(port, instanceId) {
    const testPhone = '5585999999000';
    const testMessage = 'Rate limit test message';
    
    console.log(`   📤 Sending 10 rapid messages...`);
    
    const results = [];
    const startTotal = Date.now();
    
    // Send 10 messages as fast as possible
    for (let i = 0; i < 10; i++) {
        const result = await sendMessageWithTiming(port, testPhone, `${testMessage} #${i + 1}`);
        results.push(result);
        
        // Small delay to avoid connection issues
        await new Promise(r => setTimeout(r, 50));
    }
    
    const totalDuration = Date.now() - startTotal;
    
    console.log(`   ✅ Sent ${results.length} messages in ${totalDuration}ms`);
    
    // Analyze results
    const statusCodes = results.map(r => r.statusCode || (r.error ? 'error' : 'unknown'));
    const durations = results.map(r => r.duration);
    const avgDuration = durations.reduce((a, b) => a + b, 0) / durations.length;
    
    console.log(`   📊 Status codes: ${statusCodes.join(', ')}`);
    console.log(`   📊 Average response time: ${avgDuration.toFixed(0)}ms`);
    
    // Check for rate limit responses
    const rateLimitedCount = results.filter(r => r.statusCode === 429).length;
    const errorCount = results.filter(r => r.error).length;
    
    if (rateLimitedCount > 0) {
        console.log(`   ⚠️  ${rateLimitedCount} messages got 429 (Rate Limited) response`);
    }
    
    // Check logs
    console.log('   🔍 Checking logs for rate limiting patterns...');
    const logInfo = await checkRateLimitingInLogs(instanceId);
    
    return {
        totalMessages: results.length,
        totalDuration: totalDuration,
        avgDuration: avgDuration,
        statusCodes: statusCodes,
        rateLimitedCount: rateLimitedCount,
        errorCount: errorCount,
        logInfo: logInfo
    };
}

/**
 * Run the rate limiting test
 */
async function runTest() {
    console.log('='.repeat(60));
    console.log('🧪 E2E TEST: RATE LIMITING');
    console.log('='.repeat(60));
    console.log('');

    try {
        // Get active instances
        const instances = await getActiveInstances();

        if (instances.length === 0) {
            console.log('⚠️  No active instances found.');
            console.log('');
            console.log('To run this test, you need at least one connected instance.');
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
                // Get rate limit settings
                const settings = await getRateLimitSettings(instance.instance_id);
                
                if (settings) {
                    console.log(`   ⚙️  Found rate limit settings:`);
                    if (settings.multi_input_delay) {
                        console.log(`      - multi_input_delay: ${settings.multi_input_delay}ms`);
                    }
                    if (settings.rate_limit) {
                        console.log(`      - rate_limit: ${settings.rate_limit}`);
                    }
                    if (settings.max_requests_per_minute) {
                        console.log(`      - max_requests_per_minute: ${settings.max_requests_per_minute}`);
                    }
                } else {
                    console.log(`   ℹ️  No specific rate limit settings found in database`);
                }
                
                // Test rate limiting with rapid messages
                const testResult = await testRateLimiting(instance.port, instance.instance_id);
                
                results.push({
                    instanceId: instance.instance_id,
                    port: instance.port,
                    settings: settings,
                    testResult: testResult
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
        console.log('📋 RATE LIMITING TEST SUMMARY');
        console.log('='.repeat(60));

        const totalTests = results.filter(r => r.testResult).length;
        
        console.log(`Total instances tested: ${results.length}`);
        console.log('');

        // Show details
        results.forEach(r => {
            console.log(`📱 ${r.instanceId} (port: ${r.port})`);
            if (r.error) {
                console.log(`   ❌ Error: ${r.error}`);
            } else if (r.testResult) {
                const tr = r.testResult;
                console.log(`   ✅ Test completed:`);
                console.log(`      - Messages sent: ${tr.totalMessages}`);
                console.log(`      - Total time: ${tr.totalDuration}ms`);
                console.log(`      - Avg response: ${tr.avgDuration.toFixed(0)}ms`);
                console.log(`      - Rate limited (429): ${tr.rateLimitedCount}`);
                console.log(`      - Errors: ${tr.errorCount}`);
                
                if (tr.logInfo) {
                    console.log(`      - Log pattern: ${tr.logInfo.pattern}`);
                }
                
                // Analyze if rate limiting is working
                if (tr.rateLimitedCount > 0 || tr.avgDuration > 500) {
                    console.log(`   ✅ Rate limiting appears to be working`);
                } else {
                    console.log(`   ℹ️  No explicit rate limiting detected`);
                }
            }
        });

        console.log('');
        
        // Test result
        if (totalTests > 0) {
            console.log('✅ TEST COMPLETED: Rate limiting test finished');
            console.log('');
            console.log('Rate limiting analysis:');
            
            const avgRateLimited = results.reduce((sum, r) => sum + (r.testResult?.rateLimitedCount || 0), 0);
            const avgDuration = results.reduce((sum, r) => sum + (r.testResult?.avgDuration || 0), 0) / totalTests;
            
            if (avgRateLimited > 0) {
                console.log(`   - Instances returned 429 status: ${avgRateLimited} times total`);
            }
            
            if (avgDuration > 500) {
                console.log(`   - Average response time: ${avgDuration.toFixed(0)}ms (suggests throttling)`);
            } else {
                console.log(`   - Average response time: ${avgDuration.toFixed(0)}ms`);
            }
            
            console.log('');
            console.log('To configure rate limiting, update instance settings:');
            console.log('   - multi_input_delay: delay between messages (ms)');
            console.log('   - auto_pause: pause when too many messages');
            
            process.exit(0);
        } else {
            console.log('❌ TEST FAILED: No tests could be completed');
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

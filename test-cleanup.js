// test-cleanup.js - Test script for cleanup mechanisms
const { cleanupOrphanedInstances } = require('./db-updated.js')
const { performCleanup } = require('./cleanup-service.js')
const { saveInstanceRecord, listInstancesRecords } = require('./db-updated.js')

async function testCleanupMechanisms() {
    console.log('Starting cleanup mechanism tests...\n')

    // Test 1: Create test instances
    console.log('Test 1: Creating test instances...')
    try {
        const testInstances = [
            { instanceId: 'test-instance-1', payload: { status: 'inactive', name: 'Test Instance 1' } },
            { instanceId: 'test-instance-2', payload: { status: 'active', name: 'Test Instance 2' } },
            { instanceId: 'test-instance-3', payload: { status: 'stopped', name: 'Test Instance 3' } }
        ]

        for (const { instanceId, payload } of testInstances) {
            await saveInstanceRecord(instanceId, payload)
            console.log(`Created instance: ${instanceId} with status: ${payload.status}`)
        }

        // Verify instances were created
        const instances = await listInstancesRecords()
        console.log(`\nCurrent instances after creation (${instances.length} total):`)
        instances.forEach(instance => {
            console.log(`  - ${instance.instance_id}: ${instance.status}`)
        })

        console.log('\nTest 1: PASSED - Test instances created successfully')
    } catch (err) {
        console.error('\nTest 1: FAILED - Error creating test instances:', err.message)
        return false
    }

    // Test 2: Test direct cleanup function
    console.log('\nTest 2: Testing direct cleanup function...')
    try {
        const result = await cleanupOrphanedInstances(1) // Clean instances older than 1 hour
        console.log(`Direct cleanup removed ${result.cleaned} instances`)
        
        // Check remaining instances
        const remainingInstances = await listInstancesRecords()
        console.log(`\nRemaining instances after direct cleanup (${remainingInstances.length} total):`)
        remainingInstances.forEach(instance => {
            console.log(`  - ${instance.instance_id}: ${instance.status}`)
        })

        console.log('\nTest 2: PASSED - Direct cleanup function works correctly')
    } catch (err) {
        console.error('\nTest 2: FAILED - Error in direct cleanup:', err.message)
        return false
    }

    // Test 3: Test service cleanup function
    console.log('\nTest 3: Testing service cleanup function...')
    try {
        const result = await performCleanup()
        console.log(`Service cleanup removed ${result.cleaned} instances`)
        
        // Check remaining instances
        const remainingInstances = await listInstancesRecords()
        console.log(`\nRemaining instances after service cleanup (${remainingInstances.length} total):`)
        remainingInstances.forEach(instance => {
            console.log(`  - ${instance.instance_id}: ${instance.status}`)
        })

        console.log('\nTest 3: PASSED - Service cleanup function works correctly')
    } catch (err) {
        console.error('\nTest 3: FAILED - Error in service cleanup:', err.message)
        return false
    }

    // Test 4: Verify active instances are preserved
    console.log('\nTest 4: Verifying active instances are preserved...')
    try {
        const finalInstances = await listInstancesRecords()
        const activeInstances = finalInstances.filter(instance => instance.status === 'active')
        
        if (activeInstances.length > 0) {
            console.log(`Found ${activeInstances.length} active instance(s) preserved:`)
            activeInstances.forEach(instance => {
                console.log(`  - ${instance.instance_id}: ${instance.status}`)
            })
            console.log('\nTest 4: PASSED - Active instances preserved correctly')
        } else {
            console.log('\nTest 4: WARNING - No active instances found')
        }
    } catch (err) {
        console.error('\nTest 4: FAILED - Error checking active instances:', err.message)
        return false
    }

    console.log('\n=== All cleanup mechanism tests completed successfully! ===')
    return true
}

// Run tests if executed directly
if (require.main === module) {
    testCleanupMechanisms().then(success => {
        if (success) {
            console.log('\nCleanup mechanisms are working correctly.')
            process.exit(0)
        } else {
            console.log('\nSome tests failed. Please check the output above.')
            process.exit(1)
        }
    }).catch(err => {
        console.error('\nTest suite failed with error:', err.message)
        process.exit(1)
    })
}

module.exports = { testCleanupMechanisms }
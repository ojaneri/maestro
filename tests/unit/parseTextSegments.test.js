/**
 * Unit Tests for parseTextSegments Function
 * Tests the text splitting behavior with # delimiter
 * Run: node tests/unit/parseTextSegments.test.js
 */

// Copy of parseTextSegments function from src/whatsapp-server/ai/index.js
function parseTextSegments(text) {
    if (!text) return [];
    const segments = text.split('#').filter(s => s.trim());
    return segments.length > 0 ? segments : [text];
}

// Test utilities
let passed = 0;
let failed = 0;

function assertEqual(actual, expected, testName) {
    const actualStr = JSON.stringify(actual);
    const expectedStr = JSON.stringify(expected);
    
    if (actualStr === expectedStr) {
        console.log(`✅ PASS: ${testName}`);
        passed++;
        return true;
    } else {
        console.log(`❌ FAIL: ${testName}`);
        console.log(`   Expected: ${expectedStr}`);
        console.log(`   Actual:   ${actualStr}`);
        failed++;
        return false;
    }
}

function assertArrayLength(actual, expectedLength, testName) {
    if (Array.isArray(actual) && actual.length === expectedLength) {
        console.log(`✅ PASS: ${testName}`);
        passed++;
        return true;
    } else {
        console.log(`❌ FAIL: ${testName}`);
        console.log(`   Expected length: ${expectedLength}`);
        console.log(`   Actual: ${JSON.stringify(actual)}`);
        failed++;
        return false;
    }
}

// Test cases
console.log('='.repeat(60));
console.log('🧪 UNIT TESTS: parseTextSegments Function');
console.log('='.repeat(60));
console.log('');

console.log('--- Test 1: Basic splitting by # character ---');
{
    const input = 'OI#Tudo bem?#Meu nome é Fulano';
    const result = parseTextSegments(input);
    assertArrayLength(result, 3, 'Basic split returns 3 segments');
    assertEqual(result[0], 'OI', 'First segment is "OI"');
    assertEqual(result[1], 'Tudo bem?', 'Second segment is "Tudo bem?"');
    assertEqual(result[2], 'Meu nome é Fulano', 'Third segment is "Meu nome é Fulano"');
}
console.log('');

console.log('--- Test 2: Empty segments are filtered out ---');
{
    const input = 'Message1##Message2';
    const result = parseTextSegments(input);
    assertArrayLength(result, 2, 'Empty segments filtered, returns 2 segments');
    assertEqual(result[0], 'Message1', 'First non-empty segment');
    assertEqual(result[1], 'Message2', 'Second non-empty segment');
}
console.log('');

console.log('--- Test 3: Multiple # delimiters work correctly ---');
{
    const input = 'A#B#C#D#E';
    const result = parseTextSegments(input);
    assertArrayLength(result, 5, 'Multiple delimiters returns 5 segments');
}
console.log('');

console.log('--- Test 4: Text without # returns single segment ---');
{
    const input = 'Olá, tudo bem?';
    const result = parseTextSegments(input);
    assertArrayLength(result, 1, 'No delimiter returns 1 segment');
    assertEqual(result[0], 'Olá, tudo bem?', 'Segment is original text');
}
console.log('');

console.log('--- Test 5: Edge case - Empty string ---');
{
    const input = '';
    const result = parseTextSegments(input);
    assertArrayLength(result, 0, 'Empty string returns empty array');
}
console.log('');

console.log('--- Test 6: Edge case - Only # characters ---');
{
    const input = '###';
    const result = parseTextSegments(input);
    // When all segments are empty, function returns original text
    assertArrayLength(result, 1, 'Only # returns 1 segment (original)');
    assertEqual(result[0], '###', 'Returns original text');
}
console.log('');

console.log('--- Test 7: Edge case - Consecutive ### (4+ hashes) ---');
{
    const input = '####';
    const result = parseTextSegments(input);
    assertArrayLength(result, 1, '4+ hashes returns 1 segment (original)');
}
console.log('');

console.log('--- Test 8: Edge case - null/undefined input ---');
{
    const resultNull = parseTextSegments(null);
    const resultUndefined = parseTextSegments(undefined);
    assertArrayLength(resultNull, 0, 'null returns empty array');
    assertArrayLength(resultUndefined, 0, 'undefined returns empty array');
}
console.log('');

console.log('--- Test 9: Whitespace handling ---');
{
    const input = '  Hello #  World  #  Test  ';
    const result = parseTextSegments(input);
    // Note: The function filters empty segments but does NOT trim the remaining content
    assertArrayLength(result, 3, 'Whitespace filtered, returns 3 segments');
    assertEqual(result[0], '  Hello ', 'First segment retains whitespace');
    assertEqual(result[1], '  World  ', 'Second segment retains whitespace');
    assertEqual(result[2], '  Test  ', 'Third segment retains whitespace');
}
console.log('');

console.log('--- Test 10: Single character text ---');
{
    const input = 'A';
    const result = parseTextSegments(input);
    assertArrayLength(result, 1, 'Single char returns 1 segment');
    assertEqual(result[0], 'A', 'Segment is "A"');
}
console.log('');

console.log('--- Test 11: Leading # delimiter ---');
{
    const input = '#Hello World';
    const result = parseTextSegments(input);
    assertArrayLength(result, 1, 'Leading # filtered, returns 1 segment');
    assertEqual(result[0], 'Hello World', 'Segment is "Hello World"');
}
console.log('');

console.log('--- Test 12: Trailing # delimiter ---');
{
    const input = 'Hello World#';
    const result = parseTextSegments(input);
    assertArrayLength(result, 1, 'Trailing # filtered, returns 1 segment');
    assertEqual(result[0], 'Hello World', 'Segment is "Hello World"');
}
console.log('');

// Summary
console.log('='.repeat(60));
console.log('📊 TEST SUMMARY');
console.log('='.repeat(60));
console.log(`✅ Passed: ${passed}`);
console.log(`❌ Failed: ${failed}`);
console.log(`📝 Total:  ${passed + failed}`);
console.log('');

if (failed > 0) {
    console.log('⚠️  Some tests failed!');
    process.exit(1);
} else {
    console.log('🎉 All tests passed!');
    process.exit(0);
}

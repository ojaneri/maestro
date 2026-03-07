#!/usr/bin/env node
/**
 * HTML Report Generator for Jest Test Results
 * Run after tests to generate a detailed HTML report
 */

const fs = require('fs');
const path = require('path');

const TIMESTAMP = process.env.TIMESTAMP || new Date().toISOString().replace(/[:.]/g, '-');
const DURATION = process.env.DURATION || '0';
const DURATION_S = process.env.DURATION_S || '0';

// Read Jest JSON output from file (look in current directory, tests directory, and parent)
const jestOutputFile = path.join(process.cwd(), 'tests', 'jest-output.json');
const jestOutputFileAlt = path.join(process.cwd(), 'jest-output.json');
const jestOutputFileAlt2 = path.join(process.cwd(), '..', 'tests', 'jest-output.json');

let jestOutput;
if (fs.existsSync(jestOutputFile)) {
    jestOutput = fs.readFileSync(jestOutputFile, 'utf8');
} else if (fs.existsSync(jestOutputFileAlt)) {
    jestOutput = fs.readFileSync(jestOutputFileAlt, 'utf8');
} else if (fs.existsSync(jestOutputFileAlt2)) {
    jestOutput = fs.readFileSync(jestOutputFileAlt2, 'utf8');
} else {
    console.error('Jest output file not found');
    process.exit(1);
}

// Remove ANSI escape codes - comprehensive pattern
function stripAnsi(str) {
    // Match ANSI escape sequences: ESC[...letter
    const ansiPattern = /\x1b\[[0-9;]*[A-Za-z]/g;
    // Also match other common ANSI sequences
    const ansiPattern2 = /\x1b\][^\x07]*\x07/g;
    
    return str
        .replace(ansiPattern, '')
        .replace(ansiPattern2, '')
        .replace(/\[[0-9;]*m/g, '');
}

jestOutput = stripAnsi(jestOutput);

// Extract JSON from Jest output - find the closing bracket and extract valid JSON
let jsonStr = jestOutput;

// Find JSON by looking for 'numFailedTestSuites' (the start of Jest JSON output)
const jsonStartMarker = '"numFailedTestSuites"';
const jsonStartIdx = jestOutput.indexOf(jsonStartMarker);

if (jsonStartIdx >= 0) {
    // Find the opening brace before this marker
    const beforeJson = jestOutput.substring(0, jsonStartIdx);
    const lastBraceBefore = beforeJson.lastIndexOf('{');
    
    if (lastBraceBefore >= 0) {
        // Find the last closing brace in the entire output
        const lastBrace = jestOutput.lastIndexOf('}');
        
        if (lastBrace > lastBraceBefore) {
            jsonStr = jestOutput.substring(lastBraceBefore, lastBrace + 1);
        }
    }
}

// Parse the Jest JSON output
let data;
try {
    data = JSON.parse(jsonStr);
} catch (e) {
    console.error('Failed to parse Jest output:', e.message);
    process.exit(1);
}

const numSuites = data.numTotalTestSuites || 0;
const numTests = data.numTotalTests || 0;
const numPassed = data.numPassedTests || 0;
const numFailed = data.numFailedTests || 0;
const testResults = data.testResults || [];
const success = data.success;

const execDate = new Date().toLocaleString('pt-BR');
const statusClass = success ? 'success' : 'error';
const statusText = success ? '✓ TODOS OS TESTES PASSARAM' : '✗ ALGUNS TESTES FALHARAM';

let html = `<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maestro Test Report - ${TIMESTAMP}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); min-height: 100vh; padding: 20px; color: #eee; }
        .container { max-width: 1200px; margin: 0 auto; }
        header { text-align: center; margin-bottom: 30px; }
        h1 { font-size: 2.5em; margin-bottom: 10px; background: linear-gradient(90deg, #00d4ff, #7c3aed); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .meta { color: #888; font-size: 0.9em; }
        .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card { background: rgba(255,255,255,0.05); border-radius: 12px; padding: 20px; text-align: center; border: 1px solid rgba(255,255,255,0.1); }
        .card.success { border-color: #10b981; background: rgba(16,185,129,0.1); }
        .card.error { border-color: #ef4444; background: rgba(239,68,68,0.1); }
        .card h3 { font-size: 2em; margin-bottom: 5px; }
        .card.success h3 { color: #10b981; }
        .card.error h3 { color: #ef4444; }
        .card p { color: #aaa; font-size: 0.9em; }
        .status-badge { display: inline-block; padding: 8px 16px; border-radius: 20px; font-weight: bold; font-size: 1.2em; margin-bottom: 20px; }
        .status-badge.success { background: rgba(16,185,129,0.2); color: #10b981; border: 2px solid #10b981; }
        .status-badge.error { background: rgba(239,68,68,0.2); color: #ef4444; border: 2px solid #ef4444; }
        .test-suites { margin-top: 30px; }
        .suite { background: rgba(255,255,255,0.03); border-radius: 12px; margin-bottom: 20px; overflow: hidden; border: 1px solid rgba(255,255,255,0.1); }
        .suite-header { background: rgba(255,255,255,0.05); padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
        .suite-header:hover { background: rgba(255,255,255,0.08); }
        .suite-title { font-size: 1.1em; font-weight: 600; }
        .suite-stats { display: flex; gap: 15px; }
        .suite-stat { padding: 4px 12px; border-radius: 12px; font-size: 0.85em; }
        .suite-stat.pass { background: rgba(16,185,129,0.2); color: #10b981; }
        .suite-stat.fail { background: rgba(239,68,68,0.2); color: #ef4444; }
        .suite-tests { display: none; padding: 15px 20px; }
        .suite.open .suite-tests { display: block; }
        .test-item { padding: 10px 15px; margin: 5px 0; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; }
        .test-item.pass { background: rgba(16,185,129,0.1); border-left: 3px solid #10b981; }
        .test-item.fail { background: rgba(239,68,68,0.1); border-left: 3px solid #ef4444; }
        .test-name { font-family: 'Monaco','Menlo',monospace; font-size: 0.9em; }
        .test-time { color: #888; font-size: 0.8em; }
        .test-status { font-size: 1.2em; }
        .footer { text-align: center; margin-top: 40px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.1); color: #666; font-size: 0.85em; }
        .toggle-icon { transition: transform 0.3s; }
        .suite.open .toggle-icon { transform: rotate(180deg); }
        .test-description { font-size: 0.8em; color: #888; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🧪 Maestro Test Report</h1>
            <p class="meta">Executado em: ${execDate}</p>
            <p class="meta">Duração: ${DURATION}ms (${DURATION_S}s)</p>
        </header>
        <div style="text-align: center;">
            <span class="status-badge ${statusClass}">${statusText}</span>
        </div>
        <div class="summary">
            <div class="card"><h3>${numSuites}</h3><p>Suítes de Teste</p></div>
            <div class="card success"><h3>${numPassed}</h3><p>Testes Passados</p></div>
            <div class="card error"><h3>${numFailed}</h3><p>Testes Falhados</p></div>
            <div class="card"><h3>${numTests}</h3><p>Total de Testes</p></div>
        </div>
        <div class="test-suites">
            <h2 style="margin-bottom: 20px; color: #ccc;">📋 Detalhamento por Suíte</h2>
`;

// Add test suites
testResults.forEach((suite, idx) => {
    const passCount = suite.assertionResults ? suite.assertionResults.filter(r => r.status === 'passed').length : 0;
    const failCount = suite.assertionResults ? suite.assertionResults.filter(r => r.status === 'failed').length : 0;
    const shortName = suite.name.replace(/^.*\//, '').replace(/\.test\.js$/, '');
    
    html += `
        <div class="suite" id="suite-${idx}">
            <div class="suite-header" onclick="document.getElementById('suite-${idx}').classList.toggle('open')">
                <span class="suite-title">${shortName}</span>
                <div class="suite-stats">
                    <span class="suite-stat pass">${passCount} ✓</span>
                    <span class="suite-stat fail">${failCount} ✗</span>
                    <span class="toggle-icon">▼</span>
                </div>
            </div>
            <div class="suite-tests">
    `;
    
    if (suite.assertionResults) {
        suite.assertionResults.forEach(test => {
            const itemClass = test.status === 'passed' ? 'pass' : 'fail';
            const statusIcon = test.status === 'passed' ? '✓' : '✗';
            const time = test.duration ? test.duration + 'ms' : '';
            const ancestorTitles = test.ancestorTitles ? test.ancestorTitles.join(' > ') : '';
            
            html += `
                <div class="test-item ${itemClass}">
                    <div>
                        <div class="test-name">${test.title}</div>
                        <div class="test-description">${ancestorTitles}</div>
                    </div>
                    <div style="text-align: right;">
                        <span class="test-time">${time}</span>
                        <span class="test-status">${statusIcon}</span>
                    </div>
                </div>
            `;
        });
    }
    
    html += `
            </div>
        </div>
    `;
});

html += `
        </div>
        <div class="footer">
            <p>Gerado automaticamente pelo Maestro Test Runner</p>
            <p>Versão: 1.0.0</p>
        </div>
    </div>
</body>
</html>
`;

// Write HTML report to tests directory
const reportFile = path.join(process.cwd(), 'tests', 'report.html');
fs.writeFileSync(reportFile, html);

console.log('HTML report generated:', reportFile);

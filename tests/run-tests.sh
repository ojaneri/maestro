#!/bin/bash
#
# Maestro Test Runner with HTML Report Generation
# Returns exit code 0 (green) on success, 1 (red) on failure
#

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Directories
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PERF_DIR="$SCRIPT_DIR/performance"
REPORT_FILE="$SCRIPT_DIR/report.html"

# Create performance directory if not exists
mkdir -p "$PERF_DIR"

# Timestamp for this run
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
PERF_FILE="$PERF_DIR/run_$TIMESTAMP.json"

echo "============================================"
echo "  Maestro Test Runner"
echo "============================================"
echo ""

# Change to tests directory
cd "$SCRIPT_DIR"

# Run Jest tests with JSON output
echo "Running tests..."
echo ""

START_TIME=$(date +%s%N)
JEST_OUTPUT=$(npx jest --colors --json 2>&1)
EXIT_CODE=$?
END_TIME=$(date +%s%N)

# Save Jest output for report generation
cd tests
echo "$JEST_OUTPUT" > jest-output.json
cd ..

# Calculate duration in milliseconds
DURATION=$(( (END_TIME - START_TIME) / 1000000 ))
DURATION_S=$(echo "scale=3; $DURATION/1000" | bc)

# Show output summary
echo "$JEST_OUTPUT" | tail -30

# Generate HTML report
export TIMESTAMP
export DURATION
export DURATION_S
node tests/generate-report.js

# Extract numbers from Jest output
NUM_PASSED=$(echo "$JEST_OUTPUT" | grep -o '"numPassedTests":[0-9]*' | grep -o '[0-9]*' | head -1)
NUM_FAILED=$(echo "$JEST_OUTPUT" | grep -o '"numFailedTests":[0-9]*' | grep -o '[0-9]*' | head -1)
NUM_SUITES=$(echo "$JEST_OUTPUT" | grep -o '"numTotalTestSuites":[0-9]*' | grep -o '[0-9]*' | head -1)
NUM_TESTS=$(echo "$JEST_OUTPUT" | grep -o '"numTotalTests":[0-9]*' | grep -o '[0-9]*' | head -1)

# Check if tests passed and save metrics
if [ $EXIT_CODE -ne 0 ]; then
    cat > "$PERF_FILE" << EOF
{
  "timestamp": "$TIMESTAMP",
  "datetime": "$(date -Iseconds)",
  "numTotalTestSuites": 0,
  "numTotalTests": 0,
  "numPassedTests": 0,
  "numFailedTests": 0,
  "duration": $DURATION,
  "success": false
}
EOF
    echo ""
    echo "============================================"
    echo -e "${RED}✗ TESTS FAILED${NC}"
    echo "============================================"
    echo ""
    echo "HTML Report saved to: $REPORT_FILE"
    
    # Copy HTML report to web-accessible location for kapjus.kaponline.com.br
    cp "$SCRIPT_DIR/report.html" /var/www/html/kapjus.kaponline.com.br/public/report.html
    chown www-data:webdev /var/www/html/kapjus.kaponline.com.br/public/report.html
    chmod 644 /var/www/html/kapjus.kaponline.com.br/public/report.html
    echo "HTML Report also available at: https://kapjus.kaponline.com.br/report.html"
    exit 1
else
    cat > "$PERF_FILE" << EOF
{
  "timestamp": "$TIMESTAMP",
  "datetime": "$(date -Iseconds)",
  "numTotalTestSuites": ${NUM_SUITES:-0},
  "numTotalTests": ${NUM_TESTS:-0},
  "numPassedTests": ${NUM_PASSED:-0},
  "numFailedTests": ${NUM_FAILED:-0},
  "duration": $DURATION,
  "success": true
}
EOF
    
    echo ""
    echo "============================================"
    echo -e "${GREEN}✓ ALL TESTS PASSED${NC}"
    echo "============================================"
    echo ""
    echo "HTML Report saved to: $REPORT_FILE"
    
    # Copy HTML report to web-accessible location for kapjus.kaponline.com.br
    cp "$SCRIPT_DIR/report.html" /var/www/html/kapjus.kaponline.com.br/public/report.html
    chown www-data:webdev /var/www/html/kapjus.kaponline.com.br/public/report.html
    chmod 644 /var/www/html/kapjus.kaponline.com.br/public/report.html
    echo "HTML Report also available at: https://kapjus.kaponline.com.br/report.html"
    echo ""
    
    echo "Performance Summary:"
    echo "  - Suites: $NUM_SUITES"
    echo "  - Tests: $NUM_PASSED passed, $NUM_FAILED failed"
    echo "  - Duration: ${DURATION}ms (${DURATION_S}s)"
    
    # Compare with previous run
    PREV_FILE=$(ls -t "$PERF_DIR"/run_*.json 2>/dev/null | head -2 | tail -1)
    if [ -f "$PREV_FILE" ] && [ "$PREV_FILE" != "$PERF_FILE" ]; then
        PREV_DURATION=$(grep -o '"duration":[[:space:]]*[0-9]*' "$PREV_FILE" | grep -o '[0-9]*' | head -1)
        if [ -n "$PREV_DURATION" ] && [ "$PREV_DURATION" -gt 0 ]; then
            DIFF=$((DURATION - PREV_DURATION))
            DIFF_SIGN=$( [ "$DIFF" -gt 0 ] && echo "+$DIFF" || echo "$DIFF" )
            echo "  - vs previous: ${DIFF_SIGN}ms"
        fi
    fi
    
    exit 0
fi

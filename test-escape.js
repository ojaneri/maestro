function escapeHtml(value) {
    if (value === null || value === undefined) return "";
    return String(value)
        .replace(/&/g, '&')
        .replace(/</g, '<')
        .replace(/>/g, '>')
        .replace(/"/g, '"')
        .replace(/'/g, ''');
}

console.log('Test 1:', escapeHtml("<script>alert('XSS')</script>"));
console.log('Test 2:', escapeHtml('Single quote: \'test\''));
console.log('Test 3:', escapeHtml('Double quote: "test"'));
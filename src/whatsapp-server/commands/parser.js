/**
 * @fileoverview Command parser - extracts and parses assistant commands from text
 * @module whatsapp-server/commands/parser
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Handles command extraction and argument parsing
 * 
 * Supports two formats:
 * - [[command_name(arg1, arg2, ...)]] (bracket format)
 * - Commands after &&& separator (ampersand format)
 */

/**
 * Parse arguments from string "arg1", "arg2", 123, etc.
 * @param {string} argsStr - Arguments string
 * @returns {Array}
 */
function parseArguments(argsStr) {
  if (!argsStr || !argsStr.trim()) return [];
  
  const args = [];
  // Simple parsing - split by comma, handle quotes
  const parts = argsStr.split(',');
  for (const part of parts) {
    let cleaned = part.trim();
    // Remove quotes if present
    if ((cleaned.startsWith('"') && cleaned.endsWith('"')) ||
        (cleaned.startsWith("'") && cleaned.endsWith("'"))) {
      cleaned = cleaned.slice(1, -1);
    }
    args.push(cleaned);
  }
  return args;
}

/**
 * Extract assistant commands from AI response text
 * Commands are formatted as [[command_name(arg1, arg2, ...)]] or as lines after &&& separator
 * @param {string} text - AI response text
 * @returns {Array<Object>}
 */
function extractAssistantCommands(text) {
  const commands = [];
  
  // Split by &&& separator
  if (text.includes('&&&')) {
    const parts = text.split('&&&');
    const commandSection = parts[1] ? parts[1].trim() : '';
    
    // Extract function calls from command section
    // Format: function_name("arg1", "arg2", ...)
    const funcRegex = /([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)/g;
    let match;
    while ((match = funcRegex.exec(commandSection)) !== null) {
      const funcName = match[1];
      const args = match[2];
      // Parse arguments properly
      const parsedArgs = parseArguments(args);
      commands.push({ name: funcName, args: parsedArgs });
    }
  }
  
  // Also check for [[command(args)]] format (backward compatibility)
  const bracketRegex = /\[\[([a-zA-Z_]+)\(([^)\]]*)\)\]\]/g;
  let match;
  while ((match = bracketRegex.exec(text)) !== null) {
    commands.push({ name: match[1], args: parseArguments(match[2]) });
  }
  
  return commands;
}

/**
 * Extract user message and commands from AI response
 * @param {string} text - AI response text
 * @returns {Object} - { userMessage: string, commands: Array }
 */
function parseAIResponse(text) {
  let userMessage = text;
  const commands = [];
  
  if (text.includes('&&&')) {
    const parts = text.split('&&&');
    userMessage = parts[0].trim();
    const commandSection = parts[1] ? parts[1].trim() : '';
    
    // Extract function calls from command section
    const funcRegex = /([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)/g;
    let match;
    while ((match = funcRegex.exec(commandSection)) !== null) {
      const funcName = match[1];
      const args = match[2];
      const parsedArgs = parseArguments(args);
      commands.push({ name: funcName, args: parsedArgs });
    }
  }
  
  // Also check for [[command(args)]] format (backward compatibility)
  const bracketRegex = /\[\[([a-zA-Z_]+)\(([^)\]]*)\)\]\]/g;
  let match;
  while ((match = bracketRegex.exec(text)) !== null) {
    commands.push({ name: match[1], args: parseArguments(match[2]) });
  }
  
  return { userMessage, commands };
}

/**
 * Parse function arguments string into object
 * @param {string} argsString - Arguments string (e.g., "key1='value1', key2=123")
 * @returns {Object}
 */
function parseFunctionArgs(argsString) {
  if (!argsString || argsString.trim() === '') {
    return {};
  }
  
  const args = {};
  const tokens = tokenizeArgs(argsString);
  
  for (const token of tokens) {
    const [key, value] = parseKeyValue(token);
    if (key) {
      args[key] = value;
    }
  }
  
  return args;
}

/**
 * Tokenize arguments string
 * @param {string} argsString - Arguments string
 * @returns {Array<string>}
 */
function tokenizeArgs(argsString) {
  const tokens = [];
  let current = '';
  let depth = 0;
  let inString = false;
  let stringChar = '';
  
  for (let i = 0; i < argsString.length; i++) {
    const char = argsString[i];
    
    if (inString) {
      if (char === stringChar) {
        inString = false;
      }
      current += char;
    } else {
      if (char === '"' || char === "'") {
        inString = true;
        stringChar = char;
        current += char;
      } else if (char === '(') {
        depth++;
        current += char;
      } else if (char === ')') {
        depth--;
        current += char;
      } else if (char === ',' && depth === 0) {
        tokens.push(current.trim());
        current = '';
      } else {
        current += char;
      }
    }
  }
  
  if (current.trim()) {
    tokens.push(current.trim());
  }
  
  return tokens;
}

/**
 * Parse key=value token
 * @param {string} token - Key=value token
 * @returns {Array}
 */
function parseKeyValue(token) {
  const eqIndex = token.indexOf('=');
  
  if (eqIndex === -1) {
    return [null, null];
  }
  
  const key = token.substring(0, eqIndex).trim();
  const valueStr = token.substring(eqIndex + 1).trim();
  
  const value = parseValue(valueStr);
  
  return [key, value];
}

/**
 * Parse string value to appropriate type
 * @param {string} valueStr - Value string
 * @returns {*}
 */
function parseValue(valueStr) {
  // Remove surrounding quotes if present
  if ((valueStr.startsWith('"') && valueStr.endsWith('"')) ||
      (valueStr.startsWith("'") && valueStr.endsWith("'"))) {
    return valueStr.slice(1, -1);
  }
  
  // Try to parse as number
  if (/^-?\d+$/.test(valueStr)) {
    return parseInt(valueStr, 10);
  }
  
  if (/^-?\d+\.\d+$/.test(valueStr)) {
    return parseFloat(valueStr);
  }
  
  // Try to parse as boolean
  if (valueStr.toLowerCase() === 'true') return true;
  if (valueStr.toLowerCase() === 'false') return false;
  if (valueStr.toLowerCase() === 'null') return null;
  
  // Return as string
  return valueStr;
}

/**
 * Escape special regex characters in a string
 * @param {string} str - String to escape
 * @returns {string}
 */
function escapeRegex(str) {
  return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Remove commands from text (replace with explanation)
 * @param {string} text - Original text
 * @param {Array} commands - Extracted commands
 * @returns {string}
 */
function removeCommandsFromText(text, commands) {
  if (!text) return '';
  let result = text;
  
  // FIRST: Remove &&& separator and everything after it
  if (result.includes('&&&')) {
    const parts = result.split('&&&');
    result = parts[0].trim();
  }
  
  // ALSO: Remove --- separator (alternative format some AIs use)
  if (result.includes('---')) {
    const parts = result.split('---');
    result = parts[0].trim();
  }
  
  // Also remove [[command(args)]] format
  for (const command of commands) {
    if (command.name) {
      // Remove bracket format: [[command(args)]]
      const bracketPattern = new RegExp(`\\[\\[${escapeRegex(command.name)}\\([^\\]]*\\)\\]\\]`, 'g');
      result = result.replace(bracketPattern, '');
    }
  }
  
  return result.trim();
}

module.exports = {
  extractAssistantCommands,
  parseFunctionArgs,
  parseArguments,
  parseAIResponse,
  removeCommandsFromText,
};

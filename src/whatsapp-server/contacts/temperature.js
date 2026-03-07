/**
 * @fileoverview Contact temperature calculation and delay determination
 * @module whatsapp-server/contacts/temperature
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Determines contact "temperature" for message pacing
 */

// Temperature thresholds (in hours)
const TEMPERATURE_THRESHOLDS = {
  HOT: 0,       // Active conversation (< 1 hour)
  WARM: 24,     // Recent contact (< 24 hours)
  COOL: 72,     // Not recent (< 3 days)
  COLD: 168,    // Week since last contact (1 week)
  FROZEN: 720,  // Month since last contact (30 days)
};

// Delay configurations (in milliseconds)
const TEMPERATURE_DELAYS = {
  HOT: 1000,    // 1 second - fast response
  WARM: 5000,   // 5 seconds
  COOL: 15000,  // 15 seconds
  COLD: 30000,  // 30 seconds
  FROZEN: 60000, // 1 minute
};

/**
 * Determine temperature based on last contact time
 * @param {Date|string} lastContact - Last contact timestamp
 * @returns {string}
 */
function determineTemperature(lastContact) {
  if (!lastContact) return 'FROZEN';
  
  const lastContactTime = new Date(lastContact).getTime();
  const now = Date.now();
  const hoursSinceContact = (now - lastContactTime) / (1000 * 60 * 60);
  
  if (hoursSinceContact <= TEMPERATURE_THRESHOLDS.HOT) return 'HOT';
  if (hoursSinceContact <= TEMPERATURE_THRESHOLDS.WARM) return 'WARM';
  if (hoursSinceContact <= TEMPERATURE_THRESHOLDS.COOL) return 'COOL';
  if (hoursSinceContact <= TEMPERATURE_THRESHOLDS.COLD) return 'COLD';
  
  return 'FROZEN';
}

/**
 * Calculate delay based on temperature
 * @param {string} temperature - Temperature level
 * @returns {number}
 */
function calculateTemperatureDelay(temperature) {
  return TEMPERATURE_DELAYS[temperature] || TEMPERATURE_DELAYS.COOL;
}

/**
 * Get temperature info for a contact
 * @param {Object} contact - Contact object with lastContact
 * @returns {Object}
 */
function getContactTemperature(contact) {
  const lastContact = contact.lastMessageAt || contact.lastContact;
  const temperature = determineTemperature(lastContact);
  const delay = calculateTemperatureDelay(temperature);
  
  return {
    temperature,
    delayMs: delay,
    lastContact,
    hoursSinceContact: lastContact 
      ? (Date.now() - new Date(lastContact).getTime()) / (1000 * 60 * 60)
      : null,
  };
}

module.exports = {
  determineTemperature,
  calculateTemperatureDelay,
  getContactTemperature,
  TEMPERATURE_THRESHOLDS,
  TEMPERATURE_DELAYS,
};

<?php
/**
 * AI, alarm, audio-transcription and secretary config variable extraction.
 *
 * extractAiConfigVars() reads $selectedInstance and populates all
 * $ai*, $alarm*, $audioTranscription* and $secretary* globals used
 * by the IA, Automacao and General tabs.
 *
 * Expected globals (set by index.php before calling):
 *   $selectedInstance
 */

if (!function_exists('extractAiConfigVars')) {
    function extractAiConfigVars(): void
    {
        global $selectedInstance,
               $legacyOpenAIConfig, $aiConfig,
               $aiEnabled, $aiProvider, $aiModel,
               $aiHistoryLimit, $aiTemperature, $aiMaxTokens, $aiMultiInputDelay,
               $aiSystemPrompt, $aiAssistantPrompt, $aiAssistantId,
               $aiOpenaiMode, $aiOpenaiApiKey,
               $aiGeminiApiKey, $aiGeminiInstruction,
               $aiModelFallback1, $aiModelFallback2,
               $aiOpenRouterApiKey, $aiOpenRouterBaseUrl,
               $aiAutoPauseEnabled, $aiAutoPauseMinutes,
               $alarmConfig,
               $audioTranscriptionConfig, $audioTranscriptionEnabled,
               $audioTranscriptionGeminiApiKey, $audioTranscriptionPrefix,
               $secretaryConfig, $secretaryEnabled, $secretaryIdleHours,
               $secretaryInitialResponse, $secretaryQuickReplies;

        $legacyOpenAIConfig = $selectedInstance['openai'] ?? [];
        $aiConfig = $selectedInstance['ai'] ?? [];
        $aiEnabled = isset($aiConfig['enabled']) ? (bool)$aiConfig['enabled'] : !empty($legacyOpenAIConfig['enabled']);
        $aiProviderRaw = $aiConfig['provider'] ?? 'openai';
        $allowedProviders = ['openai', 'gemini', 'openrouter'];
        $aiProvider = in_array(strtolower($aiProviderRaw), $allowedProviders, true) ? strtolower($aiProviderRaw) : 'openai';
        $aiModel = $aiConfig['model'] ?? $legacyOpenAIConfig['model'] ?? 'gpt-4.1-mini';
        $aiHistoryLimit = max(1, (int)($aiConfig['history_limit'] ?? $legacyOpenAIConfig['history_limit'] ?? 20));
        $aiTemperature = $aiConfig['temperature'] ?? $legacyOpenAIConfig['temperature'] ?? 0.3;
        $aiMaxTokens = max(64, (int)($aiConfig['max_tokens'] ?? $legacyOpenAIConfig['max_tokens'] ?? 600));
        $aiMultiInputDelay = max(0, (int)($aiConfig['multi_input_delay'] ?? DEFAULT_MULTI_INPUT_DELAY));
        $defaultSystemPrompt = 'You are a helpful WhatsApp assistant. Respond naturally and concisely.';
        $aiSystemPrompt = $aiConfig['system_prompt'] ?? $legacyOpenAIConfig['system_prompt'] ?? $defaultSystemPrompt;
        $aiAssistantPrompt = $aiConfig['assistant_prompt'] ?? $legacyOpenAIConfig['assistant_prompt'] ?? '';
        $aiAssistantId = $aiConfig['assistant_id'] ?? $legacyOpenAIConfig['assistant_id'] ?? '';
        $aiOpenaiMode = $aiConfig['openai_mode'] ?? $legacyOpenAIConfig['mode'] ?? 'responses';
        $aiOpenaiApiKey = $aiConfig['openai_api_key'] ?? $legacyOpenAIConfig['api_key'] ?? '';
        $aiGeminiApiKey = $aiConfig['gemini_api_key'] ?? '';
        $aiGeminiInstruction = $aiConfig['gemini_instruction'] ?? DEFAULT_GEMINI_INSTRUCTION;
        $aiModelFallback1 = $aiConfig['model_fallback_1'] ?? '';
        $aiModelFallback2 = $aiConfig['model_fallback_2'] ?? '';
        $aiOpenRouterApiKey = $aiConfig['openrouter_api_key'] ?? '';
        $aiOpenRouterBaseUrl = $aiConfig['openrouter_base_url'] ?? DEFAULT_OPENROUTER_BASE_URL;
        $aiAutoPauseEnabled = $aiConfig['auto_pause_enabled'] ?? false;
        $aiAutoPauseMinutes = $aiConfig['auto_pause_minutes'] ?? 5;
        $alarmConfig = $selectedInstance['alarms'] ?? [];
        $audioTranscriptionConfig = $selectedInstance['audio_transcription'] ?? [];
        $audioTranscriptionEnabled = !empty($audioTranscriptionConfig['enabled']);
        $audioTranscriptionGeminiApiKey = $audioTranscriptionConfig['gemini_api_key'] ?? '';
        $audioTranscriptionPrefix = $audioTranscriptionConfig['prefix'] ?? '🔊';
        $secretaryConfig = $selectedInstance['secretary'] ?? [];
        $secretaryEnabled = !empty($secretaryConfig['enabled']);
        $secretaryIdleHours = max(0, (int)($secretaryConfig['idle_hours'] ?? 0));
        $secretaryInitialResponse = $secretaryConfig['initial_response'] ?? '';
        $secretaryQuickReplies = $secretaryConfig['quick_replies'] ?? [];
    }
}

<?php
class ChatService {
    private $db;
    private $chatSessionModel;
    private $userPromptModel;
    private $aiResponseModel;
    private $sessionModelModel;
    private $aiModelModel;
    private $aiProviderFactory;

    public function __construct($db) {
        $this->db = $db;
        $this->chatSessionModel = new ChatSession($db);
        $this->userPromptModel = new UserPrompt($db);
        $this->aiResponseModel = new AIResponse($db);
        $this->sessionModelModel = new SessionModel($db);
        $this->aiModelModel = new AIModel($db);
        $this->aiProviderFactory = new AIProviderFactory();

        Logger::debug("ChatService initialized");
    }

    /**
     * Create a new chat session with optional initial models
     */
    public function createSession($userId, $title, $modelIds = []) {
        $startTime = microtime(true);

        try {
            Logger::info("Creating chat session", [
                'user_id' => $userId,
                'title' => $title,
                'model_count' => count($modelIds)
            ]);

            // Create session
            $session = $this->chatSessionModel->create([
                'user_id' => $userId,
                'title' => $title
            ]);

            // Add models to session if provided
            if (!empty($modelIds)) {
                foreach ($modelIds as $modelId) {
                    $this->addModelToSession($session['id'], $modelId, $userId);
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Chat session created successfully", [
                'session_id' => $session['id'],
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);

            return $session;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to create chat session", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get session by ID with authorization check
     */
    public function getSession($sessionId, $userId) {
        $startTime = microtime(true);

        try {
            $session = $this->chatSessionModel->getById($sessionId);

            if (!$session) {
                throw new InvalidArgumentException("Session not found");
            }

            // Check ownership
            if ($session['user_id'] !== $userId) {
                throw new InvalidArgumentException("Access denied");
            }

            // Get associated models
            $session['models'] = $this->getSessionModels($sessionId, $userId);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::debug("Session retrieved", [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);

            return $session;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get session", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update session
     */
    public function updateSession($sessionId, $userId, $data) {
        $startTime = microtime(true);

        try {
            // Verify ownership
            $session = $this->getSession($sessionId, $userId);

            $updatedSession = $this->chatSessionModel->update($sessionId, $data);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Session updated", [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'updated_fields' => array_keys($data),
                'duration_ms' => $duration
            ]);

            return $updatedSession;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to update session", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Delete session (hard delete with all related data)
     */
    public function deleteSession($sessionId, $userId) {
        $startTime = microtime(true);

        try {
            // Verify ownership
            $this->getSession($sessionId, $userId);

            // Delete related data in correct order to avoid foreign key violations
            // 1. Delete thinking_traces (depends on prompts and responses)
            $this->db->delete('thinking_traces', ['session_id' => $sessionId]);

            // 2. Delete ai_responses (depends on prompts)
            $this->db->delete('ai_responses', ['session_id' => $sessionId]);

            // 3. Delete user_prompts
            $this->db->delete('user_prompts', ['session_id' => $sessionId]);

            // 4. Delete session_models
            $this->db->delete('session_models', ['session_id' => $sessionId]);

            $pythonUrl = Environment::get('PYTHON_FASTAPI_URL');
            $url = rtrim($pythonUrl, '/') . "/v1/chat/sessions/" . $sessionId;
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
            
            // 5. Delete the session itself
            $success = $this->db->delete('chat_sessions', ['id' => $sessionId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Session and all related data deleted", [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);

            return $success;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to delete session", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Submit prompt and get responses from all enabled models
     */
    public function submitPrompt($sessionId, $userId, $content, $options = []) {
        $startTime = microtime(true);

        try {
            Logger::info("Submitting prompt", [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'content_length' => strlen($content)
            ]);

            // Verify session ownership
            $session = $this->getSession($sessionId, $userId);

            // Get enabled models for session
            $sessionModels = $this->sessionModelModel->getBySessionId($sessionId, true);

            if (empty($sessionModels)) {
                throw new InvalidArgumentException("No models enabled for this session");
            }

            // Create user prompt
            $prompt = $this->userPromptModel->create([
                'session_id' => $sessionId,
                'user_id' => $userId,
                'content' => $content,
                'token_count' => $options['token_count'] ?? 0,
                'metadata' => $options['metadata'] ?? null
            ]);

            $responses = [];

            // Get responses from each enabled model
            foreach ($sessionModels as $sessionModel) {
                try {
                    $response = $this->getModelResponse($prompt, $sessionModel, $options);
                    $responses[] = $response;
                } catch (Exception $e) {
                    Logger::warning("Failed to get response from model", [
                        'model_id' => $sessionModel['model_id'],
                        'error' => $e->getMessage()
                    ]);
                    // Continue with other models
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Prompt submitted successfully", [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'prompt_id' => $prompt['id'],
                'response_count' => count($responses),
                'duration_ms' => $duration
            ]);

            return [
                'prompt' => $prompt,
                'responses' => $responses
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to submit prompt", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Submit prompt with simultaneous multi-model processing
     */
    public function submitPromptMultiModel($sessionId, $userId, $content, $options = []) {
        $startTime = microtime(true);

        try {
            Logger::info("Submitting multi-model prompt", [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'content_length' => strlen($content)
            ]);

            // Verify session ownership
            $session = $this->getSession($sessionId, $userId);

            // Get enabled models for session
            $sessionModels = $this->sessionModelModel->getBySessionId($sessionId, true);

            if (empty($sessionModels)) {
                throw new InvalidArgumentException("No models enabled for this session");
            }

            if (Environment::get('RATE_LIMIT_ENABLED', 'true') === 'true') {
                // Check multi-model rate limits
                $rateLimiter = new RateLimiter($this->db);
                $tier = $options['tier'] ?? 'free'; // In production, get from user subscription
                $rateCheck = $rateLimiter->checkMultiModelLimits($userId, count($sessionModels), $tier);

                if (!$rateCheck['allowed']) {
                    throw new Exception("Multi-model rate limit exceeded: " . ($rateCheck['reason'] ?? 'limit_reached'));
                }
            }

            // Create user prompt
            $prompt = $this->userPromptModel->create([
                'session_id' => $sessionId,
                'user_id' => $userId,
                'content' => $content,
                'token_count' => $options['token_count'] ?? 0,
                'metadata' => array_merge($options['metadata'] ?? [], [
                    'multi_model' => true,
                    'aggregation_strategy' => $options['aggregation_strategy'] ?? 'combine_all'
                ]),
                'file_name' => $options['file_name'] ?? null
            ]);

            // Store vector memory for user prompt
            $this->storeVectorMemory($sessionId, $content, 'user', $prompt['id'], null, [
                'token_count' => $options['token_count'] ?? 0,
                'multi_model' => true,
                'aggregation_strategy' => $options['aggregation_strategy'] ?? 'combine_all',
                'metadata' => $options['metadata'] ?? null
            ]);

            // Get model instances
            $models = [];
            foreach ($sessionModels as $sessionModel) {
                $model = $this->aiModelModel->getById($sessionModel['model_id']);
                if ($model) {
                    $models[] = $model;
                }
            }

            if (empty($models)) {
                throw new InvalidArgumentException("No valid models found for session");
            }

            // Create multi-model provider
            $multiModelConfig = [
                'aggregation_strategy' => $options['aggregation_strategy'] ?? 'combine_all',
                'max_concurrent_requests' => $options['max_concurrent_requests'] ?? 5,
                'request_timeout_ms' => $options['request_timeout_ms'] ?? 30000,
                'circuit_breaker_enabled' => $options['circuit_breaker_enabled'] ?? true,
                'retry_attempts' => $options['retry_attempts'] ?? 2
            ];

            $provider = $this->aiProviderFactory->createMultiModel($models, $multiModelConfig);

            // Build conversation context — filtered to this model's history only
            $messages = $this->buildConversationContext($sessionId, $prompt, $model['id']);

            // Execute multi-model request
            $performanceMonitor = new PerformanceMonitor($this->db);
            $aiResponse = $provider->chatCompletions($messages, array_merge($options, [
                'models' => $models,
                'performance_monitor' => $performanceMonitor,
                'request_id' => 'mm_' . $prompt['id']
            ]));

            if (Environment::get('RATE_LIMIT_ENABLED', 'true') === 'true') {
                // Record rate limit usage
                $rateLimiter = new RateLimiter($this->db);
                $tier = $options['tier'] ?? 'free'; // In production, get from user subscription
                $rateLimiter->recordMultiModelUsage($userId, count($models), $tier);
            }

            // Store aggregated response
            $response = $this->storeMultiModelResponse($prompt, $aiResponse, $models);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Multi-model prompt submitted successfully", [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'prompt_id' => $prompt['id'],
                'response_id' => $response['id'],
                'model_count' => count($models),
                'aggregation_strategy' => $multiModelConfig['aggregation_strategy'],
                'duration_ms' => $duration
            ]);

            return [
                'prompt' => $prompt,
                'response' => $response,
                'metadata' => $aiResponse['metadata'] ?? []
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to submit multi-model prompt", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get response from a specific model
      */
     private function getModelResponse($prompt, $sessionModel, $options = []) {
         // Get model details
         $model = $this->aiModelModel->getById($sessionModel['model_id']);

         // Create AIModel instance from array data
         $aiModel = new AIModel($this->db);
         $aiModel->id = $model['id'];
         $aiModel->model_name = $model['model_name'];
         $aiModel->provider = $model['provider'];
         $aiModel->config = $model['config'];

         // Prepare messages for AI provider
         $messages = $this->buildConversationContext($prompt['session_id'], $prompt);

         // Get AI provider
         $provider = $this->aiProviderFactory->create($aiModel);

        // Call AI provider
        $aiResponse = $provider->chatCompletions($messages, $options);

        // Extract response data
        $responseContent = $aiResponse['choices'][0]['message']['content'] ?? '';
        $tokenCount = $aiResponse['usage']['completion_tokens'] ?? 0;
        $cost = $this->calculateCost($model, $aiResponse['usage'] ?? []);

        // Create AI response record
        $response = $this->aiResponseModel->create([
            'prompt_id' => $prompt['id'],
            'model_id' => $model['id'],
            'session_id' => $prompt['session_id'],
            'content' => $responseContent,
            'token_count' => $tokenCount,
            'cost' => $cost,
            'metadata' => [
                'ai_response' => $aiResponse,
                'model_config' => $model['configuration'] ?? null,
                'session_model_config' => $sessionModel['config'] ?? null
            ]
        ]);

        // Store vector memory for AI response
        $this->storeVectorMemory($prompt['session_id'], $responseContent, 'assistant', $prompt['id'], $response['id'], [
            'model_id' => $model['id'],
            'model_name' => $model['name'],
            'token_count' => $tokenCount,
            'cost' => $cost
        ]);

        // Extract and store thinking traces from the response content
        $this->extractAndStoreThinkingTraces($responseContent, $response['id'], $prompt['id'], $prompt['session_id'], $prompt['user_id']);

        return $response;
    }

    /**
     * Store aggregated multi-model response
     */
    private function storeMultiModelResponse($prompt, $aiResponse, $models) {
        // Extract response data
        $responseContent = $aiResponse['choices'][0]['message']['content'] ?? '';
        $tokenCount = $aiResponse['usage']['total_tokens'] ?? 0;

        // Calculate total cost across all models
        $totalCost = 0;
        $modelMetadata = [];

        if (isset($aiResponse['metadata']['model_responses'])) {
            foreach ($aiResponse['metadata']['model_responses'] as $modelResponse) {
                $modelId = $modelResponse['model_id'] ?? null;
                if ($modelId) {
                    // Try by model name first, then by ID
                    $model = $this->aiModelModel->getByModelName($modelId);
                    if (!$model) {
                        $model = $this->aiModelModel->getById($modelId);
                    }
                    if ($model) {
                        $cost = $this->calculateCost($model, [
                            'prompt_tokens' => $modelResponse['prompt_tokens'] ?? 0,
                            'completion_tokens' => $modelResponse['completion_tokens'] ?? 0
                        ]);
                        $totalCost += $cost;
                        $modelMetadata[] = [
                            'model_id' => $model['id'], // Use actual UUID
                            'model_name' => $model['model_name'],
                            'tokens' => $modelResponse['tokens'] ?? 0,
                            'cost' => $cost,
                            'duration_ms' => $modelResponse['duration_ms'] ?? 0
                        ];
                    }
                }
            }
        }

        // Create AI response record for aggregated response
        $response = $this->aiResponseModel->create([
            'prompt_id' => $prompt['id'],
            'model_id' => null, // No single model for aggregated response
            'session_id' => $prompt['session_id'],
            'content' => $responseContent,
            'token_count' => $tokenCount,
            'cost' => $totalCost,
            'metadata' => [
                'ai_response' => $aiResponse,
                'multi_model' => true,
                'aggregation_strategy' => $aiResponse['metadata']['aggregation_strategy'] ?? 'combine_all',
                'successful_models' => $aiResponse['metadata']['successful_models'] ?? 0,
                'failed_models' => $aiResponse['metadata']['failed_models'] ?? 0,
                'total_latency_ms' => $aiResponse['metadata']['total_latency_ms'] ?? 0,
                'model_details' => $modelMetadata
            ]
        ]);

        // Store vector memory for multi-model AI response
        $this->storeVectorMemory($prompt['session_id'], $responseContent, 'assistant', $prompt['id'], $response['id'], [
            'multi_model' => true,
            'aggregation_strategy' => $aiResponse['metadata']['aggregation_strategy'] ?? 'combine_all',
            'successful_models' => $aiResponse['metadata']['successful_models'] ?? 0,
            'failed_models' => $aiResponse['metadata']['failed_models'] ?? 0,
            'total_cost' => $totalCost,
            'model_details' => $modelMetadata
        ]);

        // Extract and store thinking traces from the response content
        $this->extractAndStoreThinkingTraces($responseContent, $response['id'], $prompt['id'], $prompt['session_id'], $prompt['user_id']);

        return $response;
    }

    /**
     * Build conversation context for AI provider
     */
    private function buildConversationContext($sessionId, $currentPrompt, $modelId = null) {
        $thread = $this->aiResponseModel->getConversationThread($sessionId, 20, $modelId);
        $messages = [];

        foreach ($thread as $item) {
            // Skip the current prompt — it's added explicitly at the end
            if ($item['type'] === 'prompt' && $item['id'] === $currentPrompt['id']) {
                continue;
            }

            $role = ($item['type'] === 'prompt') ? 'user' : 'assistant';
            $content = $item['content'];

            if ($role === 'assistant') {
                $content = preg_replace('/<think>.*?<\/think>/s', '', $content);
                $content = trim($content);

                if (empty($content)) continue;
            }

            $messages[] = [
                'role' => $role,
                'content' => $content
            ];
        }

        // Always append current prompt cleanly at the end
        $messages[] = [
            'role' => 'user',
            'content' => trim($currentPrompt['content'])
        ];

        return $messages;
    }

    /**
     * Calculate cost based on model and usage
     */
    private function calculateCost($model, $usage) {
        // Simple cost calculation - in real implementation, this would be more sophisticated
        $inputTokens = $usage['prompt_tokens'] ?? 0;
        $outputTokens = $usage['completion_tokens'] ?? 0;

        // Default pricing (per 1K tokens)
        $inputPrice = 0.0015;  // $0.0015 per 1K input tokens
        $outputPrice = 0.002;  // $0.002 per 1K output tokens

        $cost = (($inputTokens / 1000) * $inputPrice) + (($outputTokens / 1000) * $outputPrice);

        return round($cost, 4);
    }

    /**
     * Chat with a specific model
     */
    public function chatWithModel($sessionId, $userId, $modelId, $content, $options = []) {
        $startTime = microtime(true);

        try {
            Logger::info("Chatting with specific model", [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'model_id' => $modelId,
                'content_length' => strlen($content)
            ]);

            // Verify session ownership
            $session = $this->getSession($sessionId, $userId);

            // Get model details by ID
            $model = $this->aiModelModel->getById($modelId);

            if (!$model || !$model['is_active']) {
                throw new InvalidArgumentException("Model not found or inactive");
            }

            // Verify model is associated with session
            $sessionModels = $this->sessionModelModel->getBySessionId($sessionId, true); // Only visible models

            $sessionModel = null;
            foreach ($sessionModels as $sm) {
                if ($sm['model_id'] === $model['id']) { // Compare with actual UUID
                    $sessionModel = $sm;
                    break;
                }
            }
            if (!$sessionModel) {
                throw new InvalidArgumentException("Model not found in session or not visible");
            }

            if (!$model || !$model['is_active']) {
                throw new InvalidArgumentException("Model not found or inactive");
            }

            // Create AIModel instance from array data
            $aiModel = new AIModel($this->db);
            $aiModel->id = $model['id'];
            $aiModel->model_name = $model['model_name'];
            $aiModel->provider = $model['provider'];
            $aiModel->config = $model['config'];

            if (Environment::get('RATE_LIMIT_ENABLED', 'true') === 'true') {
                // Check rate limits
                $rateLimiter = new RateLimiter($this->db);
                $tier = $options['tier'] ?? 'free'; // In production, get from user subscription
                $rateCheck = $rateLimiter->checkLimit($userId, 'chat_completions_per_minute', $tier);

                if (!$rateCheck['allowed']) {
                    throw new Exception("Rate limit exceeded: " . ($rateCheck['reason'] ?? 'too_many_requests'));
                }
            }

            // Create user prompt
            $prompt = $this->userPromptModel->create([
                'session_id' => $sessionId,
                'user_id' => $userId,
                'content' => $content,
                'token_count' => $options['token_count'] ?? 0,
                'metadata' => $options['metadata'] ?? null,
                'file_name' => $options['file_name'] ?? null
            ]);

            // Store vector memory for user prompt
            $this->storeVectorMemory($sessionId, $content, 'user', $prompt['id'], null, [
                'token_count' => $options['token_count'] ?? 0,
                'model_id' => $modelId,
                'metadata' => $options['metadata'] ?? null
            ]);

            // Store vector memory for user prompt
            $this->storeVectorMemory($sessionId, $content, 'user', $prompt['id'], null, [
                'token_count' => $options['token_count'] ?? 0,
                'metadata' => $options['metadata'] ?? null
            ]);

            // Build conversation context — filtered to this model's history only
            $messages = $this->buildConversationContext($sessionId, $prompt, $model['id']);

            // Get AI provider
            $provider = $this->aiProviderFactory->create($aiModel);

            // Execute chat completion
            $performanceMonitor = new PerformanceMonitor($this->db);
            $aiResponse = $provider->chatCompletions($messages, array_merge($options, [
                'performance_monitor' => $performanceMonitor,
                'request_id' => 'single_' . $prompt['id']
            ]));

            // Extract response data
            $responseContent = $aiResponse['choices'][0]['message']['content'] ?? '';
            $tokenCount = $aiResponse['usage']['completion_tokens'] ?? 0;
            $cost = $this->calculateCost($model, $aiResponse['usage'] ?? []);

            // Create AI response record
            $response = $this->aiResponseModel->create([
                'prompt_id' => $prompt['id'],
                'model_id' => $model['id'],
                'session_id' => $sessionId,
                'content' => $responseContent,
                'token_count' => $tokenCount,
                'cost' => $cost,
                'metadata' => [
                    'ai_response' => $aiResponse,
                    'model_config' => $model['config'] ?? null,
                    'session_model_config' => $sessionModel['configuration'] ?? null
                ]
            ]);

            // Store vector memory for AI response
            $this->storeVectorMemory($sessionId, $responseContent, 'assistant', $prompt['id'], $response['id'], [
                'model_id' => $model['id'],
                'model_name' => $model['model_name'],
                'token_count' => $tokenCount,
                'cost' => $cost
            ]);

            // Extract and store thinking traces from the response content
            $this->extractAndStoreThinkingTraces($responseContent, $response['id'], $prompt['id'], $sessionId, $userId);

            if (Environment::get('RATE_LIMIT_ENABLED', 'true') === 'true') {
                // Record rate limit usage
                $rateLimiter = new RateLimiter($this->db);
                $rateLimiter->recordUsage($userId, 'chat_completions_per_minute');
            }

            // Update session last message time
            $this->chatSessionModel->update($sessionId, [
                'last_message_at' => date('Y-m-d H:i:s')
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Single model chat completed successfully", [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'model_id' => $modelId,
                'prompt_id' => $prompt['id'],
                'response_id' => $response['id'],
                'duration_ms' => $duration
            ]);

            return [
                'prompt' => $prompt,
                'response' => $response,
                'metadata' => $aiResponse['metadata'] ?? []
            ];

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to chat with model", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId,
                'model_id' => $modelId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Chat with all visible models for a session in one parallel Python call.
     */
    public function chatWithModelsBatch($sessionId, $userId, $content, $visibleModelIds, $options = []) {
        $startTime = microtime(true);

        // Verify session ownership once
        $session = $this->getSession($sessionId, $userId);

        // Build targets array with per-model context (THE CORE FIX)
        $targets      = [];
        $modelDetails = []; // keyed by model UUID

        foreach ($visibleModelIds as $modelId) {
            $model = $this->aiModelModel->getById($modelId);
            if (!$model || !$model['is_active']) continue;
            $modelDetails[$model['id']] = $model;
        }

        if (empty($modelDetails)) {
            throw new InvalidArgumentException("No active visible models found");
        }

        // Create ONE shared user prompt (all models reference the same prompt)
        $prompt = $this->userPromptModel->create([
            'session_id'  => $sessionId,
            'user_id'     => $userId,
            'content'     => $content,
            'token_count' => $options['token_count'] ?? 0,
            'metadata'    => $options['metadata'] ?? null,
            'file_name'   => $options['file_name'] ?? null,
        ]);

        // Store vector memory for the user prompt
        $this->storeVectorMemory($sessionId, $content, 'user', $prompt['id'], null, [
            'token_count' => $options['token_count'] ?? 0,
            'metadata'    => $options['metadata'] ?? null,
        ]);

        foreach ($modelDetails as $modelId => $model) {
            $perModelMessages = $this->buildConversationContext($sessionId, $prompt, $modelId);

            $targets[] = [
                'model_id' => $modelId,
                'model'    => $model['model_name'],
                'provider' => $model['provider'],
                'messages' => $perModelMessages,
            ];
        }

        // Send batch request to Python (each target carries its own messages)
        $provider     = new FastAPIProvider(null);
        $batchResults = $provider->chatCompletionsBatch(null, $targets, [
            'session_id' => $sessionId,
            'user_id'    => $userId,
        ]);

        // Store each model's response
        $responses = [];
        foreach ($batchResults as $modelId => $aiResponse) {
            $model = $modelDetails[$modelId] ?? null;
            if (!$model) continue;

            $responseContent = $aiResponse['choices'][0]['message']['content'] ?? '';
            $outputTokens    = $aiResponse['usage']['completion_tokens'] ?? 0;
            $cost            = $this->calculateCost($model, $aiResponse['usage'] ?? []);

            $response = $this->aiResponseModel->create([
                'prompt_id'   => $prompt['id'],
                'model_id'    => $modelId,
                'session_id'  => $sessionId,
                'content'     => $responseContent,
                'token_count' => $outputTokens,
                'cost'        => $cost,
                'metadata'    => ['ai_response' => $aiResponse],
            ]);

            $this->storeVectorMemory($sessionId, $responseContent, 'assistant', $prompt['id'], $response['id'], [
                'model_id'    => $modelId,
                'token_count' => $outputTokens,
                'cost'        => $cost,
            ]);

            $this->extractAndStoreThinkingTraces($responseContent, $response['id'], $prompt['id'], $sessionId, $userId);

            $responses[$modelId] = $response;
        }

        // Update session last message time
        $this->chatSessionModel->update($sessionId, ['last_message_at' => date('Y-m-d H:i:s')]);

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        Logger::info("Batch chat completed", [
            'session_id'  => $sessionId,
            'model_count' => count($targets),
            'duration_ms' => $duration,
        ]);

        return [
            'prompt'    => $prompt,
            'responses' => $responses,
        ];
    }
    
    /**
     * Get conversation thread
     */
    public function getConversationThread($sessionId, $userId, $limit = 50) {
        $startTime = microtime(true);

        try {
            // Verify ownership
            $this->getSession($sessionId, $userId);

            $thread = $this->aiResponseModel->getConversationThread($sessionId, $limit);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::debug("Conversation thread retrieved", [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'limit' => $limit,
                'message_count' => count($thread),
                'duration_ms' => $duration
            ]);

            return $thread;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get conversation thread", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Add model to session
     */
    public function addModelToSession($sessionId, $modelId, $userId) {
        $startTime = microtime(true);

        try {
            // Note: Ownership verification should be done by the caller

            // Get model by ID
            $model = $this->aiModelModel->getById($modelId);

            if (!$model) {
                throw new InvalidArgumentException("Model not found");
            }

            $association = $this->sessionModelModel->create([
                'session_id' => $sessionId,
                'model_id' => $model['id'] // Use the actual UUID
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Model added to session", [
                'session_id' => $sessionId,
                'model_id' => $model['id'],
                'model_name' => $model['model_name'],
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);

            return $association;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to add model to session", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'model_id' => $modelId,
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Remove model from session
     */
    public function removeModelFromSession($sessionId, $modelId, $userId) {
        $startTime = microtime(true);

        try {
            // Verify session ownership
            $this->getSession($sessionId, $userId);

            // Get model by ID
            $model = $this->aiModelModel->getById($modelId);

            if (!$model) {
                throw new InvalidArgumentException("Model not found");
            }

            // Find the association
            $associations = $this->sessionModelModel->getBySessionId($sessionId);
            $association = null;
            foreach ($associations as $assoc) {
                if ($assoc['model_id'] === $model['id']) { // Compare with actual UUID
                    $association = $assoc;
                    break;
                }
            }

            if (!$association) {
                throw new InvalidArgumentException("Model not associated with session");
            }

            $success = $this->sessionModelModel->remove($association['id']);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Model removed from session", [
                'session_id' => $sessionId,
                'model_id' => $modelId,
                'model_uuid' => $model['id'],
                'association_id' => $association['id'],
                'user_id' => $userId,
                'success' => $success,
                'duration_ms' => $duration
            ]);

            return $success;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to remove model from session", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'model_id' => $modelId,
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get models for session
     */
    public function getSessionModels($sessionId, $userId) {
        $startTime = microtime(true);

        try {
            $associations = $this->sessionModelModel->getBySessionId($sessionId);

            // Enrich with model details
            $models = [];
            foreach ($associations as $association) {
                $model = $this->aiModelModel->getById($association['model_id']);
                if ($model) {
                    $modelNameFull = $model['display_name'] ?? $model['model_name'];
                    Logger::debug("Model data for session", [
                        'session_id' => $sessionId,
                        'model_id' => $association['model_id'],
                        'model_name' => $model['model_name'],
                        'display_name' => $model['display_name'] ?? 'NULL',
                        'model_name_full' => $modelNameFull
                    ]);
                    $models[] = array_merge($association, [
                        'model_name' => $model['model_name'],
                        'provider' => $model['provider'],
                        'model_name_full' => $modelNameFull,
                        'capabilities' => $model['capabilities']
                    ]);
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::debug("Session models retrieved", [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'model_count' => count($models),
                'duration_ms' => $duration
            ]);

            return $models;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get session models", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Extract and store thinking traces from AI response content
     */
    private function extractAndStoreThinkingTraces($responseContent, $responseId, $promptId, $sessionId, $userId) {
        try {
            Logger::debug("Attempting to extract thinking traces", [
                'response_id' => $responseId,
                'content_length' => strlen($responseContent),
                'content_preview' => substr($responseContent, 0, 100)
            ]);

            // Pattern to match thinking traces (common formats: <think>...</think>, [THINKING]...</[THINKING])
            $patterns = [
                '/<think>(.*?)<\/think>/is',  // XML-style tags
                '/\[THINKING\](.*?)\[\/THINKING\]/is',  // Bracket-style tags
                '/<thinking>(.*?)<\/thinking>/is',  // Alternative XML tags
            ];

            $traces = [];
            $sequenceOrder = 0;

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $responseContent, $matches, PREG_SET_ORDER)) {
                    Logger::debug("Found matches for pattern", [
                        'pattern' => $pattern,
                        'match_count' => count($matches)
                    ]);
                    foreach ($matches as $match) {
                        $traceContent = trim($match[1]);
                        if (!empty($traceContent)) {
                            $traces[] = [
                                'user_id' => $userId,
                                'session_id' => $sessionId,
                                'prompt_id' => $promptId,
                                'response_id' => $responseId,
                                'trace_type' => 'reasoning',
                                'content' => $traceContent,
                                'metadata' => json_encode([
                                    'pattern_used' => $pattern,
                                    'original_match' => $match[0]
                                ]),
                                'sequence_order' => $sequenceOrder++
                            ];
                        }
                    }
                }
            }

            Logger::debug("Extracted traces", [
                'response_id' => $responseId,
                'trace_count' => count($traces)
            ]);

            // Store traces in database
            foreach ($traces as $trace) {
                $result = $this->db->create('thinking_traces', $trace);
                Logger::debug("Stored thinking trace", [
                    'response_id' => $responseId,
                    'trace_id' => $result,
                    'success' => $result !== false
                ]);
            }

            if (!empty($traces)) {
                Logger::info("Thinking traces extracted and stored", [
                    'response_id' => $responseId,
                    'trace_count' => count($traces)
                ]);
            } else {
                Logger::debug("No thinking traces found in response", [
                    'response_id' => $responseId
                ]);
            }

        } catch (Exception $e) {
            Logger::error("Failed to extract thinking traces", [
                'response_id' => $responseId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Don't throw exception to avoid breaking the main flow
        }
    }

    /**
     * Store vector memory for chat interactions
     */
    private function storeVectorMemory($sessionId, $content, $role, $promptId = null, $responseId = null, $metadata = []) {
        try {
            // Generate embedding if possible
            $embedding = null;
            try {
                // Try to find an embedding-capable model
                $embeddingModel = null;

                // First, try to get a specific embedding model
                $embeddingModel = $this->aiModelModel->getByProviderModel('openai', 'text-embedding-ada-002');

                // If not found, try to find any OpenAI model with embedding capability
                if (!$embeddingModel) {
                    $openaiModels = $this->aiModelModel->getByProvider('openai', 1, 100);
                    foreach ($openaiModels['models'] as $model) {
                        if ($model['is_active'] && isset($model['capabilities']) &&
                            is_array($model['capabilities']) && in_array('embedding', $model['capabilities'])) {
                            $embeddingModel = $model;
                            break;
                        }
                    }
                }

                // If still not found, try remote provider models
                if (!$embeddingModel) {
                    $remoteModels = $this->aiModelModel->getByProvider('remote', 1, 100);
                    foreach ($remoteModels['models'] as $model) {
                        if ($model['is_active'] && isset($model['capabilities']) &&
                            is_array($model['capabilities']) && in_array('embedding', $model['capabilities'])) {
                            $embeddingModel = $model;
                            break;
                        }
                    }
                }

                if ($embeddingModel) {
                    $aiModel = new AIModel($this->db);
                    $aiModel->id = $embeddingModel['id'];
                    $aiModel->model_name = $embeddingModel['model_name'];
                    $aiModel->provider = $embeddingModel['provider'];
                    $aiModel->config = $embeddingModel['config'];

                    $provider = $this->aiProviderFactory->create($aiModel);
                    $embeddingResponse = $provider->createEmbeddings($content, ['model' => $embeddingModel['model_name']]);
                    $embedding = json_encode($embeddingResponse['data'][0]['embedding'] ?? null);
                }
            } catch (Exception $e) {
                Logger::debug("Embedding generation not available or failed", [
                    'error' => $e->getMessage(),
                    'session_id' => $sessionId,
                    'role' => $role
                ]);
                // Continue without embedding
            }

            // Store vector memory
            $vectorMemoryData = [
                'session_id' => $sessionId,
                'content' => $content,
                'role' => $role,
                'embedding' => $embedding,
                'metadata' => !empty($metadata) ? json_encode($metadata) : null
            ];

            if ($promptId) {
                $vectorMemoryData['prompt_id'] = $promptId;
            }

            if ($responseId) {
                $vectorMemoryData['response_id'] = $responseId;
            }

            $this->db->create('vector_memories', $vectorMemoryData);

            Logger::debug("Vector memory stored", [
                'session_id' => $sessionId,
                'role' => $role,
                'has_embedding' => $embedding !== null
            ]);

        } catch (Exception $e) {
            Logger::warning("Failed to store vector memory", [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'role' => $role
            ]);
            // Don't throw exception to avoid breaking the main flow
        }
    }
}
?>
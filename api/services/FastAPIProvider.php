<?php

class FastAPIProvider implements AIProvider {
    private $baseUrl;
    private $modelName;
    private $provider;

    public function __construct($model = null) {
        $this->baseUrl = Environment::get('PYTHON_FASTAPI_URL');
        
        if (empty($this->baseUrl)) {
            throw new Exception("PYTHON_FASTAPI_URL environment variable is not set");
        }
        
        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL)) {
            throw new Exception("PYTHON_FASTAPI_URL is not a valid URL: " . $this->baseUrl);
        }
        
        if ($model) {
            $this->modelName = is_array($model) ? $model['model_name'] : $model->model_name;
            $this->provider = is_array($model) ? $model['provider'] : $model->provider;
        }
    }

    public function chatCompletions($messages, $options = []) {
        set_time_limit(600);
        $normalizedMessages = $this->normalizeMessages($messages);
        
        $payload = [
            'session_id' => $options['session_id'] ?? 'none',
            'user_id' => $options['user_id'] ?? 'none',
            'messages' => $normalizedMessages,
            'model' => $this->modelName ?? $options['model_name'] ?? 'default',
            'provider' => $this->provider ?? $options['provider'] ?? 'ollama',
            'context_data' => !empty($options['context_data']) ? $options['context_data'] : new \stdClass(),
            'options' => !empty($options['llm_options']) ? $options['llm_options'] : new \stdClass()
        ];

        $hasFile = isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;
        $url = rtrim($this->baseUrl, '/') . '/v1/chat';

        if ($hasFile) {
            $url .= '/completions-with-file';
            $postFields = [
                'payload' => json_encode($payload),
                'file' => new CURLFile($_FILES['file']['tmp_name'], $_FILES['file']['type'], $_FILES['file']['name'])
            ];
            $headers = ['Content-Type: multipart/form-data'];
        } else {
            $url .= '/completions';
            $postFields = json_encode($payload);
            $headers = ['Content-Type: application/json'];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new Exception("Connection to Python AI Engine failed: " . $error);
        }

        Logger::debug("FastAPI response", [
            'http_code' => $httpCode,
            'response' => substr($response, 0, 500)
        ]);

        $data = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMsg = is_array($data) && isset($data['detail']) 
                ? json_encode($data['detail']) 
                : $response;
            throw new Exception("Python AI Engine Error ($httpCode): " . $errorMsg);
        }

        return [
            'choices' => [
                [
                    'message' => [
                        'content' => $data['content'] ?? '',
                        'role' => 'assistant'
                    ]
                ]
            ],
            'usage' => $data['usage'] ?? [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0
            ],
            'metadata' => $data['metadata'] ?? []
        ];
    }

    /**
     * Send all models in a single request to Python; Python runs them in parallel.
     * $targets = [['model_id' => UUID, 'model' => modelName, 'provider' => provider], ...]
     * Returns array keyed by model_id (UUID).
     */
    public function chatCompletionsBatch($messages, $targets, $options = []) {
        set_time_limit(600);
 
        if (empty($targets)) {
            throw new \Exception("No targets provided for batch completion");
        }
 
        // Build the targets payload — each target carries its own normalised messages
        $targetsPayload = array_map(function ($t) {
            return [
                'model'    => $t['model'],
                'provider' => $t['provider'],
                'model_id' => $t['model_id'],
                // Per-model conversation history (may be empty for a brand-new model)
                'messages' => array_values($this->normalizeMessages($t['messages'] ?? [])),
            ];
        }, $targets);
 
        // Use the first target's model/provider as the required top-level schema fields
        $firstTarget = $targets[0];
 
        $payload = [
            'session_id'   => $options['session_id'] ?? 'none',
            'user_id'      => $options['user_id'] ?? 'none',
            // Top-level messages: send the first model's messages so the Python
            // schema validator is happy; Python will use per-target messages instead.
            'messages'     => array_values($this->normalizeMessages($firstTarget['messages'] ?? [])),
            'model'        => $firstTarget['model'] ?? 'default',
            'provider'     => $firstTarget['provider'] ?? 'ollama',
            'context_data' => !empty($options['context_data']) ? $options['context_data'] : new \stdClass(),
            'options'      => !empty($options['llm_options']) ? $options['llm_options'] : new \stdClass(),
            'targets'      => $targetsPayload,
        ];
 
        $url = rtrim($this->baseUrl, '/') . '/v1/chat/completions/batch';
 
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
 
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
 
        if ($error) {
            throw new \Exception("Batch request to Python AI Engine failed: " . $error);
        }
 
        $data = json_decode($response, true);
 
        if ($httpCode !== 200) {
            $errorMsg = is_array($data) && isset($data['detail'])
                ? json_encode($data['detail'])
                : $response;
            throw new \Exception("Python AI Engine Batch Error ($httpCode): " . $errorMsg);
        }
 
        // $data['results'] is keyed by model_id (PHP UUID).
        // Normalise each result into the same format as chatCompletions().
        $results = [];
        foreach ($data['results'] as $modelId => $result) {
            $results[$modelId] = [
                'choices'  => [[
                    'message' => [
                        'content' => $result['content'] ?? '',
                        'role'    => 'assistant',
                    ],
                ]],
                'usage'    => $result['usage']    ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
                'metadata' => $result['metadata'] ?? [],
            ];
        }
        return $results;
    }

    /**
     * Normalize messages to ensure they're in the correct format for Python API
     * MUST return a real array (not an object) for JSON encoding
     */
    private function normalizeMessages($messages) {
        $normalized = [];
        
        foreach ($messages as $message) {
            if (is_array($message)) {
                $normalized[] = [
                    'role' => $message['role'] ?? 'user',
                    'content' => $message['content'] ?? ''
                ];
            } elseif (is_object($message)) {
                $normalized[] = [
                    'role' => $message->role ?? 'user',
                    'content' => $message->content ?? ''
                ];
            } elseif (is_string($message)) {
                $normalized[] = [
                    'role' => 'user',
                    'content' => $message
                ];
            }
        }
        
        // Ensure it's a real indexed array (not associative)
        return array_values($normalized);
    }

    public function streamChatCompletions($messages, $options = []) {
        throw new Exception("Streaming not yet implemented");
    }
    
    public function createEmbeddings($input, $options = []) {
        throw new Exception("Embeddings not yet implemented");
    }
}
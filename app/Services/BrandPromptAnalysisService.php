<?php

namespace App\Services;

use App\Models\AiModel;
use App\Models\AiModelUsage;
use App\Models\Brand;
use App\Models\BrandPrompt;
use App\Models\BrandPromptResource;
use Illuminate\Support\Facades\Log;

class BrandPromptAnalysisService
{
    protected AIPromptService $aiPromptService;

    protected AiModelDistributionService $distributionService;

    public function __construct(
        AIPromptService $aiPromptService,
        AiModelDistributionService $distributionService
    ) {
        $this->aiPromptService = $aiPromptService;
        $this->distributionService = $distributionService;
    }

    /**
     * Analyze a brand prompt and generate AI response with competitor analysis
     */
    public function analyzePrompt(BrandPrompt $brandPrompt, Brand $brand, ?string $preferredModelName = null, ?string $sessionId = null): array
    {
        $competitors = $brand->competitors()->pluck('name')->toArray();
        $subreddits = $brand->subreddits()->pluck('subreddit_name')->toArray();

        Log::info('Analyzing brand prompt', [
            'brand_prompt_id' => $brandPrompt->id,
            'brand_name' => $brand->name,
            'competitors_count' => count($competitors),
            'subreddits_count' => count($subreddits),
            'preferred_model' => $preferredModelName,
            'session_id' => $sessionId,
        ]);

        // Generate the enhanced prompt
        $enhancedPrompt = $this->buildAnalysisPrompt(
            $brand->name,
            $competitors,
            $brandPrompt->prompt,
            $subreddits
        );

        // Get AI response with preferred model and session-based distribution
        $aiResponseData = $this->generateAIResponse($enhancedPrompt, $preferredModelName, $sessionId);

        // Parse the response to extract resources and analysis
        $parsedResponse = $this->parseAIResponse($aiResponseData['text'], $brand, $competitors, $brandPrompt);

        $result = [
            'ai_response' => $parsedResponse['html_response'],
            'resources' => $parsedResponse['resources'],
            'analysis' => $parsedResponse['analysis'],
            'ai_model_id' => $aiResponseData['ai_model_id'] ?? null,
        ];

        Log::info('Analysis result prepared', [
            'brand_prompt_id' => $brandPrompt->id,
            'ai_model_id' => $result['ai_model_id'],
            'has_ai_model_id' => isset($result['ai_model_id']),
        ]);

        return $result;
    }

    /**
     * Build the analysis prompt using the template provided
     */
    protected function buildAnalysisPrompt(string $brandName, array $competitors, string $phrase, array $subreddits = []): string
    {
        $competitorsString = implode(', ', $competitors);
        $subredditsString = ! empty($subreddits) ? implode(', ', array_map(fn ($s) => "r/{$s}", $subreddits)) : '';

        $additionalContext = '';
        if (! empty($subreddits)) {
            $additionalContext .= "\n\nIMPORTANT: When providing resources, you MUST include relevant Reddit posts/discussions from these target subreddits: {$subredditsString}. Include URLs to Reddit threads that discuss topics related to [{$phrase}] in these communities.";
        }

        return "Given a brand [{$brandName}], a comma-separated list of competitors [{$competitorsString}], and a single phrase [{$phrase}], generate two outputs:

            1. An AI-generated response to the [{$phrase}] in HTML format, simulating a natural, informative answer that mentions [{$brandName}] and [{$competitorsString}] as relevant players in the context of the phrase.

            2. A detailed analysis with resources categorized by type.
            {$additionalContext}

            Please structure your response as follows:

            HTML_RESPONSE_START
            [Your HTML formatted response here]
            HTML_RESPONSE_END

            ANALYSIS_START
            Resources: [Provide a detailed list of resources with the following format for each:
            - URL: [full URL]
            - Type: [competitor_website|industry_report|news_article|documentation|blog_post|research_paper|social_media|reddit|youtube|marketplace|review_site|other]
            - Title: [resource title]
            - Description: [brief description of what this resource contains]
            
            IMPORTANT: Include Reddit URLs (from reddit.com) and YouTube URLs (from youtube.com) when relevant to the topic. For Reddit, prioritize the target subreddits mentioned above if provided.
            ]
            
            Brand_Sentiment: [Positive/Neutral/Negative with score 1-10]
            Brand_Position: [Percentage representing brand's prominence in response]
            Brand_Visibility: [How prominently the brand is featured - score 1-10]
            Competitor_Mentions: [JSON object with competitor names and their mention counts/context]
            ANALYSIS_END";
    }

    /**
     * Generate AI response using the configured AI model
     */
    protected function generateAIResponse(string $prompt, ?string $preferredModelName = null, ?string $sessionId = null): array
    {
        try {
            $aiModel = null;

            Log::info('Generate AI Response called', [
                'preferred_model_name' => $preferredModelName,
                'session_id' => $sessionId ? substr($sessionId, 0, 8) : null,
                'has_distribution_service' => isset($this->distributionService),
            ]);

            // If a preferred model is specified, try to use it first
            if ($preferredModelName) {
                // Try case-insensitive matching by name
                $aiModel = AiModel::where('is_enabled', true)
                    ->get()
                    ->first(function ($model) use ($preferredModelName) {
                        return strcasecmp($model->name, $preferredModelName) === 0;
                    });

                if ($aiModel) {
                    Log::info('Using preferred AI model', [
                        'requested' => $preferredModelName,
                        'model_name' => $aiModel->name,
                        'display_name' => $aiModel->display_name,
                        'model_id' => $aiModel->id,
                    ]);
                } else {
                    $enabledModels = AiModel::where('is_enabled', true)->pluck('name')->toArray();
                    Log::warning('Preferred AI model not found or disabled', [
                        'requested_model' => $preferredModelName,
                        'enabled_models' => $enabledModels,
                    ]);
                }
            }

            // If no preferred model or it wasn't found, use weighted distribution service
            if (! $aiModel) {
                Log::info('Attempting to get model from distribution service', [
                    'session_id' => $sessionId ? substr($sessionId, 0, 8) : 'none',
                ]);

                $aiModel = $this->distributionService->getNextModel(
                    AiModelDistributionService::STRATEGY_WEIGHTED,
                    $sessionId
                );

                if ($aiModel) {
                    Log::info('Using weighted distribution AI model', [
                        'model_name' => $aiModel->name,
                        'display_name' => $aiModel->display_name,
                        'model_id' => $aiModel->id,
                        'order' => $aiModel->order,
                        'session_id' => substr($sessionId ?? 'none', 0, 8),
                    ]);
                } else {
                    Log::error('Distribution service returned null model', [
                        'session_id' => $sessionId ? substr($sessionId, 0, 8) : 'none',
                    ]);
                }
            }

            if (! $aiModel) {
                throw new \Exception('No enabled AI model found');
            }

            Log::info('Using AI model for analysis', [
                'model_name' => $aiModel->name,
                'display_name' => $aiModel->display_name,
                'ai_model_id' => $aiModel->id,
            ]);

            $response = $this->callAiProvider($aiModel, $prompt);

            // Track AI model usage
            if ($sessionId) {
                AiModelUsage::incrementUsage(
                    $aiModel->id,
                    $sessionId,
                    'brand_prompt_analysis',
                    null
                );

                Log::info('AI model usage tracked', [
                    'ai_model_id' => $aiModel->id,
                    'session_id' => substr($sessionId, 0, 8),
                ]);
            }

            return [
                'text' => $response->text,
                'ai_model_id' => $aiModel->id,
            ];

        } catch (\Exception $e) {
            Log::error('AI Response Generation Failed', [
                'error' => $e->getMessage(),
                'prompt' => substr($prompt, 0, 200).'...',
            ]);
            throw $e;
        }
    }

    /**
     * Call the AI provider using direct HTTP requests (bypassing Prism environment config)
     */
    protected function callAiProvider(AiModel $aiModel, string $prompt)
    {
        $apiConfig = $aiModel->api_config ?? [];

        // Validate provider
        if (! $this->validateProvider($aiModel->name)) {
            Log::warning('Potentially unsupported AI provider', [
                'provider' => $aiModel->name,
            ]);
        }

        $model = $apiConfig['model'] ?? $this->getDefaultModel($aiModel->name);
        $temperature = $apiConfig['temperature'] ?? 0.7;
        $maxTokens = $apiConfig['max_tokens'] ?? 2000;

        $apiKey = $apiConfig['api_key'] ?? null;
        if (! $apiKey || trim($apiKey) === '') {
            throw new \Exception("API key not configured or is empty for AI model: {$aiModel->name}. Please check the API configuration.");
        }

        // Trim any whitespace from the API key
        $apiKey = trim($apiKey);

        // Use direct HTTP calls to avoid .env dependency
        switch ($aiModel->name) {
            case 'openai':
                return $this->callOpenAIDirect($apiKey, $model, $prompt, $temperature, $maxTokens);

            case 'gemini':
            case 'google':
            case 'google-ai':
                return $this->callGeminiDirect($apiKey, $model, $prompt, $temperature, $maxTokens);

            case 'anthropic':
            case 'claude':
                return $this->callAnthropicDirect($apiKey, $model, $prompt, $temperature, $maxTokens);

            case 'groq':
                return $this->callGroqDirect($apiKey, $model, $prompt, $temperature, $maxTokens);

            case 'mistral':
                return $this->callMistralDirect($apiKey, $model, $prompt, $temperature, $maxTokens);

            case 'grok':
            case 'x-ai':
            case 'xai':
                return $this->callXAIDirect($apiKey, $model, $prompt, $temperature, $maxTokens);

            case 'deepseek':
                return $this->callDeepSeekDirect($apiKey, $model, $prompt, $temperature, $maxTokens);

            case 'openrouter':
                return $this->callOpenRouterDirect($apiKey, $model, $prompt, $temperature, $maxTokens);

            case 'perplexity':
                return $this->callPerplexityDirect($apiKey, $model, $prompt, $temperature, $maxTokens);

            case 'ollama':
                return $this->callOllamaDirect($apiKey, $model, $prompt, $temperature, $maxTokens, $apiConfig);

            case 'google-ai-overview':
            case 'dataforseo':
                return $this->callGoogleAIOverviewDirect($apiKey, $prompt, $apiConfig);

            default:
                // Generic fallback - try OpenAI-compatible API
                Log::warning('Unknown AI provider, attempting OpenAI-compatible API', [
                    'provider' => $aiModel->name,
                ]);

                return $this->callOpenAIDirect($apiKey, $model, $prompt, $temperature, $maxTokens);
        }
    }

    /**
     * Call OpenAI API directly
     */
    protected function callOpenAIDirect($apiKey, $model, $prompt, $temperature, $maxTokens)
    {
        // Validate API key format for OpenAI (should start with 'sk-')
        if (! str_starts_with($apiKey, 'sk-')) {
            throw new \Exception("Invalid OpenAI API key format. OpenAI API keys should start with 'sk-'");
        }

        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

        if (! $response->successful()) {
            $errorBody = $response->body();
            $errorData = json_decode($errorBody, true);
            $errorMessage = $errorData['error']['message'] ?? $errorBody;

            throw new \Exception("OpenAI API error (Status: {$response->status()}): {$errorMessage}");
        }

        $data = $response->json();

        return (object) ['text' => $data['choices'][0]['message']['content'] ?? ''];
    }

    /**
     * Call Perplexity API directly
     */
    protected function callPerplexityDirect($apiKey, $model, $prompt, $temperature, $maxTokens)
    {
        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.perplexity.ai/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Perplexity API error: '.$response->status().' - '.$response->body());
        }

        $data = $response->json();

        return (object) ['text' => $data['choices'][0]['message']['content'] ?? ''];
    }

    /**
     * Call Gemini API directly
     */
    protected function callGeminiDirect($apiKey, $model, $prompt, $temperature, $maxTokens)
    {
        $response = \Illuminate\Support\Facades\Http::timeout(60)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature' => $temperature,
                    'maxOutputTokens' => $maxTokens,
                ],
            ]);

        if (! $response->successful()) {
            throw new \Exception('Gemini API error: '.$response->status().' - '.$response->body());
        }

        $data = $response->json();

        return (object) ['text' => $data['candidates'][0]['content']['parts'][0]['text'] ?? ''];
    }

    /**
     * Call Anthropic API directly
     */
    protected function callAnthropicDirect($apiKey, $model, $prompt, $temperature, $maxTokens)
    {
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])
            ->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (! $response->successful()) {
            throw new \Exception('Anthropic API error: '.$response->status().' - '.$response->body());
        }

        $data = $response->json();

        return (object) ['text' => $data['content'][0]['text'] ?? ''];
    }

    /**
     * Call Groq API directly
     */
    protected function callGroqDirect($apiKey, $model, $prompt, $temperature, $maxTokens)
    {
        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Groq API error: '.$response->status().' - '.$response->body());
        }

        $data = $response->json();

        return (object) ['text' => $data['choices'][0]['message']['content'] ?? ''];
    }

    /**
     * Call Mistral API directly
     */
    protected function callMistralDirect($apiKey, $model, $prompt, $temperature, $maxTokens)
    {
        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.mistral.ai/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Mistral API error: '.$response->status().' - '.$response->body());
        }

        $data = $response->json();

        return (object) ['text' => $data['choices'][0]['message']['content'] ?? ''];
    }

    /**
     * Call XAI API directly
     */
    protected function callXAIDirect($apiKey, $model, $prompt, $temperature, $maxTokens)
    {
        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.x.ai/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

        if (! $response->successful()) {
            throw new \Exception('XAI API error: '.$response->status().' - '.$response->body());
        }

        $data = $response->json();

        return (object) ['text' => $data['choices'][0]['message']['content'] ?? ''];
    }

    /**
     * Call DeepSeek API directly
     */
    protected function callDeepSeekDirect($apiKey, $model, $prompt, $temperature, $maxTokens)
    {
        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.deepseek.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

        if (! $response->successful()) {
            throw new \Exception('DeepSeek API error: '.$response->status().' - '.$response->body());
        }

        $data = $response->json();

        return (object) ['text' => $data['choices'][0]['message']['content'] ?? ''];
    }

    /**
     * Call OpenRouter API directly
     */
    protected function callOpenRouterDirect($apiKey, $model, $prompt, $temperature, $maxTokens)
    {
        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

        if (! $response->successful()) {
            throw new \Exception('OpenRouter API error: '.$response->status().' - '.$response->body());
        }

        $data = $response->json();

        return (object) ['text' => $data['choices'][0]['message']['content'] ?? ''];
    }

    /**
     * Call Ollama API directly
     */
    protected function callOllamaDirect($apiKey, $model, $prompt, $temperature, $maxTokens, $config)
    {
        $baseUrl = $config['base_url'] ?? 'http://localhost:11434';

        $response = \Illuminate\Support\Facades\Http::timeout(60)
            ->post("{$baseUrl}/api/generate", [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => $temperature,
                    'num_predict' => $maxTokens,
                ],
            ]);

        if (! $response->successful()) {
            throw new \Exception('Ollama API error: '.$response->status().' - '.$response->body());
        }

        $data = $response->json();

        return (object) ['text' => $data['response'] ?? ''];
    }

    /**
     * Call Google AI Overview via DataForSEO API
     */
    protected function callGoogleAIOverviewDirect($apiKey, $prompt, $config)
    {
        // DataForSEO uses username:password authentication
        // The API key should be in format "username:password"
        $credentials = explode(':', $apiKey);
        if (count($credentials) !== 2) {
            throw new \Exception("DataForSEO API key must be in format 'username:password'");
        }

        [$username, $password] = $credentials;

        // Extract location code from config, default to US
        $locationCode = $config['location_code'] ?? 2840; // 2840 = United States
        $languageCode = $config['language_code'] ?? 'en';

        // Prepare DataForSEO payload
        $payload = [
            [
                'language_code' => $languageCode,
                'location_code' => $locationCode,
                'keyword' => $prompt,
                'se_type' => 'ai_overview',
            ],
        ];

        Log::info('Calling Google AI Overview via DataForSEO', [
            'keyword' => substr($prompt, 0, 100),
            'location_code' => $locationCode,
        ]);

        // Call DataForSEO API
        $response = \Illuminate\Support\Facades\Http::withBasicAuth($username, $password)
            ->timeout(90) // Increased timeout for live results
            ->post('https://api.dataforseo.com/v3/serp/google/organic/live/advanced', $payload);

        if (! $response->successful()) {
            throw new \Exception('DataForSEO API error: '.$response->status().' - '.$response->body());
        }

        $data = $response->json();

        // Validate response structure
        if (! isset($data['tasks'][0]['result'][0])) {
            throw new \Exception('DataForSEO API returned invalid response structure');
        }

        $result = $data['tasks'][0]['result'][0];
        $aiOverviewText = '';

        // Try to get AI Overview answer first
        if (isset($result['ai_overview']['answer']) && ! empty($result['ai_overview']['answer'])) {
            $aiOverviewText = $result['ai_overview']['answer'];
            Log::info('Retrieved AI Overview content', ['length' => strlen($aiOverviewText)]);
        } else {
            // Fallback: Extract from other sources
            Log::info('AI Overview not available, extracting from search results');

            $extractedContent = [];

            if (isset($result['items']) && is_array($result['items'])) {
                foreach ($result['items'] as $item) {
                    // Extract from "Found on Web" sections
                    if ($item['type'] === 'found_on_web' && isset($item['items'])) {
                        foreach ($item['items'] as $webItem) {
                            if (isset($webItem['title'])) {
                                $extractedContent[] = $webItem['title'];
                            }
                            if (isset($webItem['description'])) {
                                $extractedContent[] = $webItem['description'];
                            }
                        }
                    }
                    // Extract from organic results
                    elseif ($item['type'] === 'organic' && isset($item['description'])) {
                        $extractedContent[] = $item['description'];
                    }
                    // Extract from People Also Ask
                    elseif ($item['type'] === 'people_also_ask' && isset($item['items'])) {
                        foreach ($item['items'] as $paaItem) {
                            if (isset($paaItem['title'])) {
                                $extractedContent[] = $paaItem['title'];
                            }
                        }
                    }
                }
            }

            if (! empty($extractedContent)) {
                // Limit to first 15 items to avoid too much text
                $aiOverviewText = implode('. ', array_slice($extractedContent, 0, 15));
                Log::info('Extracted content from search results', [
                    'items_count' => count($extractedContent),
                    'text_length' => strlen($aiOverviewText),
                ]);
            } else {
                throw new \Exception('No content available from DataForSEO API');
            }
        }

        if (empty($aiOverviewText)) {
            throw new \Exception('DataForSEO API returned empty content');
        }

        return (object) ['text' => $aiOverviewText];
    }

    /**
     * Get default model name for each provider
     */
    protected function getDefaultModel(string $provider): string
    {
        return match ($provider) {
            'openai' => 'gpt-3.5-turbo',
            'gemini', 'google', 'google-ai' => 'gemini-pro',
            'perplexity' => 'llama-3.1-sonar-small-128k-online',
            'anthropic', 'claude' => 'claude-3-haiku-20240307',
            'grok', 'x-ai', 'xai' => 'grok-beta',
            'groq' => 'llama-3.1-70b-versatile',
            'mistral' => 'mistral-small-latest',
            'ollama' => 'llama3.1',
            'deepseek' => 'deepseek-chat',
            'openrouter' => 'meta-llama/llama-3.1-8b-instruct:free',
            'google-ai-overview', 'dataforseo' => 'ai_overview',
            default => 'gpt-3.5-turbo'
        };
    }

    /**
     * Check if provider supports the required features
     */
    protected function validateProvider(string $provider): bool
    {
        $supportedProviders = [
            // Native Prism providers
            'openai', 'gemini', 'google', 'anthropic', 'claude', 'xai', 'x-ai', 'grok',
            'groq', 'mistral', 'ollama', 'deepseek', 'openrouter',
            // OpenAI-compatible providers
            'perplexity', 'google-ai',
            // DataForSEO Google AI Overview
            'google-ai-overview', 'dataforseo',
        ];

        return in_array($provider, $supportedProviders);
    }

    /**
     * Get sample API configuration for a provider
     */
    public static function getSampleApiConfig(string $provider): array
    {
        switch ($provider) {
            case 'openai':
                return [
                    'api_key' => 'sk-your-openai-api-key-here',
                    'model' => 'gpt-3.5-turbo',
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                ];

            case 'gemini':
            case 'google':
            case 'google-ai':
                return [
                    'api_key' => 'your-google-ai-api-key-here',
                    'model' => 'gemini-pro',
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                ];

            case 'perplexity':
                return [
                    'api_key' => 'pplx-your-perplexity-api-key-here',
                    'model' => 'llama-3.1-sonar-small-128k-online',
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                ];

            case 'anthropic':
            case 'claude':
                return [
                    'api_key' => 'sk-ant-your-anthropic-api-key-here',
                    'model' => 'claude-3-haiku-20240307',
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                ];

            case 'grok':
            case 'x-ai':
            case 'xai':
                return [
                    'api_key' => 'xai-your-x-ai-api-key-here',
                    'model' => 'grok-beta',
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                ];

            case 'groq':
                return [
                    'api_key' => 'gsk_your-groq-api-key-here',
                    'model' => 'llama-3.1-70b-versatile',
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                ];

            case 'mistral':
                return [
                    'api_key' => 'your-mistral-api-key-here',
                    'model' => 'mistral-small-latest',
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                ];

            case 'ollama':
                return [
                    'api_key' => 'not-required-for-local-ollama',
                    'model' => 'llama3.1',
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                    'base_url' => 'http://localhost:11434',
                ];

            case 'deepseek':
                return [
                    'api_key' => 'sk-your-deepseek-api-key-here',
                    'model' => 'deepseek-chat',
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                ];

            case 'openrouter':
                return [
                    'api_key' => 'sk-or-your-openrouter-api-key-here',
                    'model' => 'meta-llama/llama-3.1-8b-instruct:free',
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                ];

            case 'google-ai-overview':
            case 'dataforseo':
                return [
                    'api_key' => 'username:password',
                    'model' => 'ai_overview',
                    'location_code' => 2840, // United States
                    'language_code' => 'en',
                ];

            default:
                return [
                    'api_key' => 'your-api-key-here',
                    'model' => 'default-model',
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                ];
        }
    }

    /**
     * Test an AI model configuration
     */
    public function testAiModel(AiModel $aiModel): array
    {
        try {
            // Log the test attempt for debugging
            Log::info('Testing AI model', [
                'model_id' => $aiModel->id,
                'model_name' => $aiModel->name,
                'display_name' => $aiModel->display_name,
                'has_api_config' => ! empty($aiModel->api_config),
                'api_config_keys' => $aiModel->api_config ? array_keys($aiModel->api_config) : [],
            ]);

            $testPrompt = 'Test prompt: What is artificial intelligence? Please respond briefly.';
            $response = $this->callAiProvider($aiModel, $testPrompt);

            return [
                'success' => true,
                'response' => $response->text,
                'model' => $aiModel->name,
                'message' => 'AI model is working correctly',
            ];
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('AI model test failed', [
                'model_id' => $aiModel->id,
                'model_name' => $aiModel->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'model' => $aiModel->name,
                'message' => 'AI model test failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Parse the AI response to extract HTML, resources, and analysis
     */
    protected function parseAIResponse(string $response, Brand $brand, array $competitors, BrandPrompt $brandPrompt): array
    {
        // Extract HTML response
        preg_match('/HTML_RESPONSE_START(.*?)HTML_RESPONSE_END/s', $response, $htmlMatches);
        $htmlResponse = isset($htmlMatches[1]) ? trim($htmlMatches[1]) : $response;

        // Extract analysis section
        preg_match('/ANALYSIS_START(.*?)ANALYSIS_END/s', $response, $analysisMatches);
        $analysisText = isset($analysisMatches[1]) ? trim($analysisMatches[1]) : '';

        // Parse analysis components
        $analysis = $this->parseAnalysisText($analysisText, $brand, $competitors);

        // Extract resources from analysis and save to database
        $resources = $this->extractResources($analysisText, $htmlResponse, $brandPrompt, $competitors);

        return [
            'html_response' => $htmlResponse,
            'resources' => $resources,
            'analysis' => $analysis,
        ];
    }

    /**
     * Parse the analysis text to extract metrics
     */
    protected function parseAnalysisText(string $analysisText, Brand $brand, array $competitors): array
    {
        $sentiment = 'neutral';
        $position = 0;
        $visibility = 0;
        $competitorMentions = [];

        // Extract sentiment
        if (preg_match('/Brand_Sentiment:\s*([^\n]+)/i', $analysisText, $matches)) {
            $sentimentText = strtolower(trim($matches[1]));
            if (strpos($sentimentText, 'positive') !== false) {
                $sentiment = 'positive';
            } elseif (strpos($sentimentText, 'negative') !== false) {
                $sentiment = 'negative';
            }
        }

        // Extract position percentage
        if (preg_match('/Brand_Position:\s*(\d+)%?/i', $analysisText, $matches)) {
            $position = (int) $matches[1];
        }

        // Extract visibility score
        if (preg_match('/Brand_Visibility:\s*(\d+)/i', $analysisText, $matches)) {
            $visibility = (int) $matches[1];
        }

        // Extract competitor mentions
        if (preg_match('/Competitor_Mentions:\s*(\{.*?\})/s', $analysisText, $matches)) {
            try {
                $competitorMentions = json_decode($matches[1], true) ?: [];
            } catch (\Exception $e) {
                Log::warning('Failed to parse competitor mentions JSON', [
                    'json' => $matches[1],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'sentiment' => $sentiment,
            'position' => $position,
            'visibility' => $visibility,
            'competitor_mentions' => $competitorMentions,
        ];
    }

    /**
     * Extract resources/URLs from the analysis and HTML response, save to database
     */
    protected function extractResources(string $analysisText, string $htmlResponse, BrandPrompt $brandPrompt, array $competitors = []): array
    {
        $resources = [];

        // Extract structured resources from analysis section
        if (preg_match('/Resources:\s*(.+?)(?=Brand_Sentiment:|$)/s', $analysisText, $matches)) {
            $resourcesText = $matches[1];
            $resources = $this->parseStructuredResources($resourcesText, $brandPrompt, $competitors);
        }

        // Also extract any URLs found in HTML response as fallback
        preg_match_all('/https?:\/\/[^\s<>"]+/i', $htmlResponse, $urlMatches);
        if (isset($urlMatches[0])) {
            foreach ($urlMatches[0] as $url) {
                // Check if this URL is already in structured resources
                $exists = collect($resources)->contains(function ($resource) use ($url) {
                    return $resource['url'] === $url;
                });

                if (! $exists) {
                    $resourceData = $this->createResourceEntry($url, 'other', '', '', $brandPrompt, $competitors);
                    if ($resourceData) {
                        $resources[] = $resourceData;
                    }
                }
            }
        }

        // Extract href attributes from HTML
        preg_match_all('/href=["\']([^"\']+)["\']/i', $htmlResponse, $hrefMatches);
        if (isset($hrefMatches[1])) {
            foreach ($hrefMatches[1] as $href) {
                if (filter_var($href, FILTER_VALIDATE_URL)) {
                    $exists = collect($resources)->contains(function ($resource) use ($href) {
                        return $resource['url'] === $href;
                    });

                    if (! $exists) {
                        $resourceData = $this->createResourceEntry($href, 'other', '', '', $brandPrompt, $competitors);
                        if ($resourceData) {
                            $resources[] = $resourceData;
                        }
                    }
                }
            }
        }

        // Save all resources to database
        $this->saveResourcesToDatabase($resources, $brandPrompt);

        // Return just the URLs for backward compatibility
        return array_column($resources, 'url');
    }

    /**
     * Parse structured resources from AI response
     */
    protected function parseStructuredResources(string $resourcesText, BrandPrompt $brandPrompt, array $competitors): array
    {
        $resources = [];
        $lines = explode("\n", $resourcesText);
        $currentResource = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line === '-') {
                continue;
            }

            if (preg_match('/^-?\s*URL:\s*(.+)$/i', $line, $matches)) {
                // Save previous resource if exists
                if (! empty($currentResource)) {
                    $resourceData = $this->createResourceEntry(
                        $currentResource['url'] ?? '',
                        $currentResource['type'] ?? 'other',
                        $currentResource['title'] ?? '',
                        $currentResource['description'] ?? '',
                        $brandPrompt,
                        $competitors
                    );
                    if ($resourceData) {
                        $resources[] = $resourceData;
                    }
                }
                // Start new resource
                $currentResource = ['url' => trim($matches[1])];
            } elseif (preg_match('/^-?\s*Type:\s*(.+)$/i', $line, $matches)) {
                $currentResource['type'] = trim($matches[1]);
            } elseif (preg_match('/^-?\s*Title:\s*(.+)$/i', $line, $matches)) {
                $currentResource['title'] = trim($matches[1]);
            } elseif (preg_match('/^-?\s*Description:\s*(.+)$/i', $line, $matches)) {
                $currentResource['description'] = trim($matches[1]);
            }
        }

        // Save last resource
        if (! empty($currentResource)) {
            $resourceData = $this->createResourceEntry(
                $currentResource['url'] ?? '',
                $currentResource['type'] ?? 'other',
                $currentResource['title'] ?? '',
                $currentResource['description'] ?? '',
                $brandPrompt,
                $competitors
            );
            if ($resourceData) {
                $resources[] = $resourceData;
            }
        }

        return $resources;
    }

    /**
     * Create a resource entry with extracted domain and competitor detection
     */
    protected function createResourceEntry(string $url, string $type, string $title, string $description, BrandPrompt $brandPrompt, array $competitors): ?array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $domain = parse_url($url, PHP_URL_HOST);
        $isCompetitorUrl = $this->isCompetitorUrl($url, $domain, $competitors);

        // Auto-detect Reddit and YouTube URLs if not already categorized
        if ($type === 'other' || $type === 'social_media' || $type === 'social') {
            if ($domain && (strpos($domain, 'reddit.com') !== false || strpos($domain, 'redd.it') !== false)) {
                $type = 'reddit';
            } elseif ($domain && (strpos($domain, 'youtube.com') !== false || strpos($domain, 'youtu.be') !== false)) {
                $type = 'youtube';
            }
        }

        return [
            'brand_prompt_id' => $brandPrompt->id,
            'url' => $url,
            'type' => $this->normalizeResourceType($type),
            'domain' => $domain,
            'title' => $title,
            'description' => $description,
            'is_competitor_url' => $isCompetitorUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Check if URL belongs to a competitor
     */
    protected function isCompetitorUrl(string $url, ?string $domain, array $competitors): bool
    {
        if (! $domain) {
            return false;
        }

        foreach ($competitors as $competitor) {
            $competitorDomain = strtolower($competitor);
            $urlDomain = strtolower($domain);

            // Direct domain match
            if ($urlDomain === $competitorDomain) {
                return true;
            }

            // Check if competitor name is in domain
            if (strpos($urlDomain, $competitorDomain) !== false || strpos($competitorDomain, $urlDomain) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize resource type to standard values
     */
    protected function normalizeResourceType(string $type): string
    {
        $type = strtolower(trim($type));

        $typeMapping = [
            'competitor_website' => 'competitor',
            'competitor' => 'competitor',
            'industry_report' => 'industry_report',
            'news_article' => 'news',
            'news' => 'news',
            'documentation' => 'documentation',
            'docs' => 'documentation',
            'blog_post' => 'blog',
            'blog' => 'blog',
            'research_paper' => 'research',
            'research' => 'research',
            'social_media' => 'social',
            'social' => 'social',
            'reddit' => 'reddit',
            'youtube' => 'youtube',
            'marketplace' => 'marketplace',
            'review_site' => 'reviews',
            'reviews' => 'reviews',
            'other' => 'other',
        ];

        return $typeMapping[$type] ?? 'other';
    }

    /**
     * Save resources to database
     */
    protected function saveResourcesToDatabase(array $resources, BrandPrompt $brandPrompt): void
    {
        try {
            // Delete existing resources for this prompt to avoid duplicates
            BrandPromptResource::where('brand_prompt_id', $brandPrompt->id)->delete();

            // Insert new resources
            if (! empty($resources)) {
                BrandPromptResource::insert($resources);
            }

            Log::info('Saved resources to database', [
                'brand_prompt_id' => $brandPrompt->id,
                'resource_count' => count($resources),
                'competitor_resources' => collect($resources)->where('is_competitor_url', true)->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save resources to database', [
                'brand_prompt_id' => $brandPrompt->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Batch process multiple brand prompts
     */
    public function batchAnalyzePrompts(array $brandPromptIds, string $sessionId = ''): void
    {
        foreach ($brandPromptIds as $brandPromptId) {
            $brandPrompt = BrandPrompt::find($brandPromptId);
            if ($brandPrompt) {
                \App\Jobs\ProcessBrandPromptAnalysis::dispatch($brandPrompt, $sessionId)
                    ->onQueue('default');
            }
        }
    }

    /**
     * Get prompts that contain competitor URLs in their resources
     */
    public function getPromptsWithCompetitorUrls(Brand $brand, string $competitorDomain): array
    {
        return BrandPrompt::where('brand_id', $brand->id)
            ->whereNotNull('analysis_completed_at')
            ->whereHas('promptResources', function ($query) use ($competitorDomain) {
                $query->where('domain', 'like', "%{$competitorDomain}%")
                    ->orWhere('url', 'like', "%{$competitorDomain}%");
            })
            ->with(['promptResources' => function ($query) use ($competitorDomain) {
                $query->where('domain', 'like', "%{$competitorDomain}%")
                    ->orWhere('url', 'like', "%{$competitorDomain}%");
            }])
            ->get()
            ->map(function ($prompt) {
                return [
                    'id' => $prompt->id,
                    'prompt' => $prompt->prompt,
                    'ai_response' => $prompt->ai_response,
                    'sentiment' => $prompt->sentiment,
                    'position' => $prompt->position,
                    'visibility' => $prompt->visibility,
                    'analysis_completed_at' => $prompt->analysis_completed_at,
                    'competitor_resources' => $prompt->promptResources->map(function ($resource) {
                        return [
                            'url' => $resource->url,
                            'type' => $resource->type,
                            'title' => $resource->title,
                            'description' => $resource->description,
                            'domain' => $resource->domain,
                        ];
                    })->toArray(),
                ];
            })
            ->toArray();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Competitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use App\Jobs\FetchCompetitors;
use App\Services\SerpApiStatsExtractor;

class CompetitorController extends Controller
{
    public function generalIndex()
    {
        $brands = Brand::all(); // For now, show all brands for simplicity

        return Inertia::render('competitors/index', [
            'brands' => $brands,
        ]);
    }

    public function index(Brand $brand)
    {
        // Store selected brand in session
        session(['selected_brand_id' => $brand->id]);
        
        // Get existing competitors
        $suggestedCompetitors = $brand->competitors()->where('status', 'suggested')->get();
        $acceptedCompetitors = $brand->competitors()->where('status', 'accepted')->get();
        $totalCompetitors = $suggestedCompetitors->count() + $acceptedCompetitors->count();
        
        // Only auto-fetch if no competitors exist at all
        $shouldAutoFetch = $totalCompetitors === 0;

        return Inertia::render('brands/competitors/index', [
            'brand' => $brand,
            'suggestedCompetitors' => $suggestedCompetitors,
            'acceptedCompetitors' => $acceptedCompetitors,
            'shouldAutoFetch' => $shouldAutoFetch,
            'totalCompetitors' => $totalCompetitors,
        ]);
    }

    public function fetchCompetitorsSync(Brand $brand)
    {
        try {
            // Check if brand already has competitors
            $existingCompetitors = $brand->competitors()->count();
            $competitorsToFetch = $existingCompetitors > 0 ? 5 : 7; // Get 5 more if has some, 7 if none
            
            // Get enabled AI model with API key (consistent with brand creation)
            $aiModel = \App\Models\AiModel::where('is_enabled', true)
                ->whereNotNull('api_config')
                ->get()
                ->filter(function ($model) {
                    $config = $model->api_config;
                    return !empty($config['api_key']);
                })
                ->first();

            if (!$aiModel) {
                return response()->json([
                    'success' => false,
                    'error' => 'No AI model with valid API key found. Please configure an API key for at least one AI model.'
                ], 400);
            }

            // Get existing competitor domains to avoid duplicates
            $existingDomains = $brand->competitors()->pluck('domain')->toArray();
            
            // Prepare the prompt with existing competitors context
            $prompt = $this->getCompetitorPromptForBrand($brand, $competitorsToFetch, $existingDomains);

            Log::info('Fetching competitors for existing brand', [
                'brand_id' => $brand->id,
                'brand_name' => $brand->name,
                'existing_competitors' => $existingCompetitors,
                'fetching' => $competitorsToFetch,
                'ai_model' => $aiModel->name
            ]);

            // Make API call based on the model type
            $apiKey = $aiModel->api_config['api_key'];
            if ($aiModel->name === 'perplexity') {
                $response = Http::timeout(60)
                    ->withToken($apiKey)
                    ->post('https://api.perplexity.ai/chat/completions', [
                        'model' => $aiModel->api_config['model'] ?? 'sonar-pro',
                        'messages' => [
                            ['role' => 'system', 'content' => 'You are a helpful assistant that responds only with valid JSON arrays. Do not include any explanatory text or markdown formatting.'],
                            ['role' => 'user', 'content' => $prompt]
                        ],
                        'max_tokens' => 4000,
                        'temperature' => 0.3,
                        'num_search_results' => 10, // Required for sonar models
                    ]);
            } else {
                // OpenAI
                $response = Http::timeout(60)
                    ->withToken($apiKey)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => $aiModel->api_config['model'] ?? 'gpt-4',
                        'messages' => [
                            ['role' => 'system', 'content' => 'You are a helpful assistant that responds only with valid JSON arrays. Do not include any explanatory text or markdown formatting.'],
                            ['role' => 'user', 'content' => $prompt]
                        ],
                        'max_tokens' => 4000,
                        'temperature' => 0.3,
                    ]);
            }

            if ($response->failed()) {
                return response()->json([
                    'success' => false,
                    'error' => 'AI API call failed: ' . $response->body()
                ], 500);
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!$content) {
                return response()->json([
                    'success' => false,
                    'error' => 'No content received from AI'
                ], 500);
            }

            // Parse JSON response
            $competitorsData = null;
            
            // Try direct JSON parsing first
            $competitorsData = json_decode($content, true);
            
            // If that fails, try to extract JSON from the response
            if (!$competitorsData || json_last_error() !== JSON_ERROR_NONE) {
                // Look for JSON array pattern in the text
                if (preg_match('/```json\s*(\[.*?\])\s*```/s', $content, $matches)) {
                    $competitorsData = json_decode($matches[1], true);
                } elseif (preg_match('/(\[[\s\S]*?\}[\s\S]*?\])/s', $content, $matches)) {
                    $competitorsData = json_decode($matches[1], true);
                } else {
                    // Try to find individual JSON objects and build an array
                    preg_match_all('/\{[^{}]*"name"[^{}]*"domain"[^{}]*"mentions"[^{}]*\}/', $content, $matches);
                    if (!empty($matches[0])) {
                        $competitorsData = [];
                        foreach ($matches[0] as $jsonObj) {
                            $obj = json_decode($jsonObj, true);
                            if ($obj) {
                                $competitorsData[] = $obj;
                            }
                        }
                    }
                }
            }
            
            if (!$competitorsData || json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to parse competitor data from AI response',
                    'debug' => [
                        'raw_response' => substr($content, 0, 1000),
                        'json_error' => json_last_error_msg()
                    ]
                ], 500);
            }

            // Handle different JSON structures
            if (isset($competitorsData['competitors']) && is_array($competitorsData['competitors'])) {
                $competitors = $competitorsData['competitors'];
            } elseif (is_array($competitorsData)) {
                $competitors = $competitorsData;
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid competitor data structure'
                ], 500);
            }

            // Store competitors (only save new ones to avoid duplicates)
            $savedCompetitors = [];
            
            // Get the highest existing rank for this brand to continue from there
            $maxRank = $brand->competitors()
                ->whereNotNull('rank')
                ->where('rank', '>', 0)
                ->max('rank') ?? 0;
            
            foreach ($competitors as $index => $competitorData) {
                if (isset($competitorData['name']) && isset($competitorData['domain'])) {
                    $competitor = $brand->competitors()->updateOrCreate(
                        ['domain' => $competitorData['domain']],
                        [
                            'name' => $competitorData['name'],
                            'mentions' => $competitorData['mentions'] ?? 10,
                            'status' => 'suggested',
                            'source' => 'ai',
                            'rank' => $maxRank + $index + 1, // Assign rank based on position
                            'sentiment' => 0.6, // Default sentiment for new competitors
                            'visibility' => 0.7, // Default visibility for new competitors
                        ]
                    );
                    $savedCompetitors[] = $competitor;
                }
            }

            return response()->json([
                'success' => true,
                'competitors' => $savedCompetitors,
                'message' => 'Successfully fetched ' . count($savedCompetitors) . ' competitors'
            ]);

        } catch (\Exception $e) {
            Log::error('Competitor fetch error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'An error occurred while fetching competitors: ' . $e->getMessage()
            ], 500);
        }
    }

    public function refreshCompetitors(Brand $brand)
    {
        try {
            // Clear existing AI-generated suggested competitors (keep accepted and manual ones)
            $brand->competitors()->where('source', 'ai')->where('status', 'suggested')->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Competitors cleared. Ready to fetch new ones.'
            ]);

        } catch (\Exception $e) {
            Log::error('Competitor refresh error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to clear competitors: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getCompetitorPromptForBrand(Brand $brand, int $count, array $existingDomains): string
    {
        $brandUrl = $brand->website ?: $brand->name;
        $description = $brand->description ?: '';
        
        $excludeText = '';
        if (!empty($existingDomains)) {
            $excludeList = implode('", "', $existingDomains);
            $excludeText = "\n\nIMPORTANT: Exclude these existing competitors: \"{$excludeList}\"";
        }

        return <<<PROMPT
        I need you to identify the top {$count} direct competitors for the brand: {$brand->name}

        Website: {$brandUrl}
        Description: {$description}{$excludeText}

        Please analyze this brand and identify its main competitors in the same industry/niche. Focus on:
        1. Direct competitors offering similar products/services
        2. Companies targeting the same customer base  
        3. Brands with similar market positioning

        For each competitor, provide:
        - Company name
        - Website domain (full URL with https://)
        - Estimated mentions/relevance score (10-50)

        Return the results as a JSON array with objects containing 'name', 'domain', and 'mentions' fields:

        [
        {"name": "Company Name", "domain": "https://example.com", "mentions": 25},
        {"name": "Another Company", "domain": "https://another.com", "mentions": 30}
        ]

        Respond with ONLY the JSON array, nothing else.
        PROMPT;
            }

            private function getCompetitorPrompt(Brand $brand): string
            {
                $brandUrl = $brand->website ?: $brand->name;

                return <<<PROMPT
        You are a competitor analysis expert. Analyze the brand "{$brand->name}" at URL "{$brandUrl}" and identify direct market competitors.

        IMPORTANT: Respond ONLY with a valid JSON array. Do not include any explanatory text, disclaimers, or markdown formatting.

        Return exactly this JSON structure:
        [
            {
                "name": "Competitor Name",
                "domain": "https://competitor.com",
                "mentions": 10
            }
        ]

        Find 5-8 direct competitors that offer similar products or services to "{$brand->name}". For each competitor, provide:
        - name: The brand/company name
        - domain: The official website URL (must include https://)  
        - mentions: Estimated relevance score (10-50)

        Respond with ONLY the JSON array, nothing else.
        PROMPT;
    }

    public function fetchFromAI(Request $request, Brand $brand)
    {
        FetchCompetitors::dispatch($brand);

        return back()->with('success', 'Competitor analysis has been started. Results will appear shortly.');
    }

    public function store(Request $request, Brand $brand)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'required|string|max:255|url',
        ]);

        $brand->competitors()->create([
            'name' => $request->name,
            'domain' => $request->domain,
            'status' => 'accepted',
            'source' => 'manual',
        ]);

        return back()->with('success', 'Competitor added successfully.');
    }

    public function update(Request $request, Competitor $competitor)
    {
        $request->validate([
            'status' => 'required|in:accepted,suggested,rejected',
        ]);

        $competitor->update(['status' => $request->status]);

        // Handle AJAX requests vs form requests
        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Competitor status updated.',
                'competitor' => $competitor
            ]);
        }

        return back()->with('success', 'Competitor status updated.');
    }

    public function destroy(Competitor $competitor)
    {
        $competitor->delete();

        // Handle AJAX requests vs form requests
        if (request()->expectsJson() || request()->ajax() || request()->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Competitor removed.'
            ]);
        }

        return back()->with('success', 'Competitor removed.');
    }

    /**
     * Fetch competitor stats using SerpAPI
     */
    public function fetchCompetitorStats(Competitor $competitor)
    {
        try {
            // You'll need to add your SerpAPI key to your .env file
            $serpApiKey = env('SERPAPI_KEY');
            
            if (!$serpApiKey) {
                return response()->json([
                    'error' => 'SerpAPI key not configured'
                ], 400);
            }

            // Make request to SerpAPI for the competitor
            $response = Http::get('https://serpapi.com/search.json', [
                'q' => $competitor->name,
                'engine' => 'google',
                'api_key' => $serpApiKey,
                'device' => 'desktop',
                'google_domain' => 'google.com'
            ]);

            if ($response->successful()) {
                $serpApiData = $response->json();
                
                // Extract stats using our SerpApiStatsExtractor
                $stats = SerpApiStatsExtractor::extractStats($serpApiData);
                
                // Update the competitor with the extracted stats
                $competitor->update([
                    'rank' => $stats['rank']['primary_position'] ?? null,
                    'visibility' => $stats['visibility']['search_presence_score'] ?? null,
                    'sentiment' => $stats['sentiment']['overall_sentiment'] ?? 'neutral',
                    'traffic_estimate' => $stats['traffic_estimate']['estimated_monthly_clicks'] ?? null,
                    'market_share' => $stats['market_share']['serp_dominance'] ?? null,
                    'social_metrics' => $stats['social_metrics'] ?? null,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Stats updated successfully',
                    'stats' => $stats,
                    'competitor' => $competitor->fresh()
                ]);
            } else {
                return response()->json([
                    'error' => 'Failed to fetch data from SerpAPI'
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Error fetching competitor stats: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'An error occurred while fetching stats: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Analyze competitor stats from JSON data
     */
    public function analyzeCompetitorFromJson(Request $request)
    {
        try {
            $request->validate([
                'json_data' => 'required|string',
                'competitor_name' => 'required|string'
            ]);

            // Parse the JSON data
            $jsonData = json_decode($request->json_data, true);
            
            if (!$jsonData) {
                return response()->json([
                    'error' => 'Invalid JSON data provided'
                ], 400);
            }

            // Extract stats using our SerpApiStatsExtractor
            $stats = SerpApiStatsExtractor::extractStats($jsonData);

            return response()->json([
                'success' => true,
                'competitor_name' => $request->competitor_name,
                'stats' => $stats,
                'summary' => [
                    'rank' => $stats['rank']['primary_position'] ?? 'Not found',
                    'visibility_score' => $stats['visibility']['search_presence_score'] ?? 0,
                    'sentiment' => $stats['sentiment']['overall_sentiment'] ?? 'neutral',
                    'estimated_traffic' => $stats['traffic_estimate']['estimated_monthly_clicks'] ?? 0,
                    'social_platforms' => $stats['social_metrics']['platform_count'] ?? 0,
                    'followers' => $stats['social_metrics']['total_followers'] ?? 0
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error analyzing competitor JSON: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'An error occurred while analyzing the data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch competitors for brand creation (before brand exists)
     */
    public function fetchForBrandCreation(Request $request)
    {
        $request->validate([
            'website' => 'required|url',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'session_id' => 'nullable|string'
        ]);

        try {
            // Normalize the website URL for consistent lookups
            $normalizedWebsite = rtrim(strtolower($request->website), '/');
            
            // Check if the website has changed in this session
            $sessionKey = 'brand_creation_website_' . ($request->session_id ?? session()->getId());
            $previousWebsite = session($sessionKey);
            $websiteChanged = $previousWebsite && $previousWebsite !== $normalizedWebsite;
            
            // Store current website in session
            session([$sessionKey => $normalizedWebsite]);
            
            // If website changed, skip cache and generate fresh competitors
            if (!$websiteChanged) {
                // Check if we have cached competitors for this website in the database
                $existingBrand = \App\Models\Brand::where('website', $normalizedWebsite)
                    ->orWhere('website', 'LIKE', '%' . parse_url($normalizedWebsite, PHP_URL_HOST) . '%')
                    ->first();
                
                if ($existingBrand && $existingBrand->competitors()->exists()) {
                    // Return existing competitors from database
                    $cachedCompetitors = $existingBrand->competitors()
                        ->select('id', 'name', 'domain', 'mentions', 'status', 'source')
                        ->get()
                        ->map(function ($competitor) {
                            return [
                                'id' => $competitor->id,
                                'name' => $competitor->name,
                                'domain' => $competitor->domain,
                                'mentions' => $competitor->mentions ?? 0,
                                'status' => $competitor->status ?? 'suggested',
                                'source' => $competitor->source ?? 'ai'
                            ];
                        })
                        ->toArray();
                    
                    Log::info('Returned cached competitors from database', [
                        'website' => $request->website,
                        'brand_id' => $existingBrand->id,
                        'competitor_count' => count($cachedCompetitors)
                    ]);
                    
                    return response()->json([
                        'success' => true,
                        'competitors' => $cachedCompetitors,
                        'cached' => true,
                        'message' => 'Returning previously saved competitors for this website'
                    ]);
                }
            } else {
                Log::info('Website changed, skipping cache and generating fresh competitors', [
                    'previous_website' => $previousWebsite,
                    'new_website' => $normalizedWebsite
                ]);
            }

            // If no cached competitors, fetch from AI
            // Get enabled AI model with API key
            $aiModel = \App\Models\AiModel::where('is_enabled', true)
                ->whereNotNull('api_config')
                ->get()
                ->filter(function ($model) {
                    $config = $model->api_config;
                    return !empty($config['api_key']);
                })
                ->first();

            if (!$aiModel) {
                return response()->json([
                    'success' => false,
                    'error' => 'No AI model with valid API key found. Please configure an API key for at least one AI model.'
                ], 400);
            }

            // Create a temporary brand-like object for the prompt
            $brandData = [
                'name' => $request->name ?: 'Unknown Brand',
                'website' => $request->website,
                'description' => $request->description ?: ''
            ];

            // Prepare the prompt
            $prompt = $this->getCompetitorPromptForCreation($brandData);

            Log::info('Fetching competitors for brand creation', [
                'website' => $request->website,
                'ai_model' => $aiModel->name
            ]);

            // Make API call based on the model type
            $apiKey = $aiModel->api_config['api_key'];
            
            if ($aiModel->name === 'perplexity') {
                $response = Http::timeout(60)
                    ->withToken($apiKey)
                    ->post('https://api.perplexity.ai/chat/completions', [
                        'model' => $aiModel->api_config['model'] ?? 'sonar-pro',
                        'messages' => [
                            ['role' => 'system', 'content' => 'You are a helpful assistant that responds only with valid JSON arrays. Do not include any explanatory text or markdown formatting.'],
                            ['role' => 'user', 'content' => $prompt]
                        ],
                        'max_tokens' => 4000,
                        'temperature' => 0.3,
                        'num_search_results' => 10, // Required for sonar models
                    ]);
            } else {
                // OpenAI or other models
                $response = Http::timeout(60)
                    ->withToken($apiKey)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => $aiModel->api_config['model'] ?? 'gpt-4',
                        'messages' => [
                            ['role' => 'system', 'content' => 'You are a helpful assistant that responds only with valid JSON arrays. Do not include any explanatory text or markdown formatting.'],
                            ['role' => 'user', 'content' => $prompt]
                        ],
                        'max_tokens' => 4000,
                        'temperature' => 0.3,
                    ]);
            }

            if (!$response->successful()) {
                Log::error('AI API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to fetch competitors from AI service. Please try again later.'
                ], 500);
            }

            $data = $response->json();
            $content = '';

            if ($aiModel->name === 'perplexity') {
                $content = $data['choices'][0]['message']['content'] ?? '';
            } else {
                $content = $data['choices'][0]['message']['content'] ?? '';
            }

            // Parse the JSON response
            $competitors = $this->parseCompetitorsFromAI($content);

            if (empty($competitors)) {
                return response()->json([
                    'success' => false,
                    'error' => 'No competitors found for this website.'
                ], 400);
            }

            Log::info('Successfully fetched competitors', [
                'website' => $request->website,
                'competitor_count' => count($competitors)
            ]);

            return response()->json([
                'success' => true,
                'competitors' => $competitors,
                'ai_model_used' => $aiModel->display_name
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching competitors for brand creation', [
                'error' => $e->getMessage(),
                'website' => $request->website
            ]);

            return response()->json([
                'success' => false,
                'error' => 'An error occurred while fetching competitors: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get competitor prompt for brand creation (modified version)
     */
    private function getCompetitorPromptForCreation(array $brandData): string
    {
        $website = $brandData['website'];
        $name = $brandData['name'];
        $description = $brandData['description'];

        return <<<PROMPT
I need you to identify the top 5-7 direct competitors for the website: {$website}

Brand name: {$name}
Description: {$description}

Please analyze this brand and identify its main competitors in the same industry/niche. Focus on:
1. Direct competitors offering similar products/services
2. Companies targeting the same customer base
3. Brands with similar market positioning

For each competitor, provide:
- Company name
- Website domain (full URL)
- Brief reason why they're a competitor

Return the results as a JSON array with objects containing 'name' and 'domain' fields only:

[
  {"name": "Company Name", "domain": "https://example.com"},
  {"name": "Another Company", "domain": "https://another.com"}
]

Respond with ONLY the JSON array, nothing else.
PROMPT;
    }

    /**
     * Parse competitors from AI response
     */
    private function parseCompetitorsFromAI(string $content): array
    {
        // Clean the content
        $content = trim($content);
        
        // Remove markdown code blocks if present
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*$/', '', $content);
        
        try {
            $competitors = json_decode($content, true);
            
            if (!is_array($competitors)) {
                Log::warning('AI response is not an array', ['content' => $content]);
                return [];
            }
            
            // Validate and clean competitors
            $validCompetitors = [];
            foreach ($competitors as $competitor) {
                if (isset($competitor['name']) && isset($competitor['domain'])) {
                    $domain = $competitor['domain'];
                    
                    // Ensure domain has protocol
                    if (!preg_match('/^https?:\/\//', $domain)) {
                        $domain = 'https://' . ltrim($domain, '/');
                    }
                    
                    // Validate domain format
                    if (filter_var($domain, FILTER_VALIDATE_URL)) {
                        $validCompetitors[] = [
                            'name' => trim($competitor['name']),
                            'domain' => $domain,
                            'mentions' => 0 // Default for brand creation
                        ];
                    }
                }
            }
            
            return $validCompetitors;
            
        } catch (\Exception $e) {
            Log::error('Failed to parse competitors JSON', [
                'content' => $content,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}

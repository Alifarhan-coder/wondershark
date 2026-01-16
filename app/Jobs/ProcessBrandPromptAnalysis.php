<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Models\BrandPrompt;
use App\Services\BrandPromptAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessBrandPromptAnalysis implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600; // 10 minutes timeout

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public BrandPrompt $brandPrompt,
        public string $sessionId = '',
        public bool $forceRegenerate = false,
        public ?string $preferredAiModel = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(BrandPromptAnalysisService $analysisService): void
    {
        try {
            Log::info('Starting brand prompt analysis', [
                'brand_prompt_id' => $this->brandPrompt->id,
                'brand_id' => $this->brandPrompt->brand_id,
                'prompt' => substr($this->brandPrompt->prompt, 0, 100).'...',
                'force_regenerate' => $this->forceRegenerate,
                'preferred_ai_model' => $this->preferredAiModel,
            ]);

            // Skip if already analyzed and not forcing regeneration
            if (! $this->forceRegenerate &&
                $this->brandPrompt->ai_response &&
                $this->brandPrompt->resources &&
                $this->brandPrompt->analysis_completed_at) {

                // Touch updated_at to show when this prompt was last checked
                $this->brandPrompt->touch();

                Log::info('Skipping already analyzed prompt', [
                    'brand_prompt_id' => $this->brandPrompt->id,
                    'last_checked' => now(),
                ]);

                return;
            }

            // Load brand with competitors
            $brand = $this->brandPrompt->brand()->with('competitors')->first();

            if (! $brand) {
                Log::error('Brand not found for prompt', [
                    'brand_prompt_id' => $this->brandPrompt->id,
                    'brand_id' => $this->brandPrompt->brand_id,
                ]);

                return;
            }

            // Generate AI response and analyze with session tracking
            $result = $analysisService->analyzePrompt(
                $this->brandPrompt,
                $brand,
                $this->preferredAiModel,
                $this->sessionId
            );

            // Update the brand prompt with results
            $updateData = [
                'ai_response' => $result['ai_response'],
                'resources' => json_encode($result['resources']),
                'sentiment' => $result['analysis']['sentiment'],
                'position' => $result['analysis']['position'],
                'visibility' => $result['analysis']['visibility'],
                'competitor_mentions' => json_encode($result['analysis']['competitor_mentions']),
                'analysis_completed_at' => now(),
                'analysis_failed_at' => null, // Clear any previous failure
                'analysis_error' => null, // Clear any previous error
                'session_id' => $this->sessionId ?: $this->brandPrompt->session_id,
                'ai_model_id' => $result['ai_model_id'] ?? null,
            ];

            Log::info('Updating brand prompt with analysis', [
                'brand_prompt_id' => $this->brandPrompt->id,
                'ai_model_id_to_save' => $updateData['ai_model_id'],
                'result_ai_model_id' => $result['ai_model_id'] ?? 'NOT_SET',
            ]);

            $this->brandPrompt->update($updateData);

            // Verify the update
            $this->brandPrompt->refresh();

            Log::info('Successfully completed brand prompt analysis', [
                'brand_prompt_id' => $this->brandPrompt->id,
                'sentiment' => $result['analysis']['sentiment'],
                'position' => $result['analysis']['position'],
                'visibility' => $result['analysis']['visibility'],
                'resources_count' => count($result['resources']),
                'ai_model_id_saved' => $this->brandPrompt->ai_model_id,
            ]);

        } catch (\Exception $e) {
            Log::error('Brand Prompt Analysis Failed', [
                'brand_prompt_id' => $this->brandPrompt->id,
                'brand_id' => $this->brandPrompt->brand_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Brand Prompt Analysis Job Failed Permanently', [
            'brand_prompt_id' => $this->brandPrompt->id,
            'brand_id' => $this->brandPrompt->brand_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->tries,
        ]);

        // Mark as failed
        $this->brandPrompt->update([
            'analysis_failed_at' => now(),
            'analysis_error' => $exception->getMessage(),
        ]);
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBrandPromptAnalysis;
use App\Models\Brand;
use App\Models\BrandPrompt;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProcessBrandPromptsAnalysis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brand:analyze-prompts 
                            {--brand=* : Process specific brand IDs}
                            {--all : Process all brands}
                            {--force : Force re-analysis of already processed prompts}
                            {--session= : Optional session ID for tracking}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process brand prompts to generate AI responses and competitor analysis';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $brandIds = $this->option('brand');
        $processAll = $this->option('all');
        $forceRegenerate = $this->option('force');
        $sessionId = $this->option('session') ?: Str::uuid()->toString();
        $verbose = $this->option('verbose');

        if (! $brandIds && ! $processAll) {
            $this->error('Please specify either --brand=ID or --all option');

            return 1;
        }

        if ($processAll) {
            $brands = Brand::all();
            $this->info('Processing prompts for all '.$brands->count().' brands...');
        } else {
            $brands = Brand::whereIn('id', $brandIds)->get();
            $notFound = array_diff($brandIds, $brands->pluck('id')->toArray());

            if (! empty($notFound)) {
                $this->warn('Brands not found: '.implode(', ', $notFound));
            }

            if ($brands->isEmpty()) {
                $this->error('No valid brands found');

                return 1;
            }

            $this->info('Processing prompts for '.$brands->count().' brand(s): '.$brands->pluck('name')->implode(', '));
        }

        // Get enabled AI models for distribution
        $enabledModels = \App\Models\AiModel::where('is_enabled', true)->orderBy('name')->get();

        if ($enabledModels->isEmpty()) {
            $this->error('No enabled AI models found!');

            return 1;
        }

        $this->info("Enabled AI Models ({$enabledModels->count()}): ".$enabledModels->pluck('name')->implode(', '));
        $this->newLine();

        $totalJobs = 0;
        $progressBar = $this->output->createProgressBar($brands->count());
        $progressBar->start();

        foreach ($brands as $brand) {
            // Get total prompts without any filtering (to debug)
            $allPromptsCount = BrandPrompt::withoutGlobalScopes()->where('brand_id', $brand->id)->count();
            $activePromptsCount = BrandPrompt::withoutGlobalScopes()->where('brand_id', $brand->id)->where('is_active', true)->count();
            $brandPromptsCount = BrandPrompt::where('brand_id', $brand->id)->count(); // With global scope (post_id is null)

            // Get only active brand prompts (post_id is null due to BrandPrompt global scope)
            $prompts = BrandPrompt::where('brand_id', $brand->id)
                ->where('is_active', true)
                ->get();

            $this->newLine();
            $this->info("Brand: {$brand->name}");
            $this->line("  - All prompts in DB: {$allPromptsCount}");
            $this->line("  - Active prompts: {$activePromptsCount}");
            $this->line("  - Brand-type prompts (post_id IS NULL): {$brandPromptsCount}");
            $this->line("  - Active brand-type prompts: {$prompts->count()}");

            if ($prompts->isEmpty()) {
                $this->warn('  → No active brand prompts to process');
                $progressBar->advance();

                continue;
            }

            // Filter prompts that need analysis
            $promptsToProcess = $prompts->filter(function ($prompt) use ($forceRegenerate) {
                return $forceRegenerate || ! $prompt->analysis_completed_at || ! $prompt->ai_response;
            })->values();

            if ($promptsToProcess->isEmpty()) {
                $this->warn('  → All prompts already analyzed (use --force to re-analyze)');
                $progressBar->advance();

                continue;
            }

            $totalPrompts = $promptsToProcess->count();

            // Calculate weighted distribution using Largest Remainder Method (Hamilton's method)
            $totalWeight = $enabledModels->sum('order');
            $modelAssignments = [];
            $fractionalParts = [];

            // Step 1: Calculate ideal fractional assignments
            foreach ($enabledModels as $model) {
                $weight = $model->order ?? 1;
                $idealAssignment = ($weight / $totalWeight) * $totalPrompts;
                $baseAssignment = (int) floor($idealAssignment);
                $fractional = $idealAssignment - $baseAssignment;

                $modelAssignments[$model->id] = [
                    'model' => $model,
                    'base' => $baseAssignment,
                    'fractional' => $fractional,
                    'final' => $baseAssignment,
                ];
                $fractionalParts[$model->id] = $fractional;
            }

            // Step 2: Distribute remaining prompts to models with largest fractional parts
            $assignedTotal = array_sum(array_column($modelAssignments, 'base'));
            $remaining = $totalPrompts - $assignedTotal;

            if ($remaining > 0) {
                // Sort by fractional part descending
                arsort($fractionalParts);
                $topModels = array_slice(array_keys($fractionalParts), 0, $remaining, true);

                foreach ($topModels as $modelId) {
                    $modelAssignments[$modelId]['final']++;
                }
            }

            // Show weights and distribution plan
            $this->line('  AI Model Weights:');
            foreach ($modelAssignments as $assignment) {
                $weight = $assignment['model']->order ?? 1;
                $percentage = round(($weight / $totalWeight) * 100, 1);
                $this->line("    - {$assignment['model']->name}: weight={$weight} ({$percentage}%) → {$assignment['final']} prompts");
            }

            // Step 3: Assign prompts to models based on calculated distribution
            $modelQueue = [];
            foreach ($modelAssignments as $assignment) {
                for ($i = 0; $i < $assignment['final']; $i++) {
                    $modelQueue[] = $assignment['model'];
                }
            }

            // Shuffle to avoid always assigning same models to first/last prompts
            shuffle($modelQueue);

            $modelDistribution = [];
            foreach ($promptsToProcess as $index => $prompt) {
                $assignedModel = $modelQueue[$index];

                // Track distribution for reporting
                if (! isset($modelDistribution[$assignedModel->name])) {
                    $modelDistribution[$assignedModel->name] = 0;
                }
                $modelDistribution[$assignedModel->name]++;

                if ($verbose) {
                    $this->line("  Queueing prompt #{$prompt->id} → {$assignedModel->name}: ".substr($prompt->prompt, 0, 50).'...');
                }

                // Dispatch with assigned model
                ProcessBrandPromptAnalysis::dispatch($prompt, $sessionId, $forceRegenerate, $assignedModel->name)
                    ->onQueue('default');

                $totalJobs++;
            }

            // Verify distribution matches calculation
            $this->line('  Actual Distribution:');
            foreach ($modelDistribution as $modelName => $count) {
                $this->line("    - {$modelName}: {$count} prompts");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        if ($totalJobs > 0) {
            $this->info("Queued {$totalJobs} analysis jobs with session ID: {$sessionId}");
            $this->info('Monitor progress with: php artisan queue:work');
        } else {
            $this->info('No prompts needed analysis. Use --force to re-analyze existing ones.');
        }

        return 0;
    }
}

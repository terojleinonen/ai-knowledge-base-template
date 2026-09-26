<?php

namespace App\Console\Commands;

use App\Evaluation\CaseResult;
use App\Evaluation\Dataset;
use App\Evaluation\EvalRunner;
use App\Evaluation\Summary;
use App\Services\Retrieval\Embedder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

#[Signature('kb:eval
    {dataset=evals/northwind : Dataset directory or cases.json, relative to the backend directory}
    {--retrieval-only : Only measure retrieval (fast, no chat model calls)}
    {--no-repair : Disable citation repair to measure the raw model}
    {--limit= : Only run the first N cases}
    {--json= : Also write full results to this JSON file}')]
#[Description('Measure retrieval and answer quality against a dataset of questions with known answers')]
class EvaluateKnowledgeBase extends Command
{
    public function handle(EvalRunner $runner, Embedder $embedder): int
    {
        $path = $this->argument('dataset');
        $path = str_starts_with($path, '/') ? $path : base_path($path);

        try {
            $dataset = Dataset::load($path);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('no-repair')) {
            config(['knowledge.citations.repair' => false]);
        }

        $withAnswers = ! $this->option('retrieval-only');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $settings = $this->settings($embedder, $withAnswers);

        $this->components->info("Evaluating \"{$dataset->name}\": ".count($dataset->documents).' documents, '
            .min(count($dataset->cases), $limit ?? PHP_INT_MAX).' cases');
        $this->components->twoColumnDetail('<fg=gray>Settings</>', implode(' · ', array_map(
            fn ($k, $v) => "{$k}={$v}", array_keys($settings), $settings,
        )));
        $this->newLine();

        try {
            $results = $runner->run($dataset, $withAnswers, $limit, function (CaseResult $result, int $n) {
                $this->printCase($result, $n);
            });
        } catch (Throwable $e) {
            $this->components->error('Evaluation aborted: '.$e->getMessage());

            return self::FAILURE;
        }

        $summary = Summary::of($results);
        $this->printSummary($summary, $withAnswers);

        if ($file = $this->option('json')) {
            file_put_contents($file, json_encode([
                'dataset' => $dataset->name,
                'ran_at' => now()->toIso8601String(),
                'settings' => $settings,
                'summary' => $summary,
                'cases' => array_map(fn (CaseResult $r) => $r->toArray(), $results),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            $this->components->info("Results written to {$file}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string|int|float>
     */
    private function settings(Embedder $embedder, bool $withAnswers): array
    {
        return array_filter([
            'embeddings' => $embedder->identifier(),
            'chat' => $withAnswers ? config('knowledge.chat.provider').':'.config('knowledge.chat.model') : null,
            'top_k' => (int) config('knowledge.retrieval.top_k'),
            'min_score' => (float) config('knowledge.retrieval.min_score'),
            'chunk' => config('knowledge.chunking.size').'/'.config('knowledge.chunking.overlap'),
            'repair' => $withAnswers ? (config('knowledge.citations.repair') ? 'on' : 'off') : null,
        ], fn ($v) => $v !== null);
    }

    private function printCase(CaseResult $r, int $n): void
    {
        $status = match ($r->passed()) {
            true => '<fg=green>PASS</>',
            false => '<fg=red>FAIL</>',
            null => '<fg=gray>----</>',
        };

        $details = [];

        if ($r->case->unanswerable) {
            $details[] = $r->retrievedDocuments === [] ? 'filtered by retrieval' : sprintf('retrieved (top %.2f)', $r->topScore);
        } else {
            $details[] = $r->rank === null ? '<fg=red>expected doc not retrieved</>' : "rank {$r->rank}";
        }

        if ($r->answer !== null) {
            if ($r->case->unanswerable) {
                $details[] = $r->abstained ? 'abstained' : '<fg=red>answered anyway</>';
            } else {
                $details[] = "facts {$r->factsFound}/{$r->factsTotal()}";
                $details[] = "citations {$r->correctCitations}/{$r->citations}";

                if ($r->abstained) {
                    $details[] = '<fg=red>abstained</>';
                }
            }

            $details[] = sprintf('%.1fs', $r->answerMs / 1000);
        }

        $this->components->twoColumnDetail(
            sprintf('%s <fg=gray>%2d.</> %s', $status, $n, Str::limit($r->case->question, 60)),
            implode(' · ', $details),
        );

        if ($r->passed() === false && $r->answer !== null) {
            if ($r->missingFacts !== []) {
                $this->line('        <fg=gray>missing:</> '.implode(', ', $r->missingFacts));
            }
            $this->line('        <fg=gray>answer:</>  '.Str::limit(str_replace("\n", ' ', $r->answer), 200));
        }
    }

    /**
     * @param  array<string, int|float|null>  $s
     */
    private function printSummary(array $s, bool $withAnswers): void
    {
        $pct = fn (?float $v) => $v === null ? 'n/a' : sprintf('%.0f%%', $v * 100);
        $ms = fn (?float $v) => $v === null ? 'n/a' : ($v >= 1000 ? sprintf('%.1fs', $v / 1000) : sprintf('%dms', $v));

        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>Retrieval</>');
        $this->components->twoColumnDetail('Hit@1 (right document ranked first)', $pct($s['hit_at_1']));
        $this->components->twoColumnDetail('Hit@k (right document retrieved at all)', $pct($s['hit_at_k']));
        $this->components->twoColumnDetail('MRR', $s['mrr'] === null ? 'n/a' : sprintf('%.2f', $s['mrr']));
        $this->components->twoColumnDetail('Unanswerable questions filtered by retrieval', $pct($s['off_topic_filtered']));
        $this->components->twoColumnDetail('Latency p50', $ms($s['retrieval_ms_p50']));

        if ($withAnswers) {
            $this->newLine();
            $this->components->twoColumnDetail('<options=bold>Answers</>');
            $this->components->twoColumnDetail('Fact recall', $pct($s['fact_recall']));
            $this->components->twoColumnDetail('Citation precision (cited the right document)', $pct($s['citation_precision']));
            $this->components->twoColumnDetail('Answers without citations', (string) ($s['uncited_answers'] ?? 'n/a'));
            $this->components->twoColumnDetail('Correct abstentions (unanswerable)', $pct($s['correct_abstentions']));
            $this->components->twoColumnDetail('False abstentions (answerable)', (string) ($s['false_abstentions'] ?? 'n/a'));
            $this->components->twoColumnDetail('Latency p50 / max', $ms($s['answer_ms_p50']).' / '.$ms($s['answer_ms_max']));
        }

        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>Passed</>', "{$s['passed']}/{$s['judged']}");
    }
}

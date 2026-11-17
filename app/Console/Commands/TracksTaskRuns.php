<?php

namespace App\Console\Commands;

use App\Models\TaskRun;

trait TracksTaskRuns
{
    /** Run $fn while recording a task_runs row (for the System → Scheduled tasks monitor). */
    protected function tracked(string $task, callable $fn): int
    {
        $run = TaskRun::create(['task' => $task, 'status' => 'running', 'started_at' => now()]);
        try {
            $output = $fn();
            $run->update([
                'status' => $output === 'skipped' || (is_array($output) && ($output['skipped'] ?? false)) ? 'skipped' : 'succeeded',
                'finished_at' => now(),
                'output' => is_string($output) ? $output : json_encode($output),
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'output' => mb_substr($e->getMessage(), 0, 4000)]);
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\SesameRun;
use App\Sesame\RunReport;
use Illuminate\Console\Command;

class SesameRunReport extends Command
{
    protected $signature = 'sesame:run:report {run : Run ID} {--save : Store the summary on the run row, which outlives raw data}';

    protected $description = 'Print the simulator-side summary of a run as JSON.';

    public function handle(): int
    {
        $run = SesameRun::findOrFail($this->argument('run'));
        $summary = RunReport::summary($run);
        if ($this->option('save')) {
            $run->forceFill(['summary' => $summary])->save();
        }
        $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

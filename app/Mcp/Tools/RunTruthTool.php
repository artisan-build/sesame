<?php

namespace App\Mcp\Tools;

use App\Models\SesameRun;
use App\Sesame\RunReport;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('run_truth')]
#[Description('Read bounded ground truth: recipients, drawn outcomes and delivery state per message, ordered by message ID. Data is content-classified, not metadata. Follow next until null; raw messages expire after seven days.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class RunTruthTool extends SesameTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    public function handle(Request $request): Response
    {
        $input = $this->validate($request, [
            'run_id' => ['required', 'uuid'],
            'after' => ['sometimes', 'string', 'max:80'],
            'limit' => ['sometimes', 'integer:strict', 'between:1,100'],
        ]);
        $run = SesameRun::find($input['run_id']);

        return $run === null ? Response::error('Run not found.') : Response::json([
            'run_id' => $run->id,
            'seed' => $run->seed,
            ...RunReport::truth($run, $input['after'] ?? null, $input['limit'] ?? 25),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->description('Run ID returned by runs.')->required(),
            'after' => $schema->string()->description('Message ID from next on the previous page.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Models\SesameAccount;
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

#[Name('accounts')]
#[Description('List simulated SES accounts and their current runs, without access keys, signing keys, truth tokens or webhook credentials. Cursor-paginated by account ID.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class AccountsTool extends SesameTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    public function handle(Request $request): Response
    {
        $input = $this->validate($request, [
            'after' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer:strict', 'between:1,100'],
        ]);
        $limit = $input['limit'] ?? 25;
        $accounts = SesameAccount::query()
            ->when(isset($input['after']), fn ($q) => $q->where('id', '>', $input['after']))
            ->orderBy('id')->limit($limit + 1)
            ->get(['id', 'name', 'preset', 'max_send_rate', 'sending_paused', 'subscription_status', 'current_run_id']);
        $page = $accounts->take($limit)->values();

        return Response::json([
            'accounts' => $page->toArray(),
            'next' => $accounts->count() > $limit ? $page->last()?->id : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'after' => $schema->string()->description('Account ID from next on the previous page.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
        ];
    }
}

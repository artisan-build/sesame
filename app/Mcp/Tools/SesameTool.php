<?php

namespace App\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

abstract class SesameTool extends Tool
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validate(Request $request, array $rules): array
    {
        resolve(ActingPrincipalResolver::class)->require(request());

        if (array_diff(array_keys($request->all()), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['arguments' => 'Unknown argument.']);
        }

        return $request->validate($rules);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Services\DeepSeekService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:tag-properties')]
#[Description('Generate AI tags for existing properties that do not have tags')]
class TagProperties extends Command
{
    public function handle(DeepSeekService $deepSeek): int
    {
        if (empty(config('services.deepseek.api_key'))) {
            $this->components->warn('DeepSeek is not configured; existing properties were left unchanged.');

            return self::SUCCESS;
        }

        $taggedCount = 0;
        $failedCount = 0;

        Property::query()
            ->where(function ($query): void {
                $query->whereNull('ai_tags')
                    ->orWhereJsonLength('ai_tags', 0);
            })
            ->orderBy('id')
            ->chunkById(50, function ($properties) use ($deepSeek, &$taggedCount, &$failedCount): void {
                foreach ($properties as $property) {
                    $result = $deepSeek->tagProperty($property->title, $property->description);

                    if ($result['success'] && ! empty($result['tags'])) {
                        $property->forceFill(['ai_tags' => $result['tags']])->save();
                        $taggedCount++;
                    } else {
                        $failedCount++;
                    }
                }
            });

        $this->components->info("Tagged {$taggedCount} properties; {$failedCount} were skipped.");

        return self::SUCCESS;
    }
}

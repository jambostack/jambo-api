<?php

namespace App\Tests\Service\ExportImport;

use App\Dto\ConflictItem;
use App\Service\ExportImport\ConflictResolver;
use PHPUnit\Framework\TestCase;

class ConflictResolverTest extends TestCase
{
    public function testApplyStrategyOverwrite(): void
    {
        $resolver = new ConflictResolver();
        $conflicts = [
            ConflictItem::create('collection', 'articles', '', 'abc-123'),
            ConflictItem::create('content_entry', 'articles/en', 'def-456', 'def-456'),
        ];
        $resolved = $resolver->applyStrategy($conflicts, 'overwrite');
        foreach ($resolved as $item) {
            $this->assertSame('overwrite', $item->chosenAction);
        }
    }

    public function testApplyStrategySkip(): void
    {
        $resolver = new ConflictResolver();
        $conflicts = [ConflictItem::create('collection', 'articles', '', 'abc-123')];
        $resolved = $resolver->applyStrategy($conflicts, 'skip');
        $this->assertSame('skip', $resolved[0]->chosenAction);
    }

    public function testApplyStrategyNewUuids(): void
    {
        $resolver = new ConflictResolver();
        $conflicts = [ConflictItem::create('content_entry', 'page/fr', 'old-1', 'old-1')];
        $resolved = $resolver->applyStrategy($conflicts, 'new_uuids');
        $this->assertSame('new_uuid', $resolved[0]->chosenAction);
    }

    public function testHasConflicts(): void
    {
        $resolver = new ConflictResolver();
        $this->assertTrue($resolver->hasConflicts([ConflictItem::create('media', 'img.jpg', 'a', 'a')]));
        $this->assertFalse($resolver->hasConflicts([]));
    }
}

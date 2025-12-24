<?php

namespace Tests\Unit\Models;

use App\Models\Item;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('uuid is generated on creation', function () {
    $tag = Tag::factory()->create();

    expect($tag->id)->not->toBeNull()
        ->and(Str::isUuid($tag->id))->toBeTrue();
});

test('fillable attributes', function () {
    $tag = new Tag;

    expect($tag->getFillable())->toBe([
        'name',
        'description',
    ]);
});

test('incrementing is false', function () {
    $tag = new Tag;

    expect($tag->getIncrementing())->toBeFalse();
});

test('key type is string', function () {
    $tag = new Tag;

    expect($tag->getKeyType())->toBe('string');
});

test('factory creates valid tag', function () {
    $tag = Tag::factory()->create();

    $this->assertDatabaseHas('tags', [
        'id' => $tag->id,
        'name' => $tag->name,
    ]);
});

test('factory creates tag with optional description', function () {
    // Create tag with description
    $tagWithDescription = Tag::factory()->create([
        'description' => 'Test description',
    ]);

    expect($tagWithDescription->description)->not->toBeNull()
        ->and($tagWithDescription->description)->toBe('Test description');

    // Create tag without description
    $tagWithoutDescription = Tag::factory()->create([
        'description' => null,
    ]);

    expect($tagWithoutDescription->description)->toBeNull();
});

test('creates with minimal attributes', function () {
    $tag = Tag::factory()->create([
        'name' => 'Test Tag',
        'description' => null,
    ]);

    $this->assertDatabaseHas('tags', [
        'id' => $tag->id,
        'name' => 'Test Tag',
    ]);
    expect($tag->description)->toBeNull();
});

test('updates attributes', function () {
    $tag = Tag::factory()->create();
    $originalId = $tag->id;

    $tag->update([
        'name' => 'Updated Name',
        'description' => 'Updated Description',
    ]);

    expect($tag->id)->toBe($originalId)
        ->and($tag->name)->toBe('Updated Name')
        ->and($tag->description)->toBe('Updated Description');

    $this->assertDatabaseHas('tags', [
        'id' => $originalId,
        'name' => 'Updated Name',
        'description' => 'Updated Description',
    ]);
});

test('deletes tag', function () {
    $tag = Tag::factory()->create();
    $tagId = $tag->id;

    $tag->delete();

    $this->assertDatabaseMissing('tags', [
        'id' => $tagId,
    ]);
});

test('tag has items relationship', function () {
    $tag = new Tag;

    expect($tag->items())->toBeInstanceOf(BelongsToMany::class)
        ->and($tag->items)->toBeInstanceOf(Collection::class);
});

test('tag can have many items', function () {
    $tag = Tag::factory()->create();
    $items = Item::factory()->count(3)->create();

    $tag->items()->attach($items);

    expect($tag->items)->toHaveCount(3);
    foreach ($items as $item) {
        expect($tag->items->contains($item))->toBeTrue();
    }
});

test('items can be detached from tag', function () {
    $tag = Tag::factory()->create();
    $items = Item::factory()->count(3)->create();

    $tag->items()->attach($items);
    expect($tag->items)->toHaveCount(3);

    $tag->items()->detach($items->first());
    $tag->refresh();

    expect($tag->items)->toHaveCount(2)
        ->and($tag->items->contains($items->first()))->toBeFalse()
        ->and($tag->items->contains($items[1]))->toBeTrue()
        ->and($tag->items->contains($items[2]))->toBeTrue();
});

test('deleting tag doesnt delete linked items', function () {
    $tag = Tag::factory()->create();
    $item = Item::factory()->create();

    $tag->items()->attach($item);
    $itemId = $item->id;
    $tagId = $tag->id;

    $tag->delete();

    $this->assertDatabaseMissing('tags', ['id' => $tagId]);
    $this->assertDatabaseHas('items', ['id' => $itemId]);
    $this->assertDatabaseMissing('item_tag', [
        'item_id' => $itemId,
        'tag_id' => $tagId,
    ]);
});

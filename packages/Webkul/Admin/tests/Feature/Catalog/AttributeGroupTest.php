<?php

use Webkul\Attribute\Models\AttributeGroup;
use Webkul\Core\Models\Locale;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

it('should returns the Attribute group index page', function () {
    $this->loginAsAdmin();

    $response = get(route('admin.catalog.attribute.groups.index'));

    $response->assertStatus(200)->assertSeeTextInOrder([
        trans('admin::app.catalog.attribute-groups.index.title'),
    ]);
});

it('should create Attribute group', function () {
    $this->loginAsAdmin();

    $response = postJson(route('admin.catalog.attribute.groups.store'), [
        'code' => 'other',
    ]);

    $this->assertDatabaseHas($this->getFullTableName(AttributeGroup::class), [
        'code' => 'other',
    ]);

    $response->assertSessionHas('success');
});

it('should show the create attribute group form', function () {
    $this->loginAsAdmin();

    $response = get(route('admin.catalog.attribute.groups.create'));

    $response->assertStatus(200)
        ->assertSeeText(trans('admin::app.catalog.attribute-groups.create.title'));
});

it('should returns the Attribute group edit page', function () {
    $this->loginAsAdmin();

    $attributeGroup = AttributeGroup::factory()->create();

    $response = get(route('admin.catalog.attribute.groups.edit', ['id' => $attributeGroup->id]));

    $response->assertStatus(200);
});

it('should update Attribute group', function () {
    $this->loginAsAdmin();

    $attributeGroup = AttributeGroup::factory()->create();

    $locales = Locale::where('status', 1)->limit(3);
    $data = [];
    foreach ($locales as $locale) {
        $data[$locale] = ['name' => $locale.fake()->word()];
    }

    $response = putJson(route('admin.catalog.attribute.groups.update', $attributeGroup->id), [
        'id'   => $attributeGroup->id,
        'code' => $attributeGroup->code,
        $data,
    ]);

    $response->assertSessionHas('success');
});

it('should give validation message when code is not unique', function () {
    $this->loginAsAdmin();

    $attributeGroup = AttributeGroup::factory()->create();

    $response = postJson(route('admin.catalog.attribute.groups.store', $attributeGroup->id), [
        'code' => $attributeGroup->code,
    ]);

    $response->assertJsonValidationErrorFor('code');
});

it('should not update the code of attribute group', function () {
    $this->loginAsAdmin();

    $attributeGroup = AttributeGroup::factory()->create(['code' => 'testAttributeGroup']);

    putJson(route('admin.catalog.attribute.groups.update', $attributeGroup->id), [
        'id'   => $attributeGroup->id,
        'code' => 'testAttribute',
    ]);

    $this->assertDatabaseHas($this->getFullTableName(AttributeGroup::class), [
        'id'   => $attributeGroup->id,
        'code' => $attributeGroup->code,
    ]);
});

it('should delete the attribute group', function () {
    $this->loginAsAdmin();

    $attributeGroup = AttributeGroup::factory()->create();

    $this->delete(route('admin.catalog.attribute.groups.delete', $attributeGroup->id))
        ->assertJsonPath('message', trans('admin::app.catalog.attribute-groups.delete-success'));

    $this->assertDatabaseMissing($this->getFullTableName(AttributeGroup::class), [
        'id' => $attributeGroup->id,
    ]);
});
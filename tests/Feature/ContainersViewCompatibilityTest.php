<?php

namespace Tests\Feature;

use Tests\TestCase;

class ContainersViewCompatibilityTest extends TestCase
{
    public function test_legacy_container_modal_alias_view_exists(): void
    {
        $this->assertTrue(view()->exists('livewire.infrastructure.partials.modal-inspect'));
    }
}

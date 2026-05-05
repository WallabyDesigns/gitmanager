<?php

namespace Tests\Unit;

use App\Support\SupportMessageView;
use Tests\TestCase;

class SupportMessageViewTest extends TestCase
{
    public function test_support_message_view_exposes_translation_and_original_metadata(): void
    {
        $view = SupportMessageView::fromEntry([
            'message' => 'こんにちは',
            'translated_message' => 'Hello',
            'source_locale' => 'ja',
        ], 'en');

        $this->assertTrue($view['has_translation']);
        $this->assertSame('Japanese', $view['source_label']);
        $this->assertSame('English', $view['target_label']);
        $this->assertStringContainsString('こんにちは', $view['original_html']);
        $this->assertStringContainsString('Hello', $view['translated_html']);
    }
}

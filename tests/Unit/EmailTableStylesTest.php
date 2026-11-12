<?php

namespace Tests\Unit;

use App\Support\EmailTableStyles;
use PHPUnit\Framework\TestCase;

class EmailTableStylesTest extends TestCase
{
    public function test_bordered_table_gets_inline_borders_on_every_cell(): void
    {
        $html = EmailTableStyles::inline(
            '<table class="table table-bordered"><tbody>'
            . '<tr><td><p><b>Salary</b></p></td><td><span>£15</span></td></tr>'
            . '<tr><td>Timing</td><td>Days</td></tr>'
            . '</tbody></table>'
        );

        $this->assertMatchesRegularExpression('/<table class="table table-bordered" style="[^"]*border-collapse:collapse/', $html);
        $this->assertSame(4, substr_count($html, 'style="border:1px solid #d9dee5;padding:8px 12px'));
        $this->assertStringContainsString('<p style="margin:0;"><b>Salary</b></p>', $html);
        $this->assertStringContainsString('<tbody>', $html);
    }

    public function test_existing_inline_style_is_kept_and_wins(): void
    {
        $html = EmailTableStyles::inline('<table class="table-bordered"><tr><td style="padding:1px;">A</td></tr></table>');

        $this->assertMatchesRegularExpression('/<td style="border:1px solid #d9dee5;[^"]*padding:1px;">/', $html);
    }

    public function test_layout_tables_and_outside_content_are_unchanged(): void
    {
        $layout = '<table role="presentation" style="width:100%"><tr><td style="padding:20px"><p>Before</p></td></tr></table>';
        $vacancyTable = '<table class="table table-bordered"><tr><td>A</td></tr></table>';

        $result = EmailTableStyles::inline($layout . $vacancyTable . '<p>After</p>');

        $this->assertStringContainsString('<td style="padding:20px"><p>Before</p></td>', $result);
        $this->assertStringEndsWith('<p>After</p>', $result);
        $this->assertSame('<p>No table</p>', EmailTableStyles::inline('<p>No table</p>'));
        $this->assertNull(EmailTableStyles::inline(null));
    }
}

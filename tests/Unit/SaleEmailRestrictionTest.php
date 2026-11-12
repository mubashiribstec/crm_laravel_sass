<?php

namespace Tests\Unit;

use App\Support\SaleEmailRestriction;
use Horsefly\Sale;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SaleEmailRestrictionTest extends TestCase
{
    public function test_job_source_ten_is_restricted(): void
    {
        $this->assertTrue(SaleEmailRestriction::applies(new Sale(['job_source_id' => 10])));
        $this->assertTrue(SaleEmailRestriction::applies(new Sale(['job_source_id' => '10'])));
    }

    public function test_other_sales_are_not_restricted(): void
    {
        $this->assertFalse(SaleEmailRestriction::applies(new Sale(['job_source_id' => 3, 'salary' => '<p>12 per hour</p>'])));
        $this->assertFalse(SaleEmailRestriction::applies(new Sale()));
    }

    #[DataProvider('textColumns')]
    public function test_keyword_in_any_text_column_is_restricted(string $column): void
    {
        $this->assertTrue(SaleEmailRestriction::applies(new Sale(['job_source_id' => 3, $column => 'Client: Hayaibu Care'])));
    }

    public function test_keyword_split_by_editor_html_is_matched(): void
    {
        $this->assertTrue(SaleEmailRestriction::applies(new Sale(['job_source_id' => 3, 'benefits' => '<p>Hay<b>aibu</b></p>'])));
    }

    public static function textColumns(): array
    {
        return array_map(fn ($column) => [$column], SaleEmailRestriction::TEXT_COLUMNS);
    }
}

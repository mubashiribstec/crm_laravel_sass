<?php

namespace Tests\Unit;

use App\Http\Controllers\ApplicantController;
use DOMDocument;
use Horsefly\Applicant;
use Horsefly\Sale;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use ZipArchive;

class CvQuestionnaireDocumentTest extends TestCase
{
    private ?string $generatedPath = null;

    protected function tearDown(): void
    {
        if ($this->generatedPath !== null) {
            File::delete($this->generatedPath);
        }

        parent::tearDown();
    }

    public static function categories(): array
    {
        return [
            'Nurse' => [[
                'Category' => 'Nurse',
                '5. NMC PIN Status' => 'Active & valid',
                '7. Clinical Skills' => 'Wound Care, Dementia Care',
                '18. Qualification' => 'Health & Social Care <Level 3>',
            ]],
            'Non Nurse' => [[
                'Category' => 'Non Nurse',
                '13. Years of Experience as a Carer' => '<1 year & counting',
                '18. Qualification' => 'NVQ Level 2 & 3',
            ]],
            'Nursery' => [[
                'Category' => 'Nursery',
                '13. Years of Experience as a Nursery' => "2 years\n& 3 months",
                '18. Qualification' => 'CACHE "Level 3" & Paediatric First Aid',
            ]],
        ];
    }

    #[DataProvider('categories')]
    public function test_special_characters_produce_a_valid_document(array $questionnaire): void
    {
        $applicant = new Applicant();
        $applicant->applicant_name = 'Test & Applicant';

        $method = new ReflectionMethod(ApplicantController::class, 'createCvQuestionnaireDocument');
        $relativePath = $method->invoke(app(ApplicantController::class), $questionnaire, $applicant, new Sale());
        $this->generatedPath = public_path($relativePath);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->generatedPath) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        libxml_use_internal_errors(true);
        $this->assertTrue((new DOMDocument())->loadXML($xml), 'word/document.xml is not valid XML');
        $this->assertStringContainsString('TEST &amp; APPLICANT', $xml);
    }
}

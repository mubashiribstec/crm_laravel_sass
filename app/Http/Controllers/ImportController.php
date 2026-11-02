<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Horsefly\Sale;
use Horsefly\Office;
use Horsefly\Unit;
use Horsefly\Applicant;
use Horsefly\Message;
use Horsefly\ApplicantNote;
use Horsefly\ModuleNote;
use Horsefly\CrmRejectedCv;
use Horsefly\IPAddress;
use Horsefly\Interview;
use Horsefly\Region;
use Horsefly\SentEmail;
use Horsefly\JobTitle;
use Horsefly\JobSource;
use Horsefly\CVNote;
use Horsefly\History;
use Horsefly\JobCategory;
use Horsefly\ApplicantPivotSale;
use Horsefly\NotesForRangeApplicant;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;
use Horsefly\Audit;
use Horsefly\CrmNote;
use Horsefly\QualityNotes;
use Horsefly\RevertStage;
use Horsefly\SaleDocument;
use Horsefly\SaleNote;
use Illuminate\Support\Str;
use League\Csv\Reader;
use Spatie\PdfToText\Pdf;
use PhpOffice\PhpWord\IOFactory;


class ImportController extends Controller
{
    public $timestamps = false;  // Disables automatic timestamps

    public function __construct()
    {
        $this->middleware('permission:applicant-import,office-import,unit-import,sale-import,administrator-user-import')->only(['importIndex']);
        $this->middleware('permission:office-import')->only(['officesImport']);
        $this->middleware('permission:unit-import')->only(['unitsImport']);
        $this->middleware('permission:applicant-import')->only(['applicantsImport', 'applicantsProcessFile']);
        $this->middleware('permission:sale-import')->only(['salesImport']);
        $this->middleware('permission:administrator-user-import')->only(['usersImport']);
        // One-off history/data-migration imports that rewrite CRM history. No screen links to
        // them, and they had no permission check: admins only.
        $this->middleware('permission:administrator-user-import')->only([
            'messagesImport', 'applicantNotesImport', 'applicantPivotSaleImport', 'notesRangeForPivotSaleImport',
            'auditsImport', 'crmNotesImport', 'crmRejectedCvImport', 'cvNotesImport', 'historyImport',
            'interviewImport', 'ipAddressImport', 'moduleNotesImport', 'qualityNotesImport', 'regionsImport',
            'revertStageImport', 'saleDocumentsImport', 'saleNotesImport', 'sentEmailDataImport',
        ]);
    }

    public function importIndex()
    {
        return view('settings.import');
    }
    public function officesImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            $startTime = microtime(true);
            Log::channel('import')->info('🔹 [Offices Import] Starting CSV import process...');

            $file = $request->file('csv_file');
            $filename = $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Convert file to UTF-8 if needed
            $content = file_get_contents($filePath);
            $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
            if ($encoding != 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                file_put_contents($filePath, $content);
            }

            // Load CSV with League\CSV
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0); // First row is header
            $csv->setDelimiter(','); // Ensure correct delimiter
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $records = $csv->getRecords();
            $headers = $csv->getHeader();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            // Count total rows
            $totalRows = iterator_count($records);
            Log::channel('import')->info("📊 Total offices records in CSV: {$totalRows}");

            $processedData = [];
            $successfulRows = 0;
            $failedRows = [];
            $rowIndex = 1; // Start from 1 to skip header

            Log::channel('import')->info('🚀 Starting offices row-by-row processing...');

            foreach ($records as $row) {
                $rowIndex++;
                try {
                    // Skip empty rows
                    if (empty(array_filter($row))) {
                        Log::channel('import')->warning("Row {$rowIndex}: Empty row , skipping");
                        continue;
                    }

                    // Pad or truncate row to match header count
                    $row = array_pad($row, $expectedColumnCount, null);
                    $row = array_slice($row, 0, $expectedColumnCount);

                    // Combine headers with row data
                    $row = array_combine($headers, $row);
                    if ($row == false) {
                        Log::channel('import')->warning("Skipped row {$rowIndex} due to header mismatch.", ['row' => $row]);
                        $failedRows[] = ['row' => $rowIndex, 'error' => 'Header mismatch'];
                        continue;
                    }

                    // Clean and normalize data
                    $row = array_map(function ($value) {
                        if (is_string($value)) {
                            // Remove extra whitespace and line breaks
                            $value = preg_replace('/\s+/', ' ', trim($value));
                            // Remove non-ASCII characters
                            $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                        }
                        return $value;
                    }, $row);

                    // Date preprocessing
                    $preprocessDate = function ($dateString, $field, $rowIndex) {
                        if (empty($dateString) || !is_string($dateString)) {
                            return null;
                        }

                        // Fix malformed numeric formats (e.g., 1122024 1230)
                        if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s?(\d{1,2})(\d{2})?$/', $dateString, $matches)) {
                            $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} " . ($matches[4] ?? '00') . ":" . ($matches[5] ?? '00');
                            Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                            return $fixedDate;
                        }

                        return $dateString;
                    };

                    // Parse dates (corrected format order)
                    $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                        if (empty($dateString)) {
                            return null;
                        }

                        $formats = [
                            'Y-m-d H:i:s',
                            'Y-m-d H:i',
                            'Y-m-d',
                            'm/d/Y H:i',  // US format first
                            'm/d/Y H:i:s',
                            'm/d/Y',
                            'd/m/Y H:i',
                            'd/m/Y H:i:s',
                            'd/m/Y',
                            'j F Y',
                            'j F Y H:i',
                            'j F Y g:i A',
                            'd F Y',
                            'd F Y g:i A'
                        ];

                        foreach ($formats as $format) {
                            try {
                                $dt = Carbon::createFromFormat($format, $dateString);
                                // Log::channel('import')->debug("Row {$rowIndex}: Parsed {$field} '{$dateString}' with format '{$format}'");
                                return $dt->format('Y-m-d H:i:s');
                            } catch (\Exception $e) {
                                // continue
                            }
                        }


                        Log::channel('import')->debug("Row {$rowIndex}: All formats failed for {$field} '{$dateString}'");
                        return null;
                    };

                    // Normalizer (keeps created_at & updated_at null if invalid)
                    $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                        $value = trim((string)($value ?? ''));

                        // Skip invalid placeholders
                        if (
                            $value == '' ||
                            in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                        ) {
                            return null;
                        }

                        try {
                            $value = $preprocessDate($value, $field, $rowIndex);
                            $parsed = $parseDate($value, $rowIndex, $field);

                            if (!$parsed || strtotime($parsed) == false) {
                                throw new \Exception("Invalid date format: '{$value}'");
                            }

                            return $parsed;
                        } catch (\Exception $e) {
                            Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                            return null;
                        }
                    };

                    $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                    $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);


                    // Clean postcode (extract valid postcode, e.g., DN16 2AB)
                    $cleanPostcode = '0';
                    if (!empty($row['office_postcode'])) {
                        preg_match('/[A-Z]{1,2}[0-9]{1,2}\s*[0-9][A-Z]{2}/i', $row['office_postcode'], $matches);
                        $cleanPostcode = $matches[0] ?? substr(trim($row['office_postcode']), 0, 8);
                    }

                    $names = array_map('trim', explode(',', $row['office_contact_name'] ?? ''));
                    $emails = array_map('trim', explode(',', $row['office_email'] ?? ''));
                    $phones = array_map('trim', explode(',', $row['office_contact_phone'] ?? ''));
                    $landlines = array_map('trim', explode(',', $row['office_contact_landline'] ?? ''));

                    $contacts = [];
                    $maxContacts = max(count($names), count($emails), count($phones), count($landlines));

                    for ($i = 0; $i < $maxContacts; $i++) {
                        $contacts[] = [
                            'contact_name'     => $names[$i] ?? 'N/A',
                            'contact_email'    => $emails[$i] ?? 'N/A',
                            'contact_phone'    => isset($phones[$i]) ? preg_replace('/[^0-9]/', '', $phones[$i]) : '0',
                            'contact_landline' => isset($landlines[$i]) ? preg_replace('/[^0-9]/', '', $landlines[$i]) : '0',
                            'contact_note'     => null,
                            'created_at' => $createdAt,
                            'updated_at' => $updatedAt,
                        ];
                    }

                    $lat = (is_numeric($row['lat']) ? (float) $row['lat'] : null);
                    $lng = (is_numeric($row['lng']) ? (float) $row['lng'] : null);

                    if ($lat === null || $lng === null || $lat === 0.0000 || $lng === 0.0000 || strtolower((string)$lat) === 'null' || strtolower((string)$lng) === 'null') {
                        $postcode_query = strlen($cleanPostcode) < 6
                            ? DB::table('outcodepostcodes')->where('outcode', $cleanPostcode)->first()
                            : DB::table('postcodes')->where('postcode', $cleanPostcode)->first();

                        if ($postcode_query) {
                            $lat = $postcode_query->lat ?? 0.0000;
                            $lng = $postcode_query->lng ?? 0.0000;
                        } else {
                            Log::channel('import')->warning("Row {$rowIndex}: No lat/lng found for postcode '{$cleanPostcode}', defaulting to 0.0000,0.0000");
                            $lat = 0.0000;
                            $lng = 0.0000;
                        }
                    }

                    // Final safety net — always ensure numeric values
                    $lat = (float)($lat ?? 0.0000);
                    $lng = (float)($lng ?? 0.0000);


                    // Keep whitespace intact
                    if (strlen($cleanPostcode) == 8) {
                        $exists = DB::table('postcodes')->where('postcode', $cleanPostcode)->exists();

                        if (!$exists) {
                            DB::table('postcodes')->insert([
                                'postcode'   => $cleanPostcode,
                                'lat'        => $lat,
                                'lng'        => $lng,
                                'created_at' => null,
                                'updated_at' => null,
                            ]);
                        }
                    } elseif (strlen($cleanPostcode) < 6) {
                        $exists = DB::table('outcodepostcodes')->where('outcode', $cleanPostcode)->exists();

                        if (!$exists) {
                            DB::table('outcodepostcodes')->insert([
                                'outcode'    => $cleanPostcode,
                                'lat'        => $lat,
                                'lng'        => $lng,
                                'created_at' => null,
                                'updated_at' => null,
                            ]);
                        }
                    }

                    $processedRow = [
                        'id' => $row['id'] ?? null,
                        'office_uid' => md5($row['id']),
                        'user_id' => $row['user_id'] ?? null,
                        'office_name' => preg_replace('/\s+/', ' ', trim($row['office_name'] ?? '')),
                        'office_type' => 'head_office',
                        'office_website' => $row['office_website'] ?? null,
                        'office_notes' => $row['office_notes'] ?? null,
                        'office_lat' => $lat,
                        'office_lng' => $lng,
                        'office_postcode' => $cleanPostcode,
                        'status' => isset($row['status']) && strtolower($row['status']) == 'active' ? 1 : 0,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                        'contacts' => $contacts
                    ];

                    $processedData[] = $processedRow;
                } catch (\Throwable $e) {
                    $failedRows[] = ['row' => $rowIndex, 'error' => $e->getMessage()];
                    Log::channel('import')->error("Row {$rowIndex}: Failed processing - {$e->getMessage()}");
                }
            }

            Log::channel('import')->info("✅ Processed {$rowIndex} rows. Total valid: " . count($processedData) . ", Failed: " . count($failedRows));

            foreach (array_chunk($processedData, 100) as $chunkIndex => $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows, $chunkIndex) {
                        foreach ($chunk as $index => $row) {
                            $rowIndex = ($chunkIndex * 100) + $index + 2;
                            try {
                                $contacts = $row['contacts'] ?? [];
                                unset($row['contacts']); // remove contacts before inserting to offices table

                                Office::withoutTimestamps(function () use ($row) {
                                    Office::updateOrCreate(['id' => $row['id']], $row);
                                });
                                Log::channel('import')->info("Office created/updated for row " . ($index + 1) . ": ID={$row['id']}");

                                // Optional: remove old contacts for this office to avoid duplicates
                                DB::table('contacts')
                                    ->where('contactable_type', 'Horsefly\\Office')
                                    ->where('contactable_id', $row['id'])
                                    ->delete();


                                // Insert contacts if available
                                if (!empty($contacts)) {
                                    $contactRows = [];
                                    foreach ($contacts as $contactData) {
                                        $contactData['contactable_id'] = $row['id'];
                                        $contactData['contactable_type'] = 'Horsefly\Office';
                                        $contactData['created_at'] = $contactData['created_at'] ?? null;
                                        $contactData['updated_at'] = $contactData['updated_at'] ?? null;
                                        $contactRows[] = $contactData;
                                    }

                                    if (!empty($contactRows)) {
                                        DB::table('contacts')->insert($contactRows);
                                        Log::channel('import')->info("Contacts inserted for office ID {$row['id']}");
                                    }
                                }

                                $successfulRows++;
                            } catch (\Throwable $e) {
                                $failedRows[] = [
                                    'row' => $rowIndex,
                                    'error' => $e->getMessage(),
                                ];
                                Log::channel('import')->error("Row {$rowIndex}: DB insert/update failed for {$row['id']} - {$e->getMessage()}");
                            }
                        }
                    });
                    Log::channel('import')->info("💾 Processed chunk #{$chunkIndex} ({$successfulRows} total)");
                } catch (\Throwable $e) {
                    $failedRows[] = ['chunk' => $chunkIndex, 'error' => $e->getMessage()];
                    Log::channel('import')->error("Chunk {$chunkIndex}: Transaction failed - {$e->getMessage()}");
                }
            }

            // Cleanup
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("🗑️ Deleted temporary file: {$filePath}");
            }

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            Log::channel('import')->info("🏁 [Offices Import Summary]");
            Log::channel('import')->info("• Total rows read: {$totalRows}");
            Log::channel('import')->info("• Successfully imported: {$successfulRows}");
            Log::channel('import')->info("• Failed rows: " . count($failedRows));
            Log::channel('import')->info("• Time taken: {$duration} seconds");


            return response()->json([
                'message' => 'CSV import completed successfully!',
                'summary' => [
                    'total_rows' => $totalRows,
                    'successful_rows' => $successfulRows,
                    'failed_rows' => count($failedRows),
                    'failed_details' => $failedRows,
                    'duration_seconds' => $duration,
                ],
            ], 200);
        } catch (\Exception $e) {
            if (file_exists($filePath ?? '')) {
                unlink($filePath);
                Log::channel('import')->info("🗑️ Deleted temporary file after error: {$filePath}");
            }
            Log::channel('import')->error("💥 Import failed: {$e->getMessage()}\nStack trace: {$e->getTraceAsString()}");
            return response()->json([
                'error' => 'CSV import failed: ' . $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }
    public function unitsImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            $startTime = microtime(true);
            Log::channel('import')->info('🔹 [Units Import] Starting CSV import process...');

            $file = $request->file('csv_file');
            $filename = $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Convert file to UTF-8 if needed
            $content = file_get_contents($filePath);
            $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
            if ($encoding != 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                file_put_contents($filePath, $content);
            }

            // Load CSV with League\CSV
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0); // First row is header
            $csv->setDelimiter(','); // Ensure correct delimiter
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $records = $csv->getRecords();
            $headers = $csv->getHeader();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            // Count total rows
            $totalRows = iterator_count($records);
            Log::channel('import')->info("📊 Total units records in CSV: {$totalRows}");

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1; // Start from 1 to skip header

            Log::channel('import')->info('🚀 Starting units row-by-row processing...');

            foreach ($records as $row) {
                $rowIndex++;
                try {
                    // Skip empty rows
                    if (empty(array_filter($row))) {
                        Log::channel('import')->warning("Row {$rowIndex}: Empty row , skipping");
                        continue;
                    }

                    // Pad or truncate row to match header count
                    $row = array_pad($row, $expectedColumnCount, null);
                    $row = array_slice($row, 0, $expectedColumnCount);

                    // Combine headers with row data
                    $row = array_combine($headers, $row);
                    if ($row == false) {
                        Log::channel('import')->warning("Skipped row {$rowIndex} due to header mismatch.", ['row' => $row]);
                        $failedRows[] = ['row' => $rowIndex, 'error' => 'Header mismatch'];
                        continue;
                    }

                    // Clean and normalize data
                    $row = array_map(function ($value) {
                        if (is_string($value)) {
                            // Remove extra whitespace and line breaks
                            $value = preg_replace('/\s+/', ' ', trim($value));
                            // Remove non-ASCII characters
                            $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                        }
                        return $value;
                    }, $row);

                    // Date preprocessing
                    $preprocessDate = function ($dateString, $field, $rowIndex) {
                        if (empty($dateString) || !is_string($dateString)) {
                            return null;
                        }

                        // Fix malformed numeric formats (e.g., 1122024 1230)
                        if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s?(\d{1,2})(\d{2})?$/', $dateString, $matches)) {
                            $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} " . ($matches[4] ?? '00') . ":" . ($matches[5] ?? '00');
                            Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                            return $fixedDate;
                        }

                        return $dateString;
                    };

                    // Parse dates (corrected format order)
                    $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                        if (empty($dateString)) {
                            return null;
                        }

                        $formats = [
                            'Y-m-d H:i:s',
                            'Y-m-d H:i',
                            'Y-m-d',
                            'm/d/Y H:i',  // US format first
                            'm/d/Y H:i:s',
                            'm/d/Y',
                            'd/m/Y H:i',
                            'd/m/Y H:i:s',
                            'd/m/Y',
                            'j F Y',
                            'j F Y H:i',
                            'j F Y g:i A',
                            'd F Y',
                            'd F Y g:i A'
                        ];

                        foreach ($formats as $format) {
                            try {
                                $dt = Carbon::createFromFormat($format, $dateString);
                                // Log::channel('import')->debug("Row {$rowIndex}: Parsed {$field} '{$dateString}' with format '{$format}'");
                                return $dt->format('Y-m-d H:i:s');
                            } catch (\Exception $e) {
                                // continue
                            }
                        }

                        Log::channel('import')->debug("Row {$rowIndex}: All formats failed for {$field} '{$dateString}'");
                        return null;
                    };

                    // Normalizer (keeps created_at & updated_at null if invalid)
                    $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                        $value = trim((string)($value ?? ''));

                        // Skip invalid placeholders
                        if (
                            $value == '' ||
                            in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                        ) {
                            return null;
                        }

                        try {
                            $value = $preprocessDate($value, $field, $rowIndex);
                            $parsed = $parseDate($value, $rowIndex, $field);

                            if (!$parsed || strtotime($parsed) == false) {
                                throw new \Exception("Invalid date format: '{$value}'");
                            }

                            return $parsed;
                        } catch (\Exception $e) {
                            Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                            return null;
                        }
                    };

                    $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                    $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                    // Clean postcode (extract valid postcode, e.g., DN16 2AB)
                    $cleanPostcode = '0';
                    if (!empty($row['unit_postcode'])) {
                        preg_match('/[A-Z]{1,2}[0-9]{1,2}\s*[0-9][A-Z]{2}/i', $row['unit_postcode'], $matches);
                        $cleanPostcode = $matches[0] ?? substr(trim($row['unit_postcode']), 0, 8);
                    }

                    $office_id = $row['head_office'];

                    if ($office_id == 'Select Office') {
                        $office_id = 0;
                    }

                    $names = array_map('trim', explode(',', $row['contact_name'] ?? ''));
                    $emails = array_map('trim', explode(',', $row['contact_email'] ?? ''));
                    $phones = array_map('trim', explode(',', $row['contact_phone_number'] ?? ''));
                    $landlines = array_map('trim', explode(',', $row['contact_landline'] ?? ''));

                    $contacts = [];
                    $maxContacts = max(count($names), count($emails), count($phones), count($landlines));

                    for ($i = 0; $i < $maxContacts; $i++) {
                        $contacts[] = [
                            'contact_name'     => $names[$i] ?? 'N/A',
                            'contact_email'    => $emails[$i] ?? 'N/A',
                            'contact_phone'    => isset($phones[$i]) ? preg_replace('/[^0-9]/', '', $phones[$i]) : '0',
                            'contact_landline' => isset($landlines[$i]) ? preg_replace('/[^0-9]/', '', $landlines[$i]) : '0',
                            'contact_note'     => null,
                        ];
                    }

                    $lat = (is_numeric($row['lat']) ? (float) $row['lat'] : null);
                    $lng = (is_numeric($row['lng']) ? (float) $row['lng'] : null);

                    if ($lat === null || $lng === null || $lat === 0.0000 || $lng === 0.0000 || strtolower((string)$lat) === 'null' || strtolower((string)$lng) === 'null') {
                        $postcode_query = strlen($cleanPostcode) < 6
                            ? DB::table('outcodepostcodes')->where('outcode', $cleanPostcode)->first()
                            : DB::table('postcodes')->where('postcode', $cleanPostcode)->first();

                        if ($postcode_query) {
                            $lat = $postcode_query->lat ?? 0.0000;
                            $lng = $postcode_query->lng ?? 0.0000;
                        } else {
                            Log::channel('import')->warning("Row {$rowIndex}: No lat/lng found for postcode '{$cleanPostcode}', defaulting to 0.0000,0.0000");
                            $lat = 0.0000;
                            $lng = 0.0000;
                        }
                    }

                    // Final safety net — always ensure numeric values
                    $lat = (float)($lat ?? 0.0000);
                    $lng = (float)($lng ?? 0.0000);

                    // Keep whitespace intact
                    if (strlen($cleanPostcode) == 8) {
                        $exists = DB::table('postcodes')->where('postcode', $cleanPostcode)->exists();

                        if (!$exists) {
                            DB::table('postcodes')->insert([
                                'postcode'   => $cleanPostcode,
                                'lat'        => $lat,
                                'lng'        => $lng,
                                'created_at' => null,
                                'updated_at' => null,
                            ]);
                        }
                    } elseif (strlen($cleanPostcode) < 6) {
                        $exists = DB::table('outcodepostcodes')->where('outcode', $cleanPostcode)->exists();

                        if (!$exists) {
                            DB::table('outcodepostcodes')->insert([
                                'outcode'    => $cleanPostcode,
                                'lat'        => $lat,
                                'lng'        => $lng,
                                'created_at' => null,
                                'updated_at' => null,
                            ]);
                        }
                    }

                    $processedRow = [
                        'id' => $row['id'],
                        'unit_uid' => md5($row['id']),
                        'office_id' => $office_id,
                        'user_id' => $row['user_id'] ?? null,
                        'unit_name' => preg_replace('/\s+/', ' ', trim($row['unit_name'] ?? '')),
                        'unit_website' => $row['website'] ?? null,
                        'unit_notes' => $row['units_notes'] ?? null,
                        'lat' => $lat,
                        'lng' => $lng,
                        'unit_postcode' => $cleanPostcode,
                        'status' => isset($row['status']) && strtolower($row['status']) == 'active' ? 1 : 0,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                        'contacts' => $contacts,
                    ];

                    $processedData[] = $processedRow;
                } catch (\Throwable $e) {
                    $failedRows[] = ['row' => $rowIndex, 'error' => $e->getMessage()];
                    Log::channel('import')->error("Row {$rowIndex}: Failed processing - {$e->getMessage()}");
                }
            }

            Log::channel('import')->info("✅ Processed {$rowIndex} rows. Total valid: " . count($processedData) . ", Failed: " . count($failedRows));

            // Save data to database
            foreach (array_chunk($processedData, 100) as $chunkIndex => $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows, $chunkIndex) {

                        foreach ($chunk as $index => $row) {
                            $rowIndex = ($chunkIndex * 100) + $index + 2;

                            try {
                                // Extract contacts from row safely
                                $contacts = $row['contacts'] ?? [];
                                unset($row['contacts']);

                                // Create or update Unit
                                Unit::withoutTimestamps(function () use ($row) {
                                    Unit::updateOrCreate(
                                        ['id' => $row['id']],   // Match on ID
                                        $row                    // Update rest of the fields
                                    );
                                });


                                // Optional: remove old contacts for this office to avoid duplicates
                                DB::table('contacts')
                                    ->where('contactable_type', 'Horsefly\\Office')
                                    ->where('contactable_id', $row['id'])
                                    ->delete();


                                // Insert contacts if available
                                if (!empty($contacts)) {
                                    $contactRows = [];
                                    foreach ($contacts as $contactData) {
                                        $contactData['contactable_id'] = $row['id'];
                                        $contactData['contactable_type'] = 'Horsefly\Unit';
                                        $contactData['created_at'] = $contactData['created_at'] ?? null;
                                        $contactData['updated_at'] = $contactData['updated_at'] ?? null;
                                        $contactRows[] = $contactData;
                                    }

                                    if (!empty($contactRows)) {
                                        DB::table('contacts')->insert($contactRows);
                                        Log::channel('import')->info("Contacts inserted for office ID {$row['id']}");
                                    }
                                }

                                $successfulRows++;
                            } catch (\Throwable $e) {
                                $failedRows[] = [
                                    'row' => $rowIndex,
                                    'error' => $e->getMessage(),
                                ];

                                Log::channel('import')->error(
                                    "Row {$rowIndex}: Failed for Unit ID {$row['id']} - {$e->getMessage()}"
                                );
                            }
                        }
                    });

                    Log::channel('import')->info("Processed chunk #{$chunkIndex}");
                } catch (\Throwable $e) {
                    $failedRows[] = [
                        'chunk' => $chunkIndex,
                        'error' => $e->getMessage(),
                    ];

                    Log::channel('import')->error(
                        "Chunk {$chunkIndex}: Transaction failed - {$e->getMessage()}"
                    );
                }
            }


            // Cleanup
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("🗑️ Deleted temporary file: {$filePath}");
            }

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            Log::channel('import')->info("🏁 [Units Import Summary]");
            Log::channel('import')->info("• Total rows read: {$totalRows}");
            Log::channel('import')->info("• Successfully imported: {$successfulRows}");
            Log::channel('import')->info("• Failed rows: " . count($failedRows));
            Log::channel('import')->info("• Time taken: {$duration} seconds");

            return response()->json([
                'message' => 'CSV import completed successfully!',
                'summary' => [
                    'total_rows' => $totalRows,
                    'successful_rows' => $successfulRows,
                    'failed_rows' => count($failedRows),
                    'failed_details' => $failedRows,
                    'duration_seconds' => $duration,
                ],
            ], 200);
        } catch (\Exception $e) {
            if (file_exists($filePath ?? '')) {
                unlink($filePath);
                Log::channel('import')->info("🗑️ Deleted temporary file after error: {$filePath}");
            }
            Log::channel('import')->error("💥 Import failed: {$e->getMessage()}\nStack trace: {$e->getTraceAsString()}");
            return response()->json([
                'error' => 'CSV import failed: ' . $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }
    public function applicantsImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            $startTime = microtime(true);
            Log::channel('import')->info('🔹 [Applicant Import] Starting CSV import process...');

            // Store file
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");
            Log::channel('import')->info('📂 File stored at: ' . $filePath);

            // Ensure UTF-8 encoding
            $content = file_get_contents($filePath);
            $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
            if ($encoding != 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                file_put_contents($filePath, $content);
                Log::channel('import')->info("✅ Converted file to UTF-8 from {$encoding}");
            }

            // Load CSV
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            // // Validate headers
            $headers = array_map(function ($h) {
                $h = strtolower(trim($h));
                $h = preg_replace('/\s+/', '_', $h); // remove ALL spaces inside
                return $h;
            }, $csv->getHeader());

            // Normalize required headers
            $requiredHeaders = [
                'id',
                'applicant_u_id',
                'applicant_user_id',
                'job_category',
                'applicant_job_title',
                'job_title_prof',
                'applicant_name',
                'applicant_email',
                'applicant_postcode',
                'applicant_phone',
                'applicant_homePhone',
                'applicant_source',
                'applicant_cv',
                'updated_cv',
                'applicant_notes',
                'applicant_experience',
                'applicant_added_date',
                'applicant_added_time',
                'lat',
                'lng',
                'is_blocked',
                'is_no_job',
                'temp_not_interested',
                'no_response',
                'is_circuit_busy',
                'is_callback_enable',
                'is_in_nurse_home',
                'is_cv_in_quality',
                'is_cv_in_quality_clear',
                'is_CV_sent',
                'is_CV_reject',
                'is_interview_confirm',
                'is_interview_attend',
                'is_in_crm_request',
                'is_in_crm_reject',
                'is_in_crm_request_reject',
                'is_crm_request_confirm',
                'is_crm_interview_attended',
                'is_in_crm_start_date',
                'is_in_crm_invoice',
                'is_in_crm_invoice_sent',
                'is_in_crm_start_date_hold',
                'is_in_crm_paid',
                'is_in_crm_dispute',
                'is_follow_up',
                'is_job_within_radius',
                'have_nursing_home_experience',
                'status',
                'paid_status',
                'paid_timestamp',
                'created_at',
                'updated_at'
            ];

            $normalizedRequired = array_map(function ($h) {
                return preg_replace('/\s+/', '', strtolower($h));
            }, $requiredHeaders);

            // Compare
            $missingHeaders = array_diff($normalizedRequired, $headers);

            if (!empty($missingHeaders)) {
                throw new \Exception('Missing required headers: ' . implode(', ', $missingHeaders));
            }

            // Count total rows
            $records = $csv->getRecords();
            $totalRows = iterator_count($records);
            Log::channel('import')->info("📊 Total applicant records in CSV: {$totalRows}");

            // Recreate iterator
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');
            $records = $csv->getRecords();

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            Log::channel('import')->info('🚀 Starting applicant row-by-row processing...');

            // Date normalizer (stopped timestamp conversion)
            $normalizeDate = function ($value) {
                $value = trim((string)$value);
                if ($value === '' || preg_match('/^(null|n\/a|na|none|-)\s*$/i', $value)) return null;

                // Add seconds if missing (HH:MM -> HH:MM:00)
                if (preg_match('/\d{1,2}:\d{2}$/', $value) && !preg_match('/\d{1,2}:\d{2}:\d{2}$/', $value)) {
                    $value .= ':00';
                }

                // Try different formats without converting to timestamp
                $formats = [
                    'Y-m-d H:i:s',
                    'Y-m-d H:i',
                    'Y-m-d',
                    'm/d/Y H:i:s',
                    'm/d/Y H:i',
                    'm/d/Y',
                    'd/m/Y H:i:s',
                    'd/m/Y H:i',
                    'd/m/Y',
                    'd-m-Y H:i:s',
                    'd-m-Y H:i',
                    'd-m-Y',
                    'Y/m/d H:i:s',
                    'Y/m/d H:i',
                    'Y/m/d',
                    'm-d-Y H:i:s',
                    'm-d-Y H:i',
                    'm-d-Y',
                    'Y.m.d H:i:s',
                    'Y.m.d H:i',
                    'Y.m.d',
                    'd.m.Y H:i:s',
                    'd.m.Y H:i',
                    'd.m.Y',
                    'Y-m-d',
                    'm/d/Y',
                    'd/m/Y'
                ];

                // Attempt to parse the value without converting to a timestamp
                foreach ($formats as $fmt) {
                    try {
                        // Instead of formatting to timestamp, just return the original value
                        if ($parsed = Carbon::createFromFormat($fmt, $value)) {
                            return $parsed->format('Y-m-d H:i:s'); // Ensure the format is MySQL-compatible
                        }
                    } catch (\Throwable $e) {
                        // Catch any exception and continue trying other formats
                    }
                }

                // Fallback: Return the value as-is if it can't be parsed (consider handling this case)
                return $value;
            };

            foreach ($records as $row) {
                $rowIndex++;
                try {
                    // Skip empty rows
                    if (empty(array_filter($row))) {
                        Log::channel('import')->warning("Row {$rowIndex}: Empty row , skipping");
                        continue;
                    }

                    // Normalize keys to lowercase to match headers
                    $row = collect($row)->mapWithKeys(function ($value, $key) {
                        $key = strtolower(trim($key));
                        $key = preg_replace('/\s+/', '_', $key);
                        return [$key => $value];
                    })->toArray();

                    // Clean data
                    $row = array_map(function ($value) {
                        return is_string($value) ? trim(preg_replace('/[^\x20-\x7E]/', '', $value)) : $value;
                    }, $row);

                    // Helper functions
                    $normalizeId = function ($input, $rowIndex) {
                        if (empty($input) && $input != '0' && $input != 0) {
                            Log::channel('import')->debug("Row {$rowIndex}: Empty id input, will generate new ID");
                            return null;
                        }
                        if (is_numeric($input)) {
                            $id = sprintf('%.0f', (float)$input);
                            if ($id == '0') {
                                Log::channel('import')->debug("Row {$rowIndex}: Invalid id (0), will generate new ID");
                                return null;
                            }
                            return $id;
                        }
                        Log::channel('import')->debug("Row {$rowIndex}: Invalid id format: {$input}, will generate new ID");
                        return null;
                    };

                    // Normalize ID
                    $id = $normalizeId($row['id'], $rowIndex);

                    // Handle postcode and geolocation
                    $cleanPostcode = '0';
                    if (!empty($row['applicant_postcode']) && is_string($row['applicant_postcode'])) {
                        preg_match('/[A-Z]{1,2}[0-9]{1,2}\s*[0-9][A-Z]{2}/i', $row['applicant_postcode'], $matches);
                        $cleanPostcode = $matches[0] ?? substr(trim($row['applicant_postcode']), 0, 8);
                    }

                    $lat = (isset($row['lat']) && is_numeric($row['lat'])) ? (float)$row['lat'] : null;
                    $lng = (isset($row['lng']) && is_numeric($row['lng'])) ? (float)$row['lng'] : null;

                    if (!$lat || !$lng) {
                        $postcode_query = strlen($cleanPostcode) < 6
                            ? DB::table('outcodepostcodes')->where('outcode', $cleanPostcode)->first()
                            : DB::table('postcodes')->where('postcode', $cleanPostcode)->first();

                        if ($postcode_query) {
                            $lat = (float)$postcode_query->lat;
                            $lng = (float)$postcode_query->lng;
                        }
                    }

                    // Ensure numeric fallback
                    $lat = $lat ?? 0.0000;
                    $lng = $lng ?? 0.0000;

                    if (strlen($cleanPostcode) == 8) {
                        $exists = DB::table('postcodes')->where('postcode', $cleanPostcode)->exists();
                        if (!$exists) {
                            DB::table('postcodes')->insert([
                                'postcode' => $cleanPostcode,
                                'lat' => $lat,
                                'lng' => $lng
                            ]);
                        }
                    } elseif (strlen($cleanPostcode) < 6 && $cleanPostcode != '0') {
                        $exists = DB::table('outcodepostcodes')->where('outcode', $cleanPostcode)->exists();
                        if (!$exists) {
                            DB::table('outcodepostcodes')->insert([
                                'outcode' => $cleanPostcode,
                                'lat' => $lat,
                                'lng' => $lng
                            ]);
                        }
                    }

                    // Handle job title and category
                    $job_category_id = null;
                    $job_title_id = null;
                    $job_type = '';
                    $requested_job_title = strtolower(trim($row['applicant_job_title'] ?? ''));
                    $requested_job_category = strtolower(trim($row['job_category'] ?? ''));
                    $job_title_prof = strtolower(trim($row['job_title_prof'] ?? ''));
                    $specialists = [
                        [
                            'id' => 1,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Psychiatrist'
                        ],
                        [
                            'id' => 2,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Spa Therapists'
                        ],
                        [
                            'id' => 3,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Housekeeper'
                        ],
                        [
                            'id' => 4,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'chef de partie'
                        ],
                        [
                            'id' => 5,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Waiter'
                        ],
                        [
                            'id' => 6,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Receptionist'
                        ],
                        [
                            'id' => 7,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Food & Beverage Assistant'
                        ],
                        [
                            'id' => 8,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Commis Chef'
                        ],
                        [
                            'id' => 9,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Occupational Therapist'
                        ],
                        [
                            'id' => 10,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Kitchen Porter'
                        ],
                        [
                            'id' => 11,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Physiotherapist'
                        ],
                        [
                            'id' => 12,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Restaurant Manager'
                        ],
                        [
                            'id' => 13,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Character Breakfast Assistant (C&B)'
                        ],
                        [
                            'id' => 14,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Speech and Language Therapy'
                        ],
                        [
                            'id' => 15,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Ancillary'
                        ],
                        [
                            'id' => 16,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Sous Chef'
                        ],
                        [
                            'id' => 17,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Pastry Chef'
                        ],
                        [
                            'id' => 18,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Psychologist'
                        ],
                        [
                            'id' => 19,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Manager'
                        ],
                        [
                            'id' => 20,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Radiographer'
                        ],
                        [
                            'id' => 21,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'ODP'
                        ],
                        [
                            'id' => 22,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'BREAST CARE NURSE'
                        ],
                        [
                            'id' => 23,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Andrology Clinical Nurse Specialist'
                        ],
                        [
                            'id' => 25,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Senior Chef De Partie'
                        ],
                        [
                            'id' => 26,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Cleaner'
                        ],
                        [
                            'id' => 27,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Kitchen Assistant'
                        ],
                        [
                            'id' => 28,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Therapist'
                        ],
                        [
                            'id' => 29,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Therapist'
                        ],
                        [
                            'id' => 30,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Pharmacist'
                        ],
                        [
                            'id' => 31,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Dining Room Assistant'
                        ],
                        [
                            'id' => 32,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Maintenance'
                        ],
                        [
                            'id' => 33,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Resident Liaison'
                        ],
                        [
                            'id' => 34,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Cook'
                        ],
                        [
                            'id' => 35,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Domestic'
                        ],
                        [
                            'id' => 36,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Doctor'
                        ],
                        [
                            'id' => 37,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Lead Family therapist'
                        ],
                        [
                            'id' => 38,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Administrator'
                        ],
                        [
                            'id' => 39,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'General Chef'
                        ],
                        [
                            'id' => 40,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Group Financial Controller'
                        ],
                        [
                            'id' => 41,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Restaurant Supervisor'
                        ],
                        [
                            'id' => 42,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Front of House'
                        ],
                        [
                            'id' => 43,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Bar Manager'
                        ],
                        [
                            'id' => 44,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Assistant Manager'
                        ],
                        [
                            'id' => 46,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Chef'
                        ],
                        [
                            'id' => 47,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Manager'
                        ],
                        [
                            'id' => 48,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Residential Children Worker'
                        ],
                        [
                            'id' => 49,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Practice Educator'
                        ],
                        [
                            'id' => 50,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Endoscopy'
                        ],
                        [
                            'id' => 51,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Nurse Associate'
                        ],
                        [
                            'id' => 52,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Director'
                        ],
                        [
                            'id' => 53,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Scrub Theatre Nurse'
                        ],
                        [
                            'id' => 54,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Anaesthetics lead'
                        ],
                        [
                            'id' => 55,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'AMBULATORY, RECOVERY AND WOUNDCARE'
                        ],
                        [
                            'id' => 56,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Radiographer'
                        ],
                        [
                            'id' => 57,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Bar'
                        ],
                        [
                            'id' => 58,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Radiographer'
                        ],
                        [
                            'id' => 59,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Service Delivery Lead'
                        ],
                        [
                            'id' => 60,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Catering Assistant'
                        ],
                        [
                            'id' => 62,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Accountant'
                        ],
                        [
                            'id' => 63,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Pre-Assessment Nurse'
                        ],
                        [
                            'id' => 64,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Reservations'
                        ],
                        [
                            'id' => 65,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Laundry Assistant'
                        ],
                        [
                            'id' => 66,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Storekeeper'
                        ],
                        [
                            'id' => 67,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Deputy Head Greenkeeper'
                        ],
                        [
                            'id' => 68,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Event Executive'
                        ],
                        [
                            'id' => 69,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Sales & Billing administrator'
                        ],
                        [
                            'id' => 70,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Associate Specialist'
                        ],
                        [
                            'id' => 71,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Junior Sous Chef'
                        ],
                        [
                            'id' => 72,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head Receptionist'
                        ],
                        [
                            'id' => 73,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Bar Supervisor'
                        ],
                        [
                            'id' => 74,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Sales Assistant'
                        ],
                        [
                            'id' => 75,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Guest Service Manager'
                        ],
                        [
                            'id' => 76,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Reservations Office Manager'
                        ],
                        [
                            'id' => 77,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Chef de Rang'
                        ],
                        [
                            'id' => 78,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Junior Sous Chef'
                        ],
                        [
                            'id' => 79,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Purchase Accounting Assistant'
                        ],
                        [
                            'id' => 80,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Floor Attendant'
                        ],
                        [
                            'id' => 81,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'AUDITOR'
                        ],
                        [
                            'id' => 82,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Executive Housekeeper'
                        ],
                        [
                            'id' => 83,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Breakfast Chef'
                        ],
                        [
                            'id' => 84,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Demi Chef de Partie'
                        ],
                        [
                            'id' => 85,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Pastry Sous Chef'
                        ],
                        [
                            'id' => 86,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Executive Chef'
                        ],
                        [
                            'id' => 87,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Housekeeping Supervisor'
                        ],
                        [
                            'id' => 88,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'supervisor'
                        ],
                        [
                            'id' => 89,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Duty Manager'
                        ],
                        [
                            'id' => 90,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Clinical Psychologist'
                        ],
                        [
                            'id' => 91,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Food Runner'
                        ],
                        [
                            'id' => 92,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'FRONT OFFICE GSA (GUEST SERVICE ASSOCIATE)'
                        ],
                        [
                            'id' => 93,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Executive Sous Chef'
                        ],
                        [
                            'id' => 94,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'RECEPTION MANAGER'
                        ],
                        [
                            'id' => 95,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'PASTRY CHEF DE PARTIE'
                        ],
                        [
                            'id' => 96,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Breakfast Supervisor'
                        ],
                        [
                            'id' => 97,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Preparation Chef'
                        ],
                        [
                            'id' => 98,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Junior Chef de Partie'
                        ],
                        [
                            'id' => 99,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Assistant Sommelier'
                        ],
                        [
                            'id' => 100,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Food and Beverage Supervisor'
                        ],
                        [
                            'id' => 101,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Breakfast Manager'
                        ],
                        [
                            'id' => 102,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Assistant Barn Manager'
                        ],
                        [
                            'id' => 103,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Senior Chef de Partie'
                        ],
                        [
                            'id' => 104,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Food and Beverage Manager'
                        ],
                        [
                            'id' => 105,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Restaurant Supervisor'
                        ],
                        [
                            'id' => 106,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'SENIOR SOUS CHEF'
                        ],
                        [
                            'id' => 107,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Greenkeeper'
                        ],
                        [
                            'id' => 108,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'CONFERENCE AND BANQUETING MANAGER'
                        ],
                        [
                            'id' => 109,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Team Leader'
                        ],
                        [
                            'id' => 110,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Account Manager'
                        ],
                        [
                            'id' => 111,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Conference and Banqueting Supervisor'
                        ],
                        [
                            'id' => 112,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Barista'
                        ],
                        [
                            'id' => 113,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Baker'
                        ],
                        [
                            'id' => 114,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Chief Steward'
                        ],
                        [
                            'id' => 115,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Porter'
                        ],
                        [
                            'id' => 116,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Sushi Chef'
                        ],
                        [
                            'id' => 117,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Brasserie Demi Chef'
                        ],
                        [
                            'id' => 118,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head Waiter'
                        ],
                        [
                            'id' => 119,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Restaurant Host'
                        ],
                        [
                            'id' => 120,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Occupational Therapist'
                        ],
                        [
                            'id' => 122,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Meeting and Events Sales Manager'
                        ],
                        [
                            'id' => 123,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Staff Nurse - Anaesthetics'
                        ],
                        [
                            'id' => 124,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Outpatient Sister'
                        ],
                        [
                            'id' => 128,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Marketing Executive'
                        ],
                        [
                            'id' => 129,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Recovery Nurse'
                        ],
                        [
                            'id' => 130,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Assessment Nurse'
                        ],
                        [
                            'id' => 131,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Hospitality Assistant'
                        ],
                        [
                            'id' => 132,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Handy Person'
                        ],
                        [
                            'id' => 133,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Golf Associate'
                        ],
                        [
                            'id' => 134,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Golf Starter & Marshall'
                        ],
                        [
                            'id' => 135,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Runner Linen'
                        ],
                        [
                            'id' => 136,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Guest Services Assistant'
                        ],
                        [
                            'id' => 137,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head Bartender'
                        ],
                        [
                            'id' => 138,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head Sommelier'
                        ],
                        [
                            'id' => 139,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'PHP Developer'
                        ],
                        [
                            'id' => 140,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Meeting & Events Coordinator'
                        ],
                        [
                            'id' => 141,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Front-End Developer'
                        ],
                        [
                            'id' => 142,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Principal Software Engineer'
                        ],
                        [
                            'id' => 143,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'SEO Manager'
                        ],
                        [
                            'id' => 144,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'General Assistant'
                        ],
                        [
                            'id' => 145,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Senior Software Developer'
                        ],
                        [
                            'id' => 146,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'UI Designer'
                        ],
                        [
                            'id' => 147,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Pharmacist Manager'
                        ],
                        [
                            'id' => 148,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Lead Pharmacist'
                        ],
                        [
                            'id' => 149,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Dispensing Assistant'
                        ],
                        [
                            'id' => 150,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Pharmacy Dispenser'
                        ],
                        [
                            'id' => 151,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Diner Manager'
                        ],
                        [
                            'id' => 152,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Casual F and B events Sup'
                        ],
                        [
                            'id' => 153,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Psychotherapist'
                        ],
                        [
                            'id' => 154,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Optometrists'
                        ],
                        [
                            'id' => 155,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Dispensing Opticians'
                        ],
                        [
                            'id' => 156,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Psychologist'
                        ],
                        [
                            'id' => 157,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Clinical Psychologist'
                        ],
                        [
                            'id' => 158,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Psychiatrist'
                        ],
                        [
                            'id' => 159,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Psychotherapist'
                        ],
                        [
                            'id' => 160,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Family Therapist'
                        ],
                        [
                            'id' => 161,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Occupational Therapist Assistant'
                        ],
                        [
                            'id' => 162,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Forestry Key Account Manager'
                        ],
                        [
                            'id' => 163,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Project Manager'
                        ],
                        [
                            'id' => 164,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Full Stack Developer'
                        ],
                        [
                            'id' => 165,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Senior Mechanical Engineer'
                        ],
                        [
                            'id' => 166,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Design Engineer'
                        ],
                        [
                            'id' => 167,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Technical Author'
                        ],
                        [
                            'id' => 168,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Solar PV Technical Design Engineer'
                        ],
                        [
                            'id' => 169,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Lead Nurse Infection Control'
                        ],
                        [
                            'id' => 170,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Physiologist'
                        ],
                        [
                            'id' => 171,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Surgical First Assistant'
                        ],
                        [
                            'id' => 172,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Sister'
                        ],
                        [
                            'id' => 173,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Manager'
                        ],
                        [
                            'id' => 174,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Sommelier'
                        ],
                        [
                            'id' => 175,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Specialist Support'
                        ],
                        [
                            'id' => 176,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'HR Advisor'
                        ],
                        [
                            'id' => 177,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Business Development'
                        ],
                        [
                            'id' => 178,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Kitchen Manager'
                        ],
                        [
                            'id' => 179,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head of Digital Transformation and IT'
                        ],
                        [
                            'id' => 180,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Housekeeping Manager'
                        ],
                        [
                            'id' => 181,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Hotel General Manager'
                        ],
                        [
                            'id' => 182,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Pharmacy Assistant'
                        ],
                        [
                            'id' => 183,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Training Officer'
                        ],
                        [
                            'id' => 184,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Pizza Chef'
                        ],
                        [
                            'id' => 185,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Dentist'
                        ],
                        [
                            'id' => 186,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Associate Dentist'
                        ],
                        [
                            'id' => 187,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Hygienist'
                        ],
                        [
                            'id' => 188,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Optical Assistant'
                        ],
                        [
                            'id' => 189,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head of Facilities'
                        ],
                        [
                            'id' => 190,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Dental Nurse'
                        ],
                        [
                            'id' => 191,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Medical Director'
                        ],
                        [
                            'id' => 192,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Positive Behaviour Practitioner'
                        ],
                        [
                            'id' => 193,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Governance & Quality Assurance Lead'
                        ],
                        [
                            'id' => 194,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Pharmacy technician'
                        ],
                        [
                            'id' => 195,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Dietician'
                        ],
                        [
                            'id' => 196,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head of Education'
                        ],
                        [
                            'id' => 197,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Orthodontist'
                        ],
                        [
                            'id' => 198,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Prosthodontist'
                        ],
                        [
                            'id' => 199,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Assistant'
                        ],
                        [
                            'id' => 200,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Hotel Manager'
                        ],
                        [
                            'id' => 201,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head of Quality Assurance and Improvement'
                        ],
                        [
                            'id' => 202,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'RGN'
                        ],
                        [
                            'id' => 203,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Operations Manager'
                        ],
                        [
                            'id' => 204,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Operations Manager'
                        ],
                        [
                            'id' => 205,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Practice Leader'
                        ],
                        [
                            'id' => 206,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Scrub Nurse'
                        ],
                        [
                            'id' => 209,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Creche support worker'
                        ],
                        [
                            'id' => 210,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Events and Sales Coordinator'
                        ],
                        [
                            'id' => 211,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Spa Manager'
                        ],
                        [
                            'id' => 212,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Senior Occupational Therapist'
                        ],
                        [
                            'id' => 213,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Grill Chef de Partie'
                        ],
                        [
                            'id' => 215,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Deputy General Manager'
                        ],
                        [
                            'id' => 216,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Food and Beverage Waiter/ess'
                        ],
                        [
                            'id' => 217,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Assistant Team Leader'
                        ],
                        [
                            'id' => 218,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Assistant Team Leader'
                        ],
                        [
                            'id' => 219,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'book keeper'
                        ],
                        [
                            'id' => 220,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Accuracy Checking Technician'
                        ],
                        [
                            'id' => 222,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Pastry Senior Sous Chef'
                        ],
                        [
                            'id' => 223,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Physiologist'
                        ],
                        [
                            'id' => 224,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head Housekeeper'
                        ],
                        [
                            'id' => 226,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Psychology'
                        ],
                        [
                            'id' => 227,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head Of Care'
                        ],
                        [
                            'id' => 228,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head Pastry Chef'
                        ],
                        [
                            'id' => 229,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Maintenance Engineer'
                        ],
                        [
                            'id' => 230,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Machine Operator'
                        ],
                        [
                            'id' => 231,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Third in Charge'
                        ],
                        [
                            'id' => 232,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Lead Occupational Therapist'
                        ],
                        [
                            'id' => 233,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Director of Clinical'
                        ],
                        [
                            'id' => 234,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Admission and Discharge Co-ordinator'
                        ],
                        [
                            'id' => 235,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Human Resource'
                        ],
                        [
                            'id' => 238,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Spa Host'
                        ],
                        [
                            'id' => 239,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Navigator'
                        ],
                        [
                            'id' => 240,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Legal Executive'
                        ],
                        [
                            'id' => 241,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Lawyer'
                        ],
                        [
                            'id' => 242,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Conveyancer'
                        ],
                        [
                            'id' => 243,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Solicitor'
                        ],
                        [
                            'id' => 244,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Practice Manager'
                        ],
                        [
                            'id' => 245,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Deputy Housekeeper'
                        ],
                        [
                            'id' => 246,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Maintenance Manager'
                        ],
                        [
                            'id' => 247,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Hospital Director'
                        ],
                        [
                            'id' => 248,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head Gardener'
                        ],
                        [
                            'id' => 249,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Driver'
                        ],
                        [
                            'id' => 250,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Secretary'
                        ],
                        [
                            'id' => 251,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Cluster Sales Exective'
                        ],
                        [
                            'id' => 252,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Trainer'
                        ],
                        [
                            'id' => 253,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Practise Educator'
                        ],
                        [
                            'id' => 254,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Third Incharge'
                        ],
                        [
                            'id' => 255,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Teacher'
                        ],
                        [
                            'id' => 256,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'butcher'
                        ],
                        [
                            'id' => 257,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Practitioner'
                        ],
                        [
                            'id' => 258,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Admin'
                        ],
                        [
                            'id' => 259,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Sales & Marketing Manager'
                        ],
                        [
                            'id' => 260,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head Receptionist'
                        ],
                        [
                            'id' => 261,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Pestry Chef'
                        ],
                        [
                            'id' => 262,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Welbeing Coordinator'
                        ],
                        [
                            'id' => 263,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Head Therapist'
                        ],
                        [
                            'id' => 264,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Lounge & Bar Supervisor'
                        ],
                        [
                            'id' => 265,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Deputy Head Housekeeper'
                        ],
                        [
                            'id' => 266,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Beauty Therapist'
                        ],
                        [
                            'id' => 267,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Front Of House Manager'
                        ],
                        [
                            'id' => 268,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Operations Director'
                        ],
                        [
                            'id' => 269,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Events Manager'
                        ],
                        [
                            'id' => 270,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Breakfast Server'
                        ],
                        [
                            'id' => 271,
                            'specialist_title' => 'nonnurse specialist',
                            'specialist_prof' => 'Dental Coordinator'
                        ],
                        [
                            'id' => 272,
                            'specialist_title' => 'nurse specialist',
                            'specialist_prof' => 'Practice Nurse'
                        ],
                    ];

                    if (empty($job_title_prof) || $job_title_prof == 'null' || $job_title_prof == '') {
                        if (!in_array($requested_job_title, ['nonnurse specialist', 'nurse specialist', 'non-nurse specialist'])) {
                            $normalizedTitle = preg_replace('/[^a-z0-9]/', '', $requested_job_title);
                            $job_title = JobTitle::whereRaw("LOWER(REGEXP_REPLACE(name, '[^a-z0-9]', '')) = ?", [$normalizedTitle])->first();
                            $job_category = JobCategory::whereRaw("LOWER(REGEXP_REPLACE(name, '[^a-z0-9]', '')) = ?", [preg_replace('/[^a-z0-9]/', '', $requested_job_category)])->first();

                            if ($job_title) {
                                $job_category_id = $job_title->job_category_id;
                                $job_title_id = $job_title->id;
                                $job_type = $job_title->type ?? '';
                            } elseif ($job_category) {
                                $job_category_id = $job_category->id;
                                Log::channel('import')->warning("Row {$rowIndex}: Job title not found: {$requested_job_title}");
                            } else {
                                Log::channel('import')->warning("Row {$rowIndex}: Job title and category not found: {$requested_job_title} / {$requested_job_category}");
                            }
                        }
                    } else {
                        foreach ($specialists as $spec) {
                            if ($spec['id'] == $job_title_prof) {
                                $normalizedSpec = strtolower(trim($spec['specialist_prof']));
                                $job_title = JobTitle::whereRaw('LOWER(name) = ?', [$normalizedSpec])->first();
                                if ($job_title) {
                                    $job_category_id = $job_title->job_category_id;
                                    $job_title_id = $job_title->id;
                                    $job_type = $job_title->type ?? '';
                                } else {
                                    Log::channel('import')->warning("Row {$rowIndex}: Job title not found for specialist id: {$job_title_prof}");
                                }
                                break;
                            }
                        }
                    }

                    // Normalize phone number to UK format
                    $normalizePhone = function ($number) {
                        if ($number === null) {
                            return null;
                        }

                        $number = trim((string)$number);

                        if ($number === '' || strtolower($number) === 'null') {
                            return null;
                        }

                        // Replace +44 with leading 0
                        if (str_starts_with($number, '+44')) {
                            $number = '0' . substr($number, 3);
                        }

                        // Remove non-digits
                        $digits = preg_replace('/\D+/', '', $number);

                        // UK numbers must be exactly 11 digits
                        if (strlen($digits) !== 11 || $digits[0] !== '0') {
                            return null;
                        }

                        return $digits;
                    };

                    // --- Process phone numbers ---
                    $rawPhone = $row['applicant_phone'];

                    $phone = '0';

                    // Check if the phone number contains a '/' (in case it's a range or multiple numbers)
                    if (is_string($rawPhone) && str_contains($rawPhone, '/')) {
                        $parts = array_map('trim', explode('/', $rawPhone));
                        $phone = $normalizePhone($parts[0] ?? ''); // Only process the first part
                    } else {
                        $phone = $normalizePhone($rawPhone); // Normalize the whole phone number
                    }

                    $homePhone = $normalizePhone($row['applicant_homephone']);

                    // Default landline to null
                    $applicantLandline = null;

                    // Only assign if home phone is valid and has 10 or more digits (after removing non-digits)
                    if (!empty($homePhone) && strlen(preg_replace('/\D/', '', $homePhone)) >= 10) {
                        $applicantLandline = $homePhone;
                    }

                    // Normalize boolean/enum fields
                    $normalizeBoolean = function ($value) {
                        $value = strtolower(trim((string)($value ?? '')));
                        return in_array($value, ['yes', '1', 'true'], true) ? 1 : 0;
                    };

                    $crmInterviewInput = strtolower(trim((string)($row['is_crm_interview_attended'] ?? '')));

                    $is_crm_interview_attended = match ($crmInterviewInput) {
                        'yes', '1', 'true'  => 1,
                        'pending', '2'      => 2,
                        'no', '0', 'false', '', 'null' => 0,
                        default             => 0, // REQUIRED for safety
                    };

                    $rawStatus = trim((string)($row['paid_status'] ?? ''));

                    // Convert to lowercase for normalization
                    $normalizedStatus = strtolower($rawStatus);

                    // Allowed statuses
                    $allowedStatuses = ['pending', 'close', 'open'];

                    // Default to 'pending' if not valid
                    $paid_status = in_array($normalizedStatus, $allowedStatuses) ? $normalizedStatus : 'pending';

                    $have_nursing_home_experience = null;
                    $input = strtolower(trim((string)($row['have_nursing_home_experience'] ?? '')));

                    if ($input != '' && $input != 'null' && $input != null) {
                        $have_nursing_home_experience = in_array($input, ['0', 'inactive', 'disabled', 'disable', 'no', 'false'], true) ? 0 : 1;
                    }

                    // Handle job source
                    $sourceRaw = $row['applicant_source'] ?? '';
                    $cleanedSource = is_string($sourceRaw) ? strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $sourceRaw))) : '';
                    $firstTwoWordsSource = implode(' ', array_slice(explode(' ', $cleanedSource), 0, 2));
                    if ($firstTwoWordsSource == 'total jobs') {
                        $firstTwoWordsSource = 'total job';
                    } elseif ($firstTwoWordsSource == 'c.v library') {
                        $firstTwoWordsSource = 'cv library';
                    }

                    $jobSource = JobSource::whereRaw('LOWER(name) = ?', [$firstTwoWordsSource])->first();
                    $jobSourceId = $jobSource ? $jobSource->id : 2; // Default to Reed

                    // Normalize and handle active status
                    $rawStatus = trim((string)($row['status'] ?? ''));
                    $normalizedStatus = strtolower($rawStatus);

                    // Allowed statuses
                    $allowedStatuses = ['active', 'inactive', 'pending'];

                    // Normalize the status field
                    $status = match ($normalizedStatus) {
                        'active', '1', 'yes', 'enabled' => 1, // 'active' mapped to 1
                        'inactive', 'no', '0', 'disabled' => 0, // 'inactive' mapped to 0
                        default => 1, // Default to 'active' (1) if status is not recognized
                    };

                    $applicant_email = isset($row['applicant_email']) &&
                        !in_array(strtolower($row['applicant_email']), ['null', 'NULL', '-'])
                        ? strtolower($row['applicant_email'])
                        : null;

                    // Normalize paid_timestamp — only consider it when paid_status is not 'pending'
                    $rawPaid = trim((string)($row['paid_timestamp'] ?? ''));
                    $paid_timestamp = null;

                    if ($paid_status !== 'pending' && $rawPaid !== '' && !preg_match('/^(null|n\/a|na|none|-|\s*)$/i', $rawPaid)) {
                        try {
                            $ts = strtotime($rawPaid);
                            if ($ts !== false && $ts > 0) {
                                $paid_timestamp = Carbon::createFromTimestamp($ts)->toDateTimeString();
                            }
                        } catch (\Throwable $e) {
                            Log::channel('import')->debug("Row {$rowIndex}: Failed to parse paid_timestamp '{$rawPaid}' — {$e->getMessage()}");
                        }
                    } else {
                        // Ensure pending status does not carry a timestamp
                        $paid_timestamp = null;
                    }

                    // Normalize created_at
                    $createdAt = $normalizeDate($row['created_at']);
                    $updatedAt = $normalizeDate($row['updated_at']);

                    // Build processed row with corrected logic for timestamps
                    $processedRow = [
                        'id' => $id,
                        'applicant_uid' => $id ? md5($id) : null,
                        'user_id' => (int)$row['applicant_user_id'],
                        'applicant_name' => $row['applicant_name'],
                        'applicant_email' => $applicant_email,
                        'applicant_notes' => $row['applicant_notes'] ?? null,
                        'lat' => $lat,
                        'lng' => $lng,
                        'gender' => $row['gender'] ?? 'u',
                        'applicant_cv' => $row['applicant_cv'] ?? null,
                        'updated_cv' => $row['updated_cv'] ?? null,
                        'applicant_postcode' => $cleanPostcode,
                        'applicant_experience' => $row['applicant_experience'] ?? null,
                        'job_category_id' => $job_category_id,
                        'job_source_id' => (int)$jobSourceId,
                        'job_title_id' => $job_title_id,
                        'job_type' => $job_type,
                        'applicant_phone' => $phone,
                        'applicant_landline' => $applicantLandline,
                        'is_blocked' => $normalizeBoolean($row['is_blocked'] ?? false),
                        'is_no_job' => $normalizeBoolean($row['is_no_job'] ?? false),
                        'is_temp_not_interested' => $normalizeBoolean($row['temp_not_interested'] ?? false),
                        'is_no_response' => $normalizeBoolean($row['no_response'] ?? false),
                        'is_circuit_busy' => $normalizeBoolean($row['is_circuit_busy'] ?? false),
                        'is_callback_enable' => $normalizeBoolean($row['is_callback_enable'] ?? false),
                        'is_in_nurse_home' => $normalizeBoolean($row['is_in_nurse_home'] ?? false),
                        'is_cv_in_quality' => $normalizeBoolean($row['is_cv_in_quality'] ?? false),
                        'is_cv_in_quality_clear' => $normalizeBoolean($row['is_cv_in_quality_clear'] ?? false),
                        'is_cv_sent' => $normalizeBoolean($row['is_cv_sent'] ?? false),
                        'is_cv_in_quality_reject' => $normalizeBoolean($row['is_cv_reject'] ?? false),
                        'is_interview_confirm' => $normalizeBoolean($row['is_interview_confirm'] ?? false),
                        'is_interview_attend' => $normalizeBoolean($row['is_interview_attend'] ?? false),
                        'is_in_crm_request' => $normalizeBoolean($row['is_in_crm_request'] ?? false),
                        'is_in_crm_reject' => $normalizeBoolean($row['is_in_crm_reject'] ?? false),
                        'is_in_crm_request_reject' => $normalizeBoolean($row['is_in_crm_request_reject'] ?? false),
                        'is_crm_request_confirm' => $normalizeBoolean($row['is_crm_request_confirm'] ?? false),
                        'is_crm_interview_attended' => $is_crm_interview_attended,
                        'is_in_crm_start_date' => $normalizeBoolean($row['is_in_crm_start_date'] ?? false),
                        'is_in_crm_invoice' => $normalizeBoolean($row['is_in_crm_invoice'] ?? false),
                        'is_in_crm_invoice_sent' => $normalizeBoolean($row['is_in_crm_invoice_sent'] ?? false),
                        'is_in_crm_start_date_hold' => $normalizeBoolean($row['is_in_crm_start_date_hold'] ?? false),
                        'is_in_crm_paid' => $normalizeBoolean($row['is_in_crm_paid'] ?? false),
                        'is_in_crm_dispute' => $normalizeBoolean($row['is_in_crm_dispute'] ?? false),
                        'is_job_within_radius' => $normalizeBoolean($row['is_job_within_radius'] ?? false),
                        'have_nursing_home_experience' => $have_nursing_home_experience,
                        'paid_status' => $paid_status,
                        'paid_timestamp' => $paid_timestamp,
                        'status' => $status,
                        // Keep raw normalized timestamp strings (or null)
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ];

                    $processedData[] = $processedRow;
                } catch (\Throwable $e) {
                    $failedRows[] = ['row' => $rowIndex, 'error' => $e->getMessage()];
                    Log::channel('import')->error("Row {$rowIndex}: Failed processing - {$e->getMessage()}");
                }
            }

            Log::channel('import')->info("✅ Processed {$rowIndex} rows. Total valid: " . count($processedData) . ", Failed: " . count($failedRows));

            // Insert/Update in chunks
            foreach (array_chunk($processedData, 50) as $chunkIndex => $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows, $chunkIndex) {
                        foreach ($chunk as $index => $row) {
                            $rowIndex = ($chunkIndex * 50) + $index + 2;

                            try {
                                Applicant::withoutTimestamps(function () use ($row) {
                                    Applicant::updateOrCreate(['id' => $row['id']], $row);
                                });

                                $successfulRows++;
                            } catch (\Throwable $e) {
                                $failedRows[] = [
                                    'row' => $rowIndex,
                                    'error' => $e->getMessage(),
                                    'email' => $row['applicant_email'] ?? 'unknown',
                                ];
                            }
                        }
                    });
                } catch (\Throwable $e) {
                    $failedRows[] = ['chunk' => $chunkIndex, 'error' => $e->getMessage()];
                }
            }


            // Cleanup
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("🗑️ Deleted temporary file: {$filePath}");
            }

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            Log::channel('import')->info("🏁 [Applicant Import Summary]");
            Log::channel('import')->info("• Total rows read: {$totalRows}");
            Log::channel('import')->info("• Successfully imported: {$successfulRows}");
            Log::channel('import')->info("• Failed rows: " . count($failedRows));
            Log::channel('import')->info("• Time taken: {$duration} seconds");

            return response()->json([
                'message' => 'CSV import completed successfully!',
                'summary' => [
                    'total_rows' => $totalRows,
                    'successful_rows' => $successfulRows,
                    'failed_rows' => count($failedRows),
                    'failed_details' => $failedRows,
                    'duration_seconds' => $duration,
                ],
            ], 200);
        } catch (\Exception $e) {
            if (file_exists($filePath ?? '')) {
                unlink($filePath);
                Log::channel('import')->info("🗑️ Deleted temporary file after error: {$filePath}");
            }
            Log::channel('import')->error("💥 Import failed: {$e->getMessage()}\nStack trace: {$e->getTraceAsString()}");
            return response()->json([
                'error' => 'CSV import failed: ' . $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }
    public function datesImport(Request $request)
    {
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5242880'],
        ]);

        $file = $request->file('csv_file');
        $path = $file->storeAs('uploads/import_files', time() . '_' . $file->getClientOriginalName());
        $filePath = storage_path("app/{$path}");

        // CSV reader
        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setHeaderOffset(0);

        // Normalize headers
        $headers = array_map(fn($h) => strtolower(trim($h)), $csv->getHeader());
        foreach (['id', 'created_at', 'updated_at'] as $req) {
            if (!in_array($req, $headers, true)) {
                @unlink($filePath);
                return response()->json(['error' => "Missing required header: {$req}"], 422);
            }
        }

        // Date normalizer
        $normalizeDate = function ($value) {
            $value = trim((string) $value);

            if ($value === '' || preg_match('/^(null|n\/a|na|none|-)\s*$/i', $value)) {
                return null;
            }

            // Add seconds if missing (HH:MM → HH:MM:00)
            if (preg_match('/\d{1,2}:\d{2}$/', $value) && !preg_match('/\d{1,2}:\d{2}:\d{2}$/', $value)) {
                $value .= ':00';
            }

            $formats = [
                'Y-m-d H:i:s',
                'Y-m-d H:i',
                'Y-m-d',
                'm/d/Y H:i:s',
                'm/d/Y H:i',
                'm/d/Y',
                'd/m/Y H:i:s',
                'd/m/Y H:i',
                'd/m/Y',
                'd-m-Y H:i:s',
                'd-m-Y H:i',
                'd-m-Y',
                'Y/m/d H:i:s',
                'Y/m/d H:i',
                'Y/m/d',
                'm-d-Y H:i:s',
                'm-d-Y H:i',
                'm-d-Y',
                'Y.m.d H:i:s',
                'Y.m.d H:i',
                'Y.m.d',
                'd.m.Y H:i:s',
                'd.m.Y H:i',
                'd.m.Y',
            ];

            foreach ($formats as $fmt) {
                try {
                    return Carbon::createFromFormat($fmt, $value)->format('Y-m-d H:i:s');
                } catch (\Throwable $e) {
                    // try next format
                }
            }

            return null;
        };

        $success = 0;
        $failed = [];
        $rowNo = 1;

        DB::beginTransaction();

        try {
            foreach ($csv->getRecords() as $row) {
                $rowNo++;

                // Normalize row keys
                $row = array_change_key_case($row, CASE_LOWER);

                $id = (int) ($row['id'] ?? 0);
                $created = $normalizeDate($row['created_at'] ?? null);
                $updated = $normalizeDate($row['updated_at'] ?? null);

                if (!$id || !$created || !$updated) {
                    $failed[] = [
                        'row' => $rowNo,
                        'id' => $id ?: null,
                        'error' => 'Invalid id / created_at / updated_at',
                    ];
                    continue;
                }

                // Update ONLY if record exists
                $affected = DB::table('units')
                    ->where('id', $id)
                    ->update([
                        'created_at' => $created,
                        'updated_at' => $updated,
                    ]);

                if ($affected) {
                    $success++;
                } else {
                    $failed[] = [
                        'row' => $rowNo,
                        'id' => $id,
                        'error' => 'ID not found',
                    ];
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            @unlink($filePath);
            throw $e;
        }

        @unlink($filePath);

        return response()->json([
            'message' => 'Timestamp import finished.',
            'summary' => [
                'successful_updates' => $success,
                'failed_rows' => count($failed),
                'failed_details' => $failed,
            ],
        ]);
    }
    public function salesImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            $startTime = microtime(true);
            Log::channel('import')->info('🔹 [Sales Import] Starting CSV import process...');

            // Step 1: Store file
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");
            Log::channel('import')->info('📂 File stored at: ' . $filePath);

            // Step 2: Ensure UTF-8
            $content = file_get_contents($filePath);
            $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
            if ($encoding != 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                file_put_contents($filePath, $content);
                Log::channel('import')->info("✅ Converted file to UTF-8 from {$encoding}");
            }

            // Step 3: Load CSV
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = array_map('trim', $csv->getHeader());
            $headers = array_filter($headers, fn($h) => $h != '');

            if (empty($headers)) {
                Log::channel('import')->error('❌ No valid headers found in CSV.');
                return response()->json(['error' => 'No valid headers found in CSV.'], 400);
            }

            $records = $csv->getRecords();
            $totalRows = iterator_count($records);
            Log::channel('import')->info("📊 Total records in CSV: {$totalRows}");
            // Recreate iterator since iterator_count exhausts it
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $records = $csv->getRecords();

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            Log::channel('import')->info('🚀 Starting row-by-row processing...');

            foreach ($records as $row) {
                $rowIndex++;
                try {
                    if (empty(array_filter($row))) continue;

                    // Ensure consistent column mapping
                    $row = array_map(fn($v) => is_string($v) ? trim(preg_replace('/[^\x20-\x7E]/', '', $v)) : $v, $row);

                    // Example logic (simplified for demo)
                    // $requested_job_title = $row['job_title'] ?? '';
                    // $job_title = JobTitle::whereRaw("LOWER(REPLACE(name,' ','')) = ?", [strtolower(str_replace(' ', '', $requested_job_title))])->first();

                    // Date preprocessing
                    $preprocessDate = function ($dateString, $field, $rowIndex) {
                        if (empty($dateString) || !is_string($dateString)) {
                            return null;
                        }

                        // Fix malformed numeric formats (e.g., 1122024 1230)
                        if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s?(\d{1,2})(\d{2})?$/', $dateString, $matches)) {
                            $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} " . ($matches[4] ?? '00') . ":" . ($matches[5] ?? '00');
                            Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                            return $fixedDate;
                        }

                        return $dateString;
                    };

                    // Parse dates (corrected format order)
                    $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                        if (empty($dateString)) {
                            return null;
                        }

                        $formats = [
                            'Y-m-d H:i:s',
                            'Y-m-d H:i',
                            'Y-m-d',
                            'm/d/Y H:i',  // US format first
                            'm/d/Y H:i:s',
                            'm/d/Y',
                            'd/m/Y H:i',
                            'd/m/Y H:i:s',
                            'd/m/Y',
                            'j F Y',
                            'j F Y H:i',
                            'j F Y g:i A',
                            'd F Y',
                            'd F Y g:i A'
                        ];

                        foreach ($formats as $format) {
                            try {
                                $dt = Carbon::createFromFormat($format, $dateString);
                                // Log::channel('import')->debug("Row {$rowIndex}: Parsed {$field} '{$dateString}' with format '{$format}'");
                                return $dt->format('Y-m-d H:i:s');
                            } catch (\Exception $e) {
                                // continue
                            }
                        }


                        Log::channel('import')->debug("Row {$rowIndex}: All formats failed for {$field} '{$dateString}'");
                        return null;
                    };

                    // Normalizer (keeps created_at & updated_at null if invalid)
                    $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                        $value = trim((string)($value ?? ''));

                        // Skip invalid placeholders
                        if (
                            $value == '' ||
                            in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                        ) {
                            return null;
                        }

                        try {
                            $value = $preprocessDate($value, $field, $rowIndex);
                            $parsed = $parseDate($value, $rowIndex, $field);

                            if (!$parsed || strtotime($parsed) == false) {
                                throw new \Exception("Invalid date format: '{$value}'");
                            }

                            return $parsed;
                        } catch (\Exception $e) {
                            Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                            return null;
                        }
                    };

                    $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                    $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                    // ✅ Postcode cleaning
                    $cleanPostcode = '0';
                    if (!empty($row['postcode'])) {
                        preg_match('/[A-Z]{1,2}[0-9]{1,2}\s*[0-9][A-Z]{2}/i', $row['postcode'], $matches);
                        $cleanPostcode = $matches[0] ?? substr(trim($row['postcode']), 0, 8);
                    }

                    $lat = is_numeric($row['lat'] ?? null) ? (float) $row['lat'] : 0.0000;
                    $lng = is_numeric($row['lng'] ?? null) ? (float) $row['lng'] : 0.0000;

                    if ($lat == 0.0000 && $lng == 0.0000) {
                        $postcode_query = strlen($cleanPostcode) < 6
                            ? DB::table('outcodepostcodes')->where('outcode', $cleanPostcode)->first()
                            : DB::table('postcodes')->where('postcode', $cleanPostcode)->first();
                        if ($postcode_query) {
                            $lat = $postcode_query->lat;
                            $lng = $postcode_query->lng;
                        }
                    }

                    // ✅ Insert postcode if missing
                    if (strlen($cleanPostcode) == 8 && !DB::table('postcodes')->where('postcode', $cleanPostcode)->exists()) {
                        $postcodesToInsert[] = [
                            'postcode' => $cleanPostcode,
                            'lat' => $lat,
                            'lng' => $lng,
                            'created_at' => null,
                            'updated_at' => null,
                        ];
                    } elseif (strlen($cleanPostcode) < 6 && !DB::table('outcodepostcodes')->where('outcode', $cleanPostcode)->exists()) {
                        $outcodesToInsert[] = [
                            'outcode' => $cleanPostcode,
                            'lat' => $lat,
                            'lng' => $lng,
                            'created_at' => null,
                            'updated_at' => null,
                        ];
                    }

                    // ✅ Job title lookup
                    $requested_job_title = $row['job_title'] ?? '';
                    $specialTitles = ['nonnurse specialist', 'nurse specialist', 'non-nurse specialist', 'select job title'];
                    $job_category_id = null;
                    $job_title_id = null;
                    $job_type = '';

                    if (!in_array(strtolower($requested_job_title), $specialTitles)) {
                        $job_title = JobTitle::whereRaw("LOWER(REPLACE(name, ' ', '')) = ?", [strtolower(str_replace(' ', '', $requested_job_title))])->first();
                        if ($job_title) {
                            $job_category_id = $job_title->job_category_id;
                            $job_title_id = $job_title->id;
                            $job_type = $job_title->type;
                        }
                    } else {
                        $catStr = str_contains($requested_job_title, 'non') ? 'non nurse' : 'nurse';
                        $job_category = JobCategory::whereRaw("LOWER(REPLACE(name, ' ', '')) = ?", [strtolower(str_replace(' ', '', $catStr))])->first();
                        if ($job_category) {
                            $job_title = JobTitle::where('job_category_id', $job_category->id)->first();
                            if ($job_title) {
                                $job_category_id = $job_title->job_category_id;
                                $job_title_id = $job_title->id;
                                $job_type = $job_title->type;
                            }
                        }
                    }

                    // ✅ Status normalization
                    $status = match (strtolower($row['status'] ?? '')) {
                        'pending' => 2,
                        'active' => 1,
                        'disable' => 0,
                        'rejected' => 3,
                        default => 0
                    };

                    $processedData[] = [
                        'id' => $row['id'] ?? null,
                        'sale_uid' => $row['id'] ? md5($row['id']) : Str::uuid(),
                        'user_id' => $row['user_id'] ?? null,
                        'office_id' => $row['head_office'] ?? null,
                        'unit_id' => $row['head_office_unit'] ?? null,
                        'sale_postcode' => $cleanPostcode,
                        'job_category_id' => $job_category_id,
                        'job_title_id' => $job_title_id,
                        'job_type' => $job_type,
                        'position_type' => $row['job_type'] ?? null,
                        'lat' => $lat,
                        'lng' => $lng,
                        'cv_limit' => $row['send_cv_limit'] ?? 0,
                        'timing' => $row['timing'] ?? '',
                        'experience' => $row['experience'] ?? '',
                        'salary' => $row['salary'] ?? '',
                        'benefits' => $row['benefits'] ?? '',
                        'qualification' => $row['qualification'] ?? '',
                        'job_description' => $row['job_description'] ?? null,
                        'is_on_hold' => (int)($row['is_on_hold'] ?? 0),
                        'is_re_open' => (int)($row['is_re_open'] ?? 0),
                        'status' => $status,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ];
                } catch (\Throwable $e) {
                    $failedRows[] = ['row' => $rowIndex, 'error' => $e->getMessage()];
                    Log::channel('import')->error("❌ Row {$rowIndex} failed: " . $e->getMessage());
                }
            }

            Log::channel('import')->info("✅ Processing complete. Total processed: " . count($processedData) . ", Failed: " . count($failedRows));

            // Step 4: Batch insert/update with detailed error tracking
            foreach (array_chunk($processedData, 100) as $chunkIndex => $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $rowIndex => $row) {
                            try {
                                Sale::withoutTimestamps(function () use ($row) {
                                    Sale::updateOrCreate(['id' => $row['id']], $row);
                                });
                                $successfulRows++;
                            } catch (\Throwable $e) {
                                $failedRows[] = [
                                    'row' => $row['id'] ?? "unknown (chunk row {$rowIndex})",
                                    'error' => $e->getMessage(),
                                    'office_id' => $row['office_id'] ?? null,
                                    'unit_id' => $row['unit_id'] ?? null,
                                    'job_title_id' => $row['job_title_id'] ?? null,
                                    'job_category_id' => $row['job_category_id'] ?? null,
                                ];
                                Log::channel('import')->error("❌ DB insert failed for ID {$row['id']} - " . $e->getMessage());
                            }
                        }
                    });

                    Log::channel('import')->info("💾 Successfully processed chunk #{$chunkIndex} ({$successfulRows} total so far)");
                } catch (\Throwable $e) {
                    $failedRows[] = [
                        'chunk' => $chunkIndex,
                        'error' => $e->getMessage(),
                    ];
                    Log::channel('import')->error("⚠️ Chunk #{$chunkIndex} failed: " . $e->getMessage());
                }
            }


            // Cleanup
            if (file_exists($filePath)) unlink($filePath);

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime), 2);

            Log::channel('import')->info("🏁 [Sales Import Summary]");
            Log::channel('import')->info("• Total rows read: {$totalRows}");
            Log::channel('import')->info("• Successfully imported: {$successfulRows}");
            Log::channel('import')->info("• Failed rows: " . count($failedRows));
            Log::channel('import')->info("• Time taken: {$duration} seconds");

            return response()->json([
                'message' => 'CSV import completed successfully!',
                'summary' => [
                    'total_rows' => $totalRows,
                    'successful_rows' => $successfulRows,
                    'failed_rows' => count($failedRows),
                    'failed_details' => $failedRows,
                    'duration_seconds' => $duration,
                ],
            ]);
        } catch (\Exception $e) {
            Log::channel('import')->error('💥 Sales import failed: ' . $e->getMessage());
            return response()->json(['error' => 'Sales import failed: ' . $e->getMessage()], 500);
        }
    }
    public function usersImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            $file = $request->file('csv_file');
            $filename = $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            $data = array_map('str_getcsv', file($filePath));

            if (count($data) < 2) {
                return response()->json(['error' => 'CSV file is empty or invalid.'], 400);
            }

            $headers = array_map('trim', $data[0]);

            for ($i = 1; $i < count($data); $i++) {
                $row = array_combine($headers, $data[$i]);

                if (!$row || !isset($row['name'], $row['email'])) {
                    continue; // Skip incomplete row
                }

                // Robust date parsing: try multiple formats, fallback to parse, then to now()
                $parseFlexibleDate = function ($value) {
                    $value = trim((string)($value ?? ''));
                    if ($value === '') return null;

                    $formats = [
                        'm/d/Y H:i',
                        'd/m/Y H:i',
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'd-m-Y H:i',
                        'm-d-Y H:i',
                        'd/m/Y',
                        'm/d/Y',
                        'Y-m-d'
                    ];

                    foreach ($formats as $fmt) {
                        try {
                            $dt = Carbon::createFromFormat($fmt, $value);
                            if ($dt) return $dt->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            // try next
                        }
                    }

                    // Last resort, try Carbon::parse
                    try {
                        $dt = Carbon::parse($value);
                        return $dt->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        return null;
                    }
                };

                // Do NOT default to current date; allow null if parsing fails
                $createdAt = $parseFlexibleDate($row['created_at'] ?? null);
                $updatedAt = $parseFlexibleDate($row['updated_at'] ?? null);

                try {
                    DB::table('users')->updateOrInsert(
                        ['id' => $row['id']], // Match by ID
                        [
                            'name' => $row['name'] ?? null,
                            'email' => $row['email'] ?? null,
                            'password' => $row['password'] ?? null,
                            'created_at' => $createdAt,
                            'updated_at' => $updatedAt,
                        ]
                    );
                } catch (\Exception $e) {
                    Log::channel('import')->error("Failed to import user (DB::table): " . json_encode($row) . ' — ' . $e->getMessage());
                    continue; // Skip row on DB error
                }
            }

            return response()->json(['message' => 'CSV imported and users saved successfully.']);
        } catch (\Exception $e) {
            Log::channel('import')->error('CSV import failed: ' . $e->getMessage());
            return response()->json(['error' => 'An error occurred while processing the CSV.'], 500);
        }
    }
    public function messagesImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            $startTime = microtime(true);
            Log::channel('import')->info('🔹 [Applicant Import] Starting CSV import process...');

            // Store file
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");
            Log::channel('import')->info('📂 File stored at: ' . $filePath);

            // Ensure UTF-8 encoding
            $content = file_get_contents($filePath);
            $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
            if ($encoding != 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                file_put_contents($filePath, $content);
                Log::channel('import')->info("✅ Converted file to UTF-8 from {$encoding}");
            }

            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);

            // Count total rows
            $records = $csv->getRecords();
            $totalRows = iterator_count($records);
            Log::channel('import')->info("📊 Total messages records in CSV: {$totalRows}");

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            Log::channel('import')->info('🚀 Starting messages row-by-row processing...');

            foreach ($records as $row) {
                $rowIndex++;
                try {
                    // Skip empty rows
                    if (empty(array_filter($row))) {
                        Log::channel('import')->warning("Row {$rowIndex}: Empty row , skipping");
                        continue;
                    }

                    $row = array_pad($row, $expectedColumnCount, null);
                    $row = array_slice($row, 0, $expectedColumnCount);
                    $row = array_combine($headers, $row);

                    $row = array_map(function ($value) {
                        if (is_string($value)) {
                            $value = preg_replace('/\s+/', ' ', trim($value));
                            $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                        }
                        return $value;
                    }, $row);

                    try {
                        $date = !empty($row['date'])
                            ? Carbon::parse($row['date'])->format('Y-m-d')
                            : null;
                        $time = !empty($row['time']) ? Carbon::createFromFormat('H:i:s', $row['time'])->format('H:i:s') : null;
                    } catch (\Exception $e) {
                        Log::channel('import')->warning("Row {$rowIndex}: Invalid date format - {$e->getMessage()}");
                    }

                    // Date preprocessing
                    $preprocessDate = function ($dateString) {
                        if (empty($dateString) || !is_string($dateString)) {
                            return null;
                        }

                        // Fix malformed numeric formats (e.g., 1122024 1230)
                        if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s?(\d{1,2})(\d{2})?$/', $dateString, $matches)) {
                            $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} " . ($matches[4] ?? '00') . ":" . ($matches[5] ?? '00');
                            return $fixedDate;
                        }

                        return $dateString;
                    };


                    // Parse dates (corrected format order)
                    $parseDate = function ($dateString) {
                        if (empty($dateString)) {
                            return null;
                        }

                        $formats = [
                            'Y-m-d H:i:s',
                            'Y-m-d H:i',
                            'Y-m-d',
                            'm/d/Y H:i',  // US format first
                            'm/d/Y H:i:s',
                            'm/d/Y',
                            'd/m/Y H:i',
                            'd/m/Y H:i:s',
                            'd/m/Y',
                            'j F Y',
                            'j F Y H:i',
                            'j F Y g:i A',
                            'd F Y',
                            'd F Y g:i A'
                        ];

                        foreach ($formats as $format) {
                            try {
                                $dt = Carbon::createFromFormat($format, $dateString);
                                return $dt->format('Y-m-d H:i:s');  // Ensure the format is MySQL-compatible
                            } catch (\Exception $e) {
                                // continue trying other formats
                            }
                        }
                        return null;
                    };


                    // Normalizer (keeps created_at & updated_at null if invalid)
                    $normalizeDate = function ($value) use ($preprocessDate, $parseDate) {
                        $value = trim((string)($value ?? ''));

                        // Skip invalid placeholders
                        if (
                            $value == '' ||
                            in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                        ) {
                            return null;
                        }

                        try {
                            // Preprocess and parse the date
                            $value = $preprocessDate($value);
                            $parsed = $parseDate($value);

                            if (!$parsed || strtotime($parsed) == false) {
                                throw new \Exception("Invalid date format: '{$value}'");
                            }

                            return $parsed;
                        } catch (\Exception $e) {
                            return null;
                        }
                    };


                    $createdAt = $normalizeDate($row['created_at']);
                    $updatedAt = $normalizeDate($row['updated_at']);

                    $processedRow = [
                        'id' => $row['id'],
                        'msg_id' => $row['msg_id'] ?? null,
                        'module_id' => $row['applicant_id'] ?? null,
                        'module_type' => 'Horsefly\Applicant',
                        'user_id' => $row['user_id'] ?? null,
                        'message' => $row['message'] ?? '',
                        'phone_number' => $row['phone_number'] ?? null,
                        'status' => $row['status'],
                        'is_read' => $row['is_read'],
                        'is_sent' => 1,
                        'date' => $date,
                        'time' => $time,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ];
                    $processedData[] = $processedRow;
                } catch (\Throwable $e) {
                    $failedRows[] = ['row' => $rowIndex, 'error' => $e->getMessage()];
                    Log::channel('import')->error("Row {$rowIndex}: Failed processing - {$e->getMessage()}");
                }
            }

            Log::channel('import')->info("✅ Processed {$rowIndex} rows. Total valid: " . count($processedData) . ", Failed: " . count($failedRows));

            foreach (array_chunk($processedData, 100) as $chunkIndex => $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows, $chunkIndex) {
                        foreach ($chunk as $index => $row) {
                            $rowIndex = ($chunkIndex * 100) + $index + 2;
                            try {
                                Message::updateOrCreate(
                                    ['id' => $row['id']],
                                    $row
                                );
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }

                                $successfulRows++;
                            } catch (\Throwable $e) {
                                $failedRows[] = [
                                    'row' => $rowIndex,
                                    'error' => $e->getMessage(),
                                ];

                                Log::channel('import')->error("Row {$rowIndex}: DB insert/update failed for {$row['id']} - {$e->getMessage()}");
                            }
                        }
                    });

                    Log::channel('import')->info("💾 Processed chunk #{$chunkIndex} ({$successfulRows} total)");
                } catch (\Throwable $e) {
                    $failedRows[] = ['chunk' => $chunkIndex, 'error' => $e->getMessage()];
                    Log::channel('import')->error("Chunk {$chunkIndex}: Transaction failed - {$e->getMessage()}");
                }
            }

            // Cleanup
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("🗑️ Deleted temporary file: {$filePath}");
            }

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            Log::channel('import')->info("🏁 [Messages Import Summary]");
            Log::channel('import')->info("• Total rows read: {$totalRows}");
            Log::channel('import')->info("• Successfully imported: {$successfulRows}");
            Log::channel('import')->info("• Failed rows: " . count($failedRows));
            Log::channel('import')->info("• Time taken: {$duration} seconds");

            return response()->json([
                'message' => 'CSV import completed successfully!',
                'summary' => [
                    'total_rows' => $totalRows,
                    'successful_rows' => $successfulRows,
                    'failed_rows' => count($failedRows),
                    'failed_details' => $failedRows,
                    'duration_seconds' => $duration,
                ],
            ], 200);
        } catch (\Exception $e) {
            if (file_exists($filePath ?? '')) {
                unlink($filePath);
                Log::channel('import')->info("🗑️ Deleted temporary file after error: {$filePath}");
            }
            Log::channel('import')->error("💥 Import failed: {$e->getMessage()}\nStack trace: {$e->getTraceAsString()}");
            return response()->json([
                'error' => 'CSV import failed: ' . $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }
    public function applicantNotesImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // Check log directory writability
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('Starting Applicant Notes CSV import');

            // Store file with unique timestamped name
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Stream encoding conversion
            $encoding = $this->detectEncoding($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                if ($handleIn == false) {
                    Log::channel('import')->error("Failed to open file for reading: {$filePath}");
                    return response()->json(['error' => 'Failed to read uploaded file.'], 500);
                }
                $handleOut = fopen($tempFile, 'w');
                if ($handleOut == false) {
                    fclose($handleIn);
                    Log::channel('import')->error("Failed to create temporary file: {$tempFile}");
                    return response()->json(['error' => 'Failed to create temporary file.'], 500);
                }
                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }
                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                if ($rowIndex % 100 == 0) {
                    Log::channel('import')->info("Processing row {$rowIndex}");
                }

                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);
                if ($row == false || !isset($row['user_id'], $row['applicant_id'], $row['details'])) {
                    Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                    continue;
                }

                // Clean string values
                $row = array_map(function ($value) {
                    if (is_string($value)) {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                    return $value;
                }, $row);

                // Date preprocessing
                $preprocessDate = function ($dateString, $field, $rowIndex) {
                    if (empty($dateString) || !is_string($dateString)) {
                        return null;
                    }
                    if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s(\d{1,2})(\d{2})$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}";
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    } elseif (preg_match('/^(\d{1})(\d{1})(\d{4})\s(\d{1,2})(\d{2})$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}";
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    }
                    return $dateString;
                };

                // Parse dates
                $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'Y-m-d',
                        'm/d/Y H:i',  // US format first
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd/m/Y H:i',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'j F Y',
                        'j F Y H:i',
                        'j F Y g:i A',
                        'd F Y',
                        'd F Y g:i A'
                    ];
                    foreach ($formats as $format) {
                        try {
                            return Carbon::createFromFormat($format, $dateString)->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$dateString}' with format {$format}");
                        }
                    }

                    try {
                        return Carbon::parse($dateString)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Final fallback failed for {$field}: {$e->getMessage()}");
                        return null;
                    }
                };

                // Define a reusable helper closure (you can move it outside loop)
                $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                    $value = trim((string)($value ?? ''));

                    // Skip invalid placeholders
                    if (
                        $value == '' ||
                        strcasecmp($value, 'null') == 0 ||
                        strcasecmp($value, 'pending') == 0 ||
                        strcasecmp($value, 'active') == 0 ||
                        strcasecmp($value, 'n/a') == 0 ||
                        strcasecmp($value, 'na') == 0 ||
                        strcasecmp($value, '-') == 0
                    ) {
                        Log::channel('import')->debug("Row {$rowIndex}: Skipping {$field} (invalid placeholder: '{$value}')");
                        return null;
                    }

                    try {
                        // Preprocess if defined
                        if (isset($preprocessDate)) {
                            $value = $preprocessDate($value, $field, $rowIndex);
                        }

                        // Parse with your custom logic, fallback to strtotime
                        $parsed = isset($parseDate)
                            ? $parseDate($value, $rowIndex, $field)
                            : date('Y-m-d H:i:s', strtotime($value));

                        if (!$parsed || strtotime($parsed) == false) {
                            throw new \Exception("Invalid date format: '{$value}'");
                        }

                        return $parsed;
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                        return null;
                    }
                };

                $createdAt = $normalizeDate($row['created_at'], 'created_at', $rowIndex);
                $updatedAt = $normalizeDate($row['updated_at'], 'updated_at', $rowIndex);

                // Prepare row for insertion
                $processedRow = [
                    'note_uid' => $row['id'] ? md5($row['id']) : null,
                    'user_id' => $row['user_id'] ?? null,
                    'applicant_id' => $row['applicant_id'],
                    'details' => $row['details'] ?? '',
                    'moved_tab_to' => $row['moved_tab_to'],
                    'status' => isset($row['status']) && strtolower($row['status']) == 'active' ? 1 : 0,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
                $processedData[] = $processedRow;
            }

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $index => $row) {

                            try {
                                ApplicantNote::withoutTimestamps(function () use ($row) {
                                    ApplicantNote::updateOrCreate(['note_uid' => $row['note_uid']], $row);
                                });

                                $successfulRows++;

                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {

                                Log::channel('import')->error("Failed to save row " . ($index + 2) . ": " . $e->getMessage());

                                $failedRows[] = [
                                    'row' => $index + 2,
                                    'error' => $e->getMessage()
                                ];
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::channel('import')->error("Transaction failed for chunk: " . $e->getMessage());
                }
            }


            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            Log::channel('import')->info("Applicant Notes CSV import completed. Successful: {$successfulRows}, Failed: " . count($failedRows));

            return response()->json([
                'message' => 'Applicant Notes CSV import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0]], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('Applicant Notes CSV import failed: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json(['error' => 'An error occurred while processing the CSV: ' . $e->getMessage()], 500);
        }
    }
    public function applicantPivotSaleImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // Check log directory writability
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('Starting Applicant Notes CSV import');

            // Store file with unique timestamped name
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Stream encoding conversion
            $encoding = $this->detectEncoding($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                if ($handleIn == false) {
                    Log::channel('import')->error("Failed to open file for reading: {$filePath}");
                    return response()->json(['error' => 'Failed to read uploaded file.'], 500);
                }
                $handleOut = fopen($tempFile, 'w');
                if ($handleOut == false) {
                    fclose($handleIn);
                    Log::channel('import')->error("Failed to create temporary file: {$tempFile}");
                    return response()->json(['error' => 'Failed to create temporary file.'], 500);
                }
                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }
                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                if ($rowIndex % 100 == 0) {
                    Log::channel('import')->info("Processing row {$rowIndex}");
                }

                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);
                if ($row == false || !isset($row['applicant_id'], $row['applicant_id'])) {
                    Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                    continue;
                }

                // Clean string values
                $row = array_map(function ($value) {
                    if (is_string($value)) {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                    return $value;
                }, $row);

                // Date preprocessing
                $preprocessDate = function ($dateString, $field, $rowIndex) {
                    if (empty($dateString) || !is_string($dateString)) {
                        return null;
                    }
                    if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s(\d{1,2})(\d{2})$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}";
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    } elseif (preg_match('/^(\d{1})(\d{1})(\d{4})\s(\d{1,2})(\d{2})$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}";
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    }
                    return $dateString;
                };

                // Parse dates
                $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'Y-m-d',
                        'm/d/Y H:i',  // US format first
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd/m/Y H:i',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'j F Y',
                        'j F Y H:i',
                        'j F Y g:i A',
                        'd F Y',
                        'd F Y g:i A'
                    ];
                    foreach ($formats as $format) {
                        try {
                            return Carbon::createFromFormat($format, $dateString)->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$dateString}' with format {$format}");
                        }
                    }
                    try {
                        return Carbon::parse($dateString)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Final fallback failed for {$field}: {$e->getMessage()}");
                        return null;
                    }
                };

                // Define a reusable helper closure (you can move it outside loop)
                $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                    $value = trim((string)($value ?? ''));

                    // Skip invalid placeholders
                    if (
                        $value == '' ||
                        strcasecmp($value, 'null') == 0 ||
                        strcasecmp($value, 'pending') == 0 ||
                        strcasecmp($value, 'active') == 0 ||
                        strcasecmp($value, 'n/a') == 0 ||
                        strcasecmp($value, 'na') == 0 ||
                        strcasecmp($value, '-') == 0
                    ) {
                        Log::channel('import')->debug("Row {$rowIndex}: Skipping {$field} (invalid placeholder: '{$value}')");
                        return null;
                    }

                    try {
                        // Preprocess if defined
                        if (isset($preprocessDate)) {
                            $value = $preprocessDate($value, $field, $rowIndex);
                        }

                        // Parse with your custom logic, fallback to strtotime
                        $parsed = isset($parseDate)
                            ? $parseDate($value, $rowIndex, $field)
                            : date('Y-m-d H:i:s', strtotime($value));

                        if (!$parsed || strtotime($parsed) == false) {
                            throw new \Exception("Invalid date format: '{$value}'");
                        }

                        return $parsed;
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                        return null;
                    }
                };

                $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                // Prepare row for insertion
                $processedRow = [
                    'id' => $row['id'],
                    'pivot_uid' => md5($row['id']),
                    'applicant_id' => $row['applicant_id'] ?? null,
                    'sale_id' => $row['sales_id'] ?? null,
                    'is_interested' => $row['is_interested'] == 'yes' ? 1 : 0,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
                $processedData[] = $processedRow;
            }

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $index => $row) {
                            try {
                                ApplicantPivotSale::withoutTimestamps(function () use ($row) {
                                    ApplicantPivotSale::updateOrCreate(['id' => $row['id']], $row);
                                });
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {
                                Log::channel('import')->error("Failed to save row " . ($index + 2) . ": " . $e->getMessage());
                                $failedRows[] = ['row' => $index + 2, 'error' => $e->getMessage()];
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::channel('import')->error("Transaction failed for chunk: " . $e->getMessage());
                }
            }

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            Log::channel('import')->info("Applicant Notes CSV import completed. Successful: {$successfulRows}, Failed: " . count($failedRows));

            return response()->json([
                'message' => 'Applicant Notes CSV import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0]], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('Applicant Notes CSV import failed: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json(['error' => 'An error occurred while processing the CSV: ' . $e->getMessage()], 500);
        }
    }
    public function notesRangeForPivotSaleImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // Check log directory writability
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('Starting Applicant Notes CSV import');

            // Store file with unique timestamped name
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Stream encoding conversion
            $encoding = $this->detectEncoding($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                if ($handleIn == false) {
                    Log::channel('import')->error("Failed to open file for reading: {$filePath}");
                    return response()->json(['error' => 'Failed to read uploaded file.'], 500);
                }
                $handleOut = fopen($tempFile, 'w');
                if ($handleOut == false) {
                    fclose($handleIn);
                    Log::channel('import')->error("Failed to create temporary file: {$tempFile}");
                    return response()->json(['error' => 'Failed to create temporary file.'], 500);
                }
                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }
                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                if ($rowIndex % 100 == 0) {
                    Log::channel('import')->info("Processing row {$rowIndex}");
                }

                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);
                if ($row == false || !isset($row['applicants_pivot_sales_id'], $row['applicants_pivot_sales_id'])) {
                    Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                    continue;
                }

                // Clean string values
                $row = array_map(function ($value) {
                    if (is_string($value)) {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                    return $value;
                }, $row);

                // Date preprocessing
                $preprocessDate = function ($dateString, $field, $rowIndex) {
                    if (empty($dateString) || !is_string($dateString)) {
                        return null;
                    }
                    if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s(\d{1,2})(\d{2})$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}";
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    } elseif (preg_match('/^(\d{1})(\d{1})(\d{4})\s(\d{1,2})(\d{2})$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}";
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    }
                    return $dateString;
                };

                // Parse dates
                $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'Y-m-d',
                        'm/d/Y H:i',  // US format first
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd/m/Y H:i',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'j F Y',
                        'j F Y H:i',
                        'j F Y g:i A',
                        'd F Y',
                        'd F Y g:i A'
                    ];
                    foreach ($formats as $format) {
                        try {
                            return Carbon::createFromFormat($format, $dateString)->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$dateString}' with format {$format}");
                        }
                    }
                    try {
                        return Carbon::parse($dateString)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Final fallback failed for {$field}: {$e->getMessage()}");
                        return null;
                    }
                };

                // Define a reusable helper closure (you can move it outside loop)
                $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                    $value = trim((string)($value ?? ''));

                    // Skip invalid placeholders
                    if (
                        $value == '' ||
                        strcasecmp($value, 'null') == 0 ||
                        strcasecmp($value, 'pending') == 0 ||
                        strcasecmp($value, 'active') == 0 ||
                        strcasecmp($value, 'n/a') == 0 ||
                        strcasecmp($value, 'na') == 0 ||
                        strcasecmp($value, '-') == 0
                    ) {
                        Log::channel('import')->debug("Row {$rowIndex}: Skipping {$field} (invalid placeholder: '{$value}')");
                        return null;
                    }

                    try {
                        // Preprocess if defined
                        if (isset($preprocessDate)) {
                            $value = $preprocessDate($value, $field, $rowIndex);
                        }

                        // Parse with your custom logic, fallback to strtotime
                        $parsed = isset($parseDate)
                            ? $parseDate($value, $rowIndex, $field)
                            : date('Y-m-d H:i:s', strtotime($value));

                        if (!$parsed || strtotime($parsed) == false) {
                            throw new \Exception("Invalid date format: '{$value}'");
                        }

                        return $parsed;
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                        return null;
                    }
                };

                $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                // Prepare row for insertion
                $processedRow = [
                    'id' => $row['id'],
                    'range_uid' => md5($row['id']),
                    'applicants_pivot_sales_id' => $row['applicants_pivot_sales_id'] ?? null,
                    'reason' => $row['reason'] ?? null,
                    'status' => $row['status'] == 'active' ? 1 : 0,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
                $processedData[] = $processedRow;
            }

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $index => $row) {
                            try {
                                NotesForRangeApplicant::withoutTimestamps(function () use ($row) {
                                    NotesForRangeApplicant::updateOrCreate(['id' => $row['id']], $row);
                                });
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {
                                Log::channel('import')->error("Failed to save row " . ($index + 2) . ": " . $e->getMessage());
                                $failedRows[] = ['row' => $index + 2, 'error' => $e->getMessage()];
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::channel('import')->error("Transaction failed for chunk: " . $e->getMessage());
                }
            }

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            Log::channel('import')->info("Pivot Notes Range CSV import completed. Successful: {$successfulRows}, Failed: " . count($failedRows));

            return response()->json([
                'message' => 'Pivot Notes Range CSV import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0]], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('Pivot Notes Range CSV import failed: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json(['error' => 'An error occurred while processing the CSV: ' . $e->getMessage()], 500);
        }
    }
    public function auditsImport(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5242880'
        ]);

        if (!$request->hasFile('csv_file')) {
            return response()->json(['error' => 'No file uploaded'], 422);
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        $file = $request->file('csv_file');
        $filePath = $file->getRealPath();

        // Parse CSV using League\Csv
        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setHeaderOffset(0);

        $processedData = [];
        $failedRows = [];
        $rowIndex = 1;

        foreach ($csv->getRecords() as $row) {
            $rowIndex++;

            try {
                $userId = trim($row['user_id'] ?? '');
                $auditableId = trim($row['auditable_id'] ?? '');
                $auditableType = trim($row['auditable_type'] ?? '');
                $data = trim($row['data'] ?? '');
                $message = trim($row['message'] ?? '');

                if (!$userId || !$auditableId) {
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Missing user_id or auditable_id'];
                    continue;
                }

                // Normalize dates
                $normalizeDate = fn($value) => ($value && strtolower($value) != 'null')
                    ? Carbon::parse($value)->format('Y-m-d H:i:s')
                    : null;

                $createdAt = $normalizeDate($row['created_at']);
                $updatedAt = $normalizeDate($row['updated_at']);

                $processedData[] = [
                    'user_id'        => $userId,
                    'auditable_id'   => $auditableId,
                    'auditable_type' => $auditableType,
                    'data'           => $data,
                    'message'        => $message,
                    'created_at'     => $createdAt,
                    'updated_at'     => $updatedAt,
                ];
            } catch (\Throwable $e) {
                $failedRows[] = ['row' => $rowIndex, 'error' => $e->getMessage()];
            }
        }

        // Insert in chunks
        $successfulRows = 0;
        foreach (array_chunk($processedData, 200) as $chunk) {
            try {
                Audit::withoutTimestamps(function () use ($chunk) {
                    Audit::insert($chunk);
                });

                $successfulRows += count($chunk);
            } catch (\Throwable $e) {
                $failedRows[] = ['row' => 'batch', 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => 'Import completed.',
            'successful_rows' => $successfulRows,
            'failed_rows' => count($failedRows),
            'failed_details' => $failedRows,
        ]);
    }
    public function crmNotesImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            $startTime = microtime(true);
            Log::channel('import')->info('🔹 [crm notes Import] Starting CSV import process...');

            $file = $request->file('csv_file');
            $filename = $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Convert file to UTF-8 if needed
            $content = file_get_contents($filePath);
            $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
            if ($encoding != 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                file_put_contents($filePath, $content);
            }

            // Load CSV with League\CSV
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0); // First row is header
            $csv->setDelimiter(','); // Ensure correct delimiter
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $records = $csv->getRecords();
            $headers = $csv->getHeader();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            // Count total rows
            $totalRows = iterator_count($records);
            Log::channel('import')->info("📊 Total crm notes records in CSV: {$totalRows}");

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            Log::channel('import')->info('🚀 Starting crm notes row-by-row processing...');

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;

                try {
                    // Skip empty rows
                    if (empty(array_filter($row))) {
                        Log::channel('import')->warning("Row {$rowIndex}: Empty row , skipping");
                        continue;
                    }

                    $row = array_pad($row, $expectedColumnCount, null);
                    $row = array_slice($row, 0, $expectedColumnCount);
                    $row = array_combine($headers, $row);
                    if ($row == false || !isset($row['user_id'], $row['applicant_id'], $row['sales_id'])) {
                        Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                        $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                        continue;
                    }

                    // Clean string values
                    $row = array_map(function ($value) {
                        if (is_string($value)) {
                            $value = preg_replace('/\s+/', ' ', trim($value));
                            $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                        }
                        return $value;
                    }, $row);

                    // Date preprocessing
                    $preprocessDate = function ($dateString, $field, $rowIndex) {
                        if (empty($dateString) || !is_string($dateString)) {
                            return null;
                        }

                        // Fix malformed numeric formats (e.g., 1122024 1230)
                        if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s?(\d{1,2})(\d{2})?$/', $dateString, $matches)) {
                            $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} " . ($matches[4] ?? '00') . ":" . ($matches[5] ?? '00');
                            Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                            return $fixedDate;
                        }

                        return $dateString;
                    };

                    // Parse dates (corrected format order)
                    $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                        $formats = [
                            'Y-m-d H:i:s',
                            'Y-m-d H:i',
                            'Y-m-d',
                            'm/d/Y H:i',  // US format first
                            'm/d/Y H:i:s',
                            'm/d/Y',
                            'd/m/Y H:i',
                            'd/m/Y H:i:s',
                            'd/m/Y',
                            'j F Y',
                            'j F Y H:i',
                            'j F Y g:i A',
                            'd F Y',
                            'd F Y g:i A'
                        ];

                        foreach ($formats as $format) {
                            try {
                                return Carbon::createFromFormat($format, $dateString)->format('Y-m-d H:i:s');
                            } catch (\Exception $e) {
                                // Skip silently for cleaner logs
                            }
                        }

                        try {
                            return Carbon::parse($dateString)->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            Log::channel('import')->debug("Row {$rowIndex}: Final fallback failed for {$field}: {$e->getMessage()}");
                            return null;
                        }
                    };

                    // Normalizer (unchanged except keeping created_at & updated_at intact)
                    $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                        $value = trim((string)($value ?? ''));

                        // Skip invalid placeholders
                        if (
                            $value == '' ||
                            in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                        ) {
                            return null;
                        }

                        try {
                            $value = $preprocessDate($value, $field, $rowIndex);
                            $parsed = $parseDate($value, $rowIndex, $field);

                            if (!$parsed || strtotime($parsed) == false) {
                                throw new \Exception("Invalid date format: '{$value}'");
                            }

                            return $parsed;
                        } catch (\Exception $e) {
                            Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                            return null;
                        }
                    };

                    $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                    $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                    // Prepare row for insertion
                    $processedRow = [
                        'id' => $row['id'],
                        'crm_notes_uid' => md5($row['id']),
                        'user_id' => $row['user_id'] ?? null,
                        'applicant_id' => $row['applicant_id'],
                        'sale_id' => $row['sales_id'],
                        'details' => $row['details'] ?? '',
                        'moved_tab_to' => $row['moved_tab_to'] ?? '',
                        'status' => $row['status'] == 'active' ? 1 : 0,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ];
                    $processedData[] = $processedRow;
                } catch (\Throwable $e) {
                    $failedRows[] = ['row' => $rowIndex, 'error' => $e->getMessage()];
                    Log::channel('import')->error("Row {$rowIndex}: Failed processing - {$e->getMessage()}");
                }
            }

            Log::channel('import')->info("✅ Processed {$rowIndex} rows. Total valid: " . count($processedData) . ", Failed: " . count($failedRows));

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunkIndex => $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows, $chunkIndex) {
                        foreach ($chunk as $index => $row) {
                            try {
                                CrmNote::withoutTimestamps(function () use ($row) {
                                    CrmNote::updateOrCreate(['id' => $row['id']], $row);
                                });
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {
                                Log::channel('import')->error("Row : DB insert/update failed for {$row['id']} - {$e->getMessage()}");
                                $failedRows[] = ['row' => $index + 2, 'error' => $e->getMessage()];
                            }
                        }
                    });
                    Log::channel('import')->info("💾 Processed chunk #{$chunkIndex} ({$successfulRows} total)");
                } catch (\Exception $e) {
                    Log::channel('import')->error("Chunk {$chunkIndex}: Transaction failed - {$e->getMessage()}");
                }
            }

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            Log::channel('import')->info("🏁 [Offices Import Summary]");
            Log::channel('import')->info("• Total rows read: {$totalRows}");
            Log::channel('import')->info("• Successfully imported: {$successfulRows}");
            Log::channel('import')->info("• Failed rows: " . count($failedRows));
            Log::channel('import')->info("• Time taken: {$duration} seconds");


            return response()->json([
                'message' => 'CSV import completed successfully!',
                'summary' => [
                    'total_rows' => $totalRows,
                    'successful_rows' => $successfulRows,
                    'failed_rows' => count($failedRows),
                    'failed_details' => $failedRows,
                    'duration_seconds' => $duration,
                ],
            ], 200);
        } catch (\Exception $e) {
            if (file_exists($filePath ?? '')) {
                unlink($filePath);
                Log::channel('import')->info("🗑️ Deleted temporary file after error: {$filePath}");
            }
            Log::channel('import')->error("💥 Import failed: {$e->getMessage()}\nStack trace: {$e->getTraceAsString()}");
            return response()->json([
                'error' => 'CSV import failed: ' . $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }
    public function crmRejectedCvImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // Check log directory writability
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('Starting CRM Rejected Cv CSV import');

            // Store file with unique timestamped name
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Stream encoding conversion
            $encoding = $this->detectEncoding($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                if ($handleIn == false) {
                    Log::channel('import')->error("Failed to open file for reading: {$filePath}");
                    return response()->json(['error' => 'Failed to read uploaded file.'], 500);
                }
                $handleOut = fopen($tempFile, 'w');
                if ($handleOut == false) {
                    fclose($handleIn);
                    Log::channel('import')->error("Failed to create temporary file: {$tempFile}");
                    return response()->json(['error' => 'Failed to create temporary file.'], 500);
                }
                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }
                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                if ($rowIndex % 100 == 0) {
                    Log::channel('import')->info("Processing row {$rowIndex}");
                }

                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);
                if ($row == false || !isset($row['user_id'], $row['applicant_id'], $row['sale_id'])) {
                    Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                    continue;
                }

                // Clean string values
                $row = array_map(function ($value) {
                    if (is_string($value)) {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                    return $value;
                }, $row);

                // Date preprocessing
                $preprocessDate = function ($dateString, $field, $rowIndex) {
                    if (empty($dateString) || !is_string($dateString)) {
                        return null;
                    }

                    // Fix malformed numeric formats (e.g., 1122024 1230)
                    if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s?(\d{1,2})(\d{2})?$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} " . ($matches[4] ?? '00') . ":" . ($matches[5] ?? '00');
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    }

                    return $dateString;
                };

                // Parse dates (corrected format order)
                $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'Y-m-d',
                        'm/d/Y H:i',  // US format first
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd/m/Y H:i',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'j F Y',
                        'j F Y H:i',
                        'j F Y g:i A',
                        'd F Y',
                        'd F Y g:i A'
                    ];

                    foreach ($formats as $format) {
                        try {
                            return Carbon::createFromFormat($format, $dateString)->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            // Skip silently for cleaner logs
                        }
                    }

                    try {
                        return Carbon::parse($dateString)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Final fallback failed for {$field}: {$e->getMessage()}");
                        return null;
                    }
                };

                // Normalizer (unchanged except keeping created_at & updated_at intact)
                $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                    $value = trim((string)($value ?? ''));

                    // Skip invalid placeholders
                    if (
                        $value == '' ||
                        in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                    ) {
                        return null;
                    }

                    try {
                        $value = $preprocessDate($value, $field, $rowIndex);
                        $parsed = $parseDate($value, $rowIndex, $field);

                        if (!$parsed || strtotime($parsed) == false) {
                            throw new \Exception("Invalid date format: '{$value}'");
                        }

                        return $parsed;
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                        return null;
                    }
                };

                $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                // Prepare row for insertion
                $processedRow = [
                    'id' => $row['id'] ?? null,
                    'crm_rejected_cv_uid' => md5($row['id']),
                    'user_id' => $row['user_id'] ?? null,
                    'crm_note_id' => $row['crm_note_id'] ?? null,
                    'applicant_id' => $row['applicant_id'],
                    'sale_id' => $row['sale_id'],
                    'reason' => $row['reason'] ?? '',
                    'crm_rejected_cv_note' => $row['crm_rejected_cv_note'] ?? '',
                    'status' => $row['status'] == 'active' ? 1 : 0,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
                $processedData[] = $processedRow;
            }

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $index => $row) {
                            try {
                                CrmRejectedCv::withoutTimestamps(function () use ($row) {
                                    CrmRejectedCv::updateOrCreate(['id' => $row['id']], $row);
                                });
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {
                                Log::channel('import')->error("Failed to save row " . ($index + 2) . ": " . $e->getMessage());
                                $failedRows[] = ['row' => $index + 2, 'error' => $e->getMessage()];
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::channel('import')->error("Transaction failed for chunk: " . $e->getMessage());
                }
            }

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            Log::channel('import')->info("CRM Rejected Cv CSV import completed. Successful: {$successfulRows}, Failed: " . count($failedRows));

            return response()->json([
                'message' => 'CRM Rejected Cv CSV import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0]], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('CRM Rejected Cv CSV import failed: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json(['error' => 'An error occurred while processing the CSV: ' . $e->getMessage()], 500);
        }
    }
    public function cvNotesImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // Check log directory writability
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('Starting CV Notes CSV import');

            // Store file with unique timestamped name
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Stream encoding conversion
            $encoding = $this->detectEncoding($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                if ($handleIn == false) {
                    Log::channel('import')->error("Failed to open file for reading: {$filePath}");
                    return response()->json(['error' => 'Failed to read uploaded file.'], 500);
                }
                $handleOut = fopen($tempFile, 'w');
                if ($handleOut == false) {
                    fclose($handleIn);
                    Log::channel('import')->error("Failed to create temporary file: {$tempFile}");
                    return response()->json(['error' => 'Failed to create temporary file.'], 500);
                }
                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }
                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                if ($rowIndex % 100 == 0) {
                    Log::channel('import')->info("Processing row {$rowIndex}");
                }

                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);
                if ($row == false || !isset($row['user_id'], $row['applicant_id'], $row['sale_id'])) {
                    Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                    continue;
                }

                // Clean string values
                $row = array_map(function ($value) {
                    if (is_string($value)) {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                    return $value;
                }, $row);

                // Date preprocessing
                $preprocessDate = function ($dateString, $field, $rowIndex) {
                    if (empty($dateString) || !is_string($dateString)) {
                        return null;
                    }

                    // Fix malformed numeric formats (e.g., 1122024 1230)
                    if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s?(\d{1,2})(\d{2})?$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} " . ($matches[4] ?? '00') . ":" . ($matches[5] ?? '00');
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    }

                    return $dateString;
                };

                // Parse dates (corrected format order)
                $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'Y-m-d',
                        'm/d/Y H:i',  // US format first
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd/m/Y H:i',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'j F Y',
                        'j F Y H:i',
                        'j F Y g:i A',
                        'd F Y',
                        'd F Y g:i A'
                    ];

                    foreach ($formats as $format) {
                        try {
                            return Carbon::createFromFormat($format, $dateString)->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            // Skip silently for cleaner logs
                        }
                    }

                    try {
                        return Carbon::parse($dateString)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Final fallback failed for {$field}: {$e->getMessage()}");
                        return null;
                    }
                };

                // Normalizer (unchanged except keeping created_at & updated_at intact)
                $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                    $value = trim((string)($value ?? ''));

                    // Skip invalid placeholders
                    if (
                        $value == '' ||
                        in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                    ) {
                        return null;
                    }

                    try {
                        $value = $preprocessDate($value, $field, $rowIndex);
                        $parsed = $parseDate($value, $rowIndex, $field);

                        if (!$parsed || strtotime($parsed) == false) {
                            throw new \Exception("Invalid date format: '{$value}'");
                        }

                        return $parsed;
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                        return null;
                    }
                };

                $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                /** paid status */
                $status = $row['status'];
                if ($status == 'active') {
                    $statusVal = '1';
                } elseif ($status == 'paid') {
                    $statusVal = '2';
                } elseif ($status == 'open') {
                    $statusVal = '3';
                } else if ($status == 'disable') {
                    $statusVal = '0';
                }

                // Prepare row for insertion
                $processedRow = [
                    'id' => $row['id'],
                    'cv_uid' => md5($row['id']),
                    'user_id' => $row['user_id'] ?? null,
                    'applicant_id' => $row['applicant_id'],
                    'sale_id' => $row['sale_id'],
                    'details' => $row['details'] ?? '',
                    'status' => $statusVal,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];

                $processedData[] = $processedRow;
            }

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $index => $row) {
                            try {
                                CVNote::withoutTimestamps(function () use ($row) {
                                    CVNote::updateOrCreate(['id' => $row['id']], $row);
                                });
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {
                                Log::channel('import')->error("Failed to save row " . ($index + 2) . ": " . $e->getMessage());
                                $failedRows[] = ['row' => $index + 2, 'error' => $e->getMessage()];
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::channel('import')->error("Transaction failed for chunk: " . $e->getMessage());
                }
            }

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            Log::channel('import')->info("CV Notes CSV import completed. Successful: {$successfulRows}, Failed: " . count($failedRows));

            return response()->json([
                'message' => 'CV Notes CSV import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0]], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('CV Notes CSV import failed: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json(['error' => 'An error occurred while processing the CSV: ' . $e->getMessage()], 500);
        }
    }
    public function historyImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // Check log directory writability
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('Starting CV Notes CSV import');

            // Store file with unique timestamped name
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Stream encoding conversion
            $encoding = $this->detectEncoding($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                if ($handleIn == false) {
                    Log::channel('import')->error("Failed to open file for reading: {$filePath}");
                    return response()->json(['error' => 'Failed to read uploaded file.'], 500);
                }
                $handleOut = fopen($tempFile, 'w');
                if ($handleOut == false) {
                    fclose($handleIn);
                    Log::channel('import')->error("Failed to create temporary file: {$tempFile}");
                    return response()->json(['error' => 'Failed to create temporary file.'], 500);
                }
                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }
                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                if ($rowIndex % 100 == 0) {
                    Log::channel('import')->info("Processing row {$rowIndex}");
                }

                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);
                if ($row == false || !isset($row['user_id'], $row['applicant_id'], $row['sale_id'])) {
                    Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                    continue;
                }

                // Clean string values
                $row = array_map(function ($value) {
                    if (is_string($value)) {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                    return $value;
                }, $row);

                // Date preprocessing
                $preprocessDate = function ($dateString, $field, $rowIndex) {
                    if (empty($dateString) || !is_string($dateString)) {
                        return null;
                    }

                    // Fix malformed numeric formats (e.g., 1122024 1230)
                    if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s?(\d{1,2})(\d{2})?$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} " . ($matches[4] ?? '00') . ":" . ($matches[5] ?? '00');
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    }

                    return $dateString;
                };

                // Parse dates (corrected format order)
                $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'Y-m-d',
                        'm/d/Y H:i',  // US format first
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd/m/Y H:i',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'j F Y',
                        'j F Y H:i',
                        'j F Y g:i A',
                        'd F Y',
                        'd F Y g:i A'
                    ];

                    foreach ($formats as $format) {
                        try {
                            return Carbon::createFromFormat($format, $dateString)->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            // Skip silently for cleaner logs
                        }
                    }

                    try {
                        return Carbon::parse($dateString)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Final fallback failed for {$field}: {$e->getMessage()}");
                        return null;
                    }
                };

                // Normalizer (unchanged except keeping created_at & updated_at intact)
                $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                    $value = trim((string)($value ?? ''));

                    // Skip invalid placeholders
                    if (
                        $value == '' ||
                        in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                    ) {
                        return null;
                    }

                    try {
                        $value = $preprocessDate($value, $field, $rowIndex);
                        $parsed = $parseDate($value, $rowIndex, $field);

                        if (!$parsed || strtotime($parsed) == false) {
                            throw new \Exception("Invalid date format: '{$value}'");
                        }

                        return $parsed;
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                        return null;
                    }
                };

                $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                // Prepare row for insertion
                $processedRow = [
                    'id' => $row['id'] ?? null,
                    'history_uid' => md5($row['id']),
                    'user_id' => $row['user_id'] ?? null,
                    'applicant_id' => $row['applicant_id'],
                    'sale_id' => $row['sale_id'],
                    'stage' => $row['stage'] ?? '',
                    'sub_stage' => $row['sub_stage'] ?? '',
                    'status' => $row['status'] == 'active' ? 1 : 0,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
                $processedData[] = $processedRow;
            }

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $index => $row) {
                            try {
                                History::withoutTimestamps(function () use ($row) {
                                    History::updateOrCreate(['id' => $row['id']], $row);
                                });
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {
                                Log::channel('import')->error("Failed to save row " . ($index + 2) . ": " . $e->getMessage());
                                $failedRows[] = ['row' => $index + 2, 'error' => $e->getMessage()];
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::channel('import')->error("Transaction failed for chunk: " . $e->getMessage());
                }
            }

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            Log::channel('import')->info("CV Notes CSV import completed. Successful: {$successfulRows}, Failed: " . count($failedRows));

            return response()->json([
                'message' => 'CV Notes CSV import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0]], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('CV Notes CSV import failed: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json(['error' => 'An error occurred while processing the CSV: ' . $e->getMessage()], 500);
        }
    }
    public function interviewImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            $startTime = microtime(true);
            Log::channel('import')->info('🔹 [Interview Import] Starting CSV import process...');

            // Store file
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");
            Log::channel('import')->info('📂 File stored at: ' . $filePath);

            // Ensure UTF-8 encoding
            $content = file_get_contents($filePath);
            $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
            if ($encoding != 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                file_put_contents($filePath, $content);
                Log::channel('import')->info("✅ Converted file to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);

            // Count total rows
            $totalRows = iterator_count($records);
            Log::channel('import')->info("📊 Total interview records in CSV: {$totalRows}");

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            Log::channel('import')->info('🚀 Starting interview row-by-row processing...');

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                try {
                    // Skip empty rows
                    if (empty(array_filter($row))) {
                        Log::channel('import')->warning("Row {$rowIndex}: Empty row , skipping");
                        continue;
                    }

                    $row = array_pad($row, $expectedColumnCount, null);
                    $row = array_slice($row, 0, $expectedColumnCount);
                    $row = array_combine($headers, $row);
                    if ($row == false || !isset($row['user_id'], $row['applicant_id'], $row['sale_id'])) {
                        Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                        $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                        continue;
                    }

                    // Clean string values
                    $row = array_map(function ($value) {
                        if (is_string($value)) {
                            $value = preg_replace('/\s+/', ' ', trim($value));
                            $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                        }
                        return $value;
                    }, $row);

                    /** ------------------------------
                     *  SCHEDULE DATE NORMALIZATION
                     * ------------------------------ */
                    $schedule_date = null;
                    if (!empty($row['schedule_date'])) {
                        $rawDate = trim($row['schedule_date']);
                        $formats = [
                            'Y-m-d H:i:s',
                            'Y-m-d H:i',
                            'Y-m-d',
                            'm/d/Y H:i',  // US format first
                            'm/d/Y H:i:s',
                            'm/d/Y',
                            'd/m/Y H:i',
                            'd/m/Y H:i:s',
                            'd/m/Y',
                            'j F Y',
                            'j F Y H:i',
                            'j F Y g:i A',
                            'd F Y',
                            'd F Y g:i A'
                        ];
                        foreach ($formats as $fmt) {
                            try {
                                $schedule_date = Carbon::createFromFormat($fmt, $rawDate)->format('Y-m-d');
                                break;
                            } catch (\Exception $e) {
                                continue;
                            }
                        }
                        if (!$schedule_date) {
                            try {
                                $schedule_date = Carbon::parse($rawDate)->format('Y-m-d');
                            } catch (\Exception $e) {
                                Log::channel('import')->warning("Row {$rowIndex}: Invalid schedule_date '{$row['schedule_date']}'");
                            }
                        }
                    }

                    /** ------------------------------
                     *  SCHEDULE TIME NORMALIZATION (robust 24-hour format)
                     * ------------------------------ */
                    // $schedule_time = '00:00'; // Default fallback

                    // if (!empty($row['schedule_time'])) {
                    //     $rawTime = trim(preg_replace('/[[:^print:]]/', '', (string)$row['schedule_time']));
                    //     $originalRaw = $rawTime; // for logging

                    //     // Normalize: lowercase, trim spaces
                    //     $rawTime = strtolower($rawTime);
                    //     $rawTime = preg_replace('/\s+/', ' ', $rawTime);

                    //     // Replace weird symbols with colon
                    //     $rawTime = str_replace([';', ',', '.', '–', '—'], ':', $rawTime);
                    //     $rawTime = preg_replace('/::+/', ':', $rawTime);
                    //     $rawTime = preg_replace('/:+$/', '', $rawTime);
                    //     $rawTime = trim($rawTime);

                    //     // Keep only first part if a range like "11 - 3 pm"
                    //     if (preg_match('/^([^-\(]+)/', $rawTime, $m)) {
                    //         $rawTime = trim($m[1]);
                    //     }

                    //     // Remove parentheses and extra text
                    //     $rawTime = preg_replace('/\(.*?\)/', '', $rawTime);
                    //     $rawTime = trim($rawTime);

                    //     // Handle "12 m" → "12:00"
                    //     if (preg_match('/^12\s*m$/', $rawTime)) {
                    //         $rawTime = '12:00';
                    //     }

                    //     // Fix "1::30" → "1:30"
                    //     $rawTime = str_replace('::', ':', $rawTime);

                    //     // If ends with ":", assume ":00"
                    //     if (preg_match('/^\d{1,2}:$/', $rawTime)) {
                    //         $rawTime .= '00';
                    //     }

                    //     // Handle "11" or "2" → assume hour only
                    //     if (preg_match('/^\d{1,2}$/', $rawTime)) {
                    //         $rawTime .= ':00';
                    //     }

                    //     // Identify AM/PM manually
                    //     $isPM = stripos($originalRaw, 'pm') != false;
                    //     $isAM = stripos($originalRaw, 'am') != false;

                    //     // Remove am/pm markers
                    //     $rawTime = str_ireplace(['am', 'pm', 'a.m', 'p.m', 'a.m.', 'p.m.'], '', $rawTime);
                    //     $rawTime = trim($rawTime);

                    //     try {
                    //         // Try to parse with Carbon
                    //         $parsed = \Carbon\Carbon::createFromFormat('H:i', $rawTime);
                    //     } catch (\Exception $e) {
                    //         try {
                    //             $parsed = \Carbon\Carbon::parse($rawTime);
                    //         } catch (\Exception $e) {
                    //             $parsed = null;
                    //         }
                    //     }

                    //     if ($parsed) {
                    //         // Adjust AM/PM
                    //         $hour = (int) $parsed->format('H');
                    //         if ($isPM && $hour < 12) {
                    //             $parsed->addHours(12);
                    //         } elseif ($isAM && $hour == 12) {
                    //             $parsed->subHours(12);
                    //         }

                    //         // ✅ Store only hour:minute
                    //         $schedule_time = $parsed->format('H:i');
                    //     } else {
                    //         \Log::channel('import')->warning("Row {$rowIndex}: Invalid schedule_time '{$originalRaw}', defaulted to 00:00");
                    //         $schedule_time = '00:00';
                    //     }
                    // }

                    $rawTime = trim($row['schedule_time'] ?? '');
                    $schedule_time = '00:00'; // default

                    if ($rawTime != '') {

                        $originalRaw = $rawTime;

                        // Remove non-printable chars
                        $rawTime = preg_replace('/[[:^print:]]/', '', $rawTime);
                        $rawTime = strtolower($rawTime);
                        $rawTime = preg_replace('/\s+/', ' ', $rawTime);

                        // Replace weird separators with colon
                        $rawTime = str_replace([';', ',', '.', '–', '—'], ':', $rawTime);
                        $rawTime = preg_replace('/::+/', ':', $rawTime);
                        $rawTime = preg_replace('/:+$/', '', $rawTime);
                        $rawTime = trim($rawTime);

                        // Keep first part if it's a range like "11 - 3 pm"
                        if (preg_match('/^([^-\(]+)/', $rawTime, $m)) {
                            $rawTime = trim($m[1]);
                        }

                        // Remove parentheses
                        $rawTime = preg_replace('/\(.*?\)/', '', $rawTime);
                        $rawTime = trim($rawTime);

                        // Handle hour-only inputs like "3" -> "3:00"
                        if (preg_match('/^\d{1,2}$/', $rawTime)) {
                            $rawTime .= ':00';
                        }

                        // Detect AM/PM
                        $originalLower = strtolower($originalRaw);
                        $isPM = strpos($originalLower, 'pm') != false;
                        $isAM = strpos($originalLower, 'am') != false;

                        // Remove AM/PM from string
                        $rawTime = preg_replace('/\s*(a\.?m\.?|p\.?m\.?)/i', '', $rawTime);

                        try {
                            $parsed = \Carbon\Carbon::createFromFormat('H:i', $rawTime);
                        } catch (\Exception $e) {
                            try {
                                $parsed = \Carbon\Carbon::parse($rawTime);
                            } catch (\Exception $e) {
                                $parsed = null;
                            }
                        }

                        if ($parsed) {
                            $hour = (int) $parsed->format('H');

                            // If input had AM/PM, convert to 24-hour
                            if ($isPM && $hour < 12) {
                                $parsed->addHours(12);
                            } elseif ($isAM && $hour == 12) {
                                $parsed->subHours(12);
                            }

                            // If no AM/PM and time is between 12:01 and 7:00, interpret as early morning
                            if (!$isAM && !$isPM) {
                                if ($hour == 12 || $hour <= 7) {
                                    // keep as is in 24-hour
                                    // 12 stays 12, 1-7 stays 01-07
                                } elseif ($hour > 7 && $hour < 12) {
                                    // optional: depends on business logic, maybe consider as morning hours
                                }
                            }

                            // Save HH:MM format
                            $schedule_time = $parsed->format('H:i');
                        } else {
                            Log::channel('import')->warning("Row {$rowIndex}: Invalid schedule_time '{$originalRaw}', defaulted to 00:00");
                            $schedule_time = '00:00';
                        }
                    }

                    /** ------------------------------
                     *  DATE FIELDS NORMALIZATION
                     * ------------------------------ */
                    $preprocessDate = function ($dateString, $field, $rowIndex) {
                        if (empty($dateString) || !is_string($dateString)) {
                            return null;
                        }

                        // Fix malformed numeric formats (e.g., 1122024 1230)
                        if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s?(\d{1,2})(\d{2})?$/', $dateString, $matches)) {
                            $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} " . ($matches[4] ?? '00') . ":" . ($matches[5] ?? '00');
                            Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                            return $fixedDate;
                        }

                        return $dateString;
                    };

                    // Parse dates (corrected format order)
                    $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                        if (empty($dateString)) {
                            return null;
                        }

                        $formats = [
                            'Y-m-d H:i:s',
                            'Y-m-d H:i',
                            'Y-m-d',
                            'm/d/Y H:i',  // US format first
                            'm/d/Y H:i:s',
                            'm/d/Y',
                            'd/m/Y H:i',
                            'd/m/Y H:i:s',
                            'd/m/Y',
                            'j F Y',
                            'j F Y H:i',
                            'j F Y g:i A',
                            'd F Y',
                            'd F Y g:i A'
                        ];

                        foreach ($formats as $format) {
                            try {
                                $dt = Carbon::createFromFormat($format, $dateString);
                                // Log::channel('import')->debug("Row {$rowIndex}: Parsed {$field} '{$dateString}' with format '{$format}'");
                                return $dt->format('Y-m-d H:i:s');
                            } catch (\Exception $e) {
                                // continue
                            }
                        }


                        Log::channel('import')->debug("Row {$rowIndex}: All formats failed for {$field} '{$dateString}'");
                        return null;
                    };

                    // Normalizer (keeps created_at & updated_at null if invalid)
                    $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                        $value = trim((string)($value ?? ''));

                        // Skip invalid placeholders
                        if (
                            $value == '' ||
                            in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                        ) {
                            return null;
                        }

                        try {
                            $value = $preprocessDate($value, $field, $rowIndex);
                            $parsed = $parseDate($value, $rowIndex, $field);

                            if (!$parsed || strtotime($parsed) == false) {
                                throw new \Exception("Invalid date format: '{$value}'");
                            }

                            return $parsed;
                        } catch (\Exception $e) {
                            Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                            return null;
                        }
                    };

                    $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                    $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                    /** ------------------------------
                     *  FINAL RECORD
                     * ------------------------------ */
                    $processedRow = [
                        'id' => $row['id'] ?? null,
                        'interview_uid' => md5($row['id']),
                        'user_id' => $row['user_id'] ?? null,
                        'applicant_id' => $row['applicant_id'],
                        'sale_id' => $row['sale_id'],
                        'details' => $row['details'] ?? '',
                        'status' => strtolower($row['status']) == 'active' ? 1 : 0,
                        'schedule_date' => $schedule_date,
                        'schedule_time' => $row['schedule_time'],
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ];

                    $processedData[] = $processedRow;
                } catch (\Throwable $e) {
                    $failedRows[] = ['row' => $rowIndex, 'error' => $e->getMessage()];
                    Log::channel('import')->error("Row {$rowIndex}: Failed processing - {$e->getMessage()}");
                }
            }

            Log::channel('import')->info("✅ Processed {$rowIndex} rows. Total valid: " . count($processedData) . ", Failed: " . count($failedRows));

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunkIndex => $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows, $chunkIndex) {
                        foreach ($chunk as $index => $row) {
                            $rowIndex = ($chunkIndex * 100) + $index + 2;
                            try {
                                Interview::withoutTimestamps(function () use ($row) {
                                    Interview::updateOrCreate(['id' => $row['id']], $row);
                                });
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Throwable $e) {
                                $failedRows[] = [
                                    'row' => $rowIndex,
                                    'error' => $e->getMessage(),
                                ];
                                Log::channel('import')->error("Row {$rowIndex}: DB insert/update failed for {$row['id']} - {$e->getMessage()}");
                            }
                        }
                    });
                    Log::channel('import')->info("💾 Processed chunk #{$chunkIndex} ({$successfulRows} total)");
                } catch (\Throwable $e) {
                    $failedRows[] = ['chunk' => $chunkIndex, 'error' => $e->getMessage()];
                    Log::channel('import')->error("Chunk {$chunkIndex}: Transaction failed - {$e->getMessage()}");
                }
            }

            // Cleanup
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("🗑️ Deleted temporary file: {$filePath}");
            }

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            Log::channel('import')->info("🏁 [Interview Import Summary]");
            Log::channel('import')->info("• Total rows read: {$totalRows}");
            Log::channel('import')->info("• Successfully imported: {$successfulRows}");
            Log::channel('import')->info("• Failed rows: " . count($failedRows));
            Log::channel('import')->info("• Time taken: {$duration} seconds");

            return response()->json([
                'message' => 'CSV import completed successfully!',
                'summary' => [
                    'total_rows' => $totalRows,
                    'successful_rows' => $successfulRows,
                    'failed_rows' => count($failedRows),
                    'failed_details' => $failedRows,
                    'duration_seconds' => $duration,
                ],
            ], 200);
        } catch (\Exception $e) {
            if (file_exists($filePath ?? '')) {
                unlink($filePath);
                Log::channel('import')->info("🗑️ Deleted temporary file after error: {$filePath}");
            }
            Log::channel('import')->error("💥 Import failed: {$e->getMessage()}\nStack trace: {$e->getTraceAsString()}");
            return response()->json([
                'error' => 'CSV import failed: ' . $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }
    public function ipAddressImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // Check log directory writability
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('Starting Ip Address CSV import');

            // Store file with unique timestamped name
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Stream encoding conversion
            $encoding = $this->detectEncoding($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                if ($handleIn == false) {
                    Log::channel('import')->error("Failed to open file for reading: {$filePath}");
                    return response()->json(['error' => 'Failed to read uploaded file.'], 500);
                }
                $handleOut = fopen($tempFile, 'w');
                if ($handleOut == false) {
                    fclose($handleIn);
                    Log::channel('import')->error("Failed to create temporary file: {$tempFile}");
                    return response()->json(['error' => 'Failed to create temporary file.'], 500);
                }
                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }
                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                if ($rowIndex % 100 == 0) {
                    Log::channel('import')->info("Processing row {$rowIndex}");
                }

                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);
                if ($row == false || !isset($row['user_id'], $row['ip_address'])) {
                    Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                    continue;
                }

                // Clean string values
                $row = array_map(function ($value) {
                    if (is_string($value)) {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                    return $value;
                }, $row);

                // Date preprocessing
                $preprocessDate = function ($dateString, $field, $rowIndex) {
                    if (empty($dateString) || !is_string($dateString)) {
                        return null;
                    }
                    if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s(\d{1,2})(\d{2})$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}";
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    } elseif (preg_match('/^(\d{1})(\d{1})(\d{4})\s(\d{1,2})(\d{2})$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}";
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    }
                    return $dateString;
                };

                // Parse dates
                $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'Y-m-d',
                        'm/d/Y H:i',  // US format first
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd/m/Y H:i',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'j F Y',
                        'j F Y H:i',
                        'j F Y g:i A',
                        'd F Y',
                        'd F Y g:i A'
                    ];
                    foreach ($formats as $format) {
                        try {
                            return Carbon::createFromFormat($format, $dateString)->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$dateString}' with format {$format}");
                        }
                    }
                    try {
                        return Carbon::parse($dateString)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Final fallback failed for {$field}: {$e->getMessage()}");
                        return null;
                    }
                };

                // Define a reusable helper closure (you can move it outside loop)
                $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                    $value = trim((string)($value ?? ''));

                    // Skip invalid placeholders
                    if (
                        $value == '' ||
                        strcasecmp($value, 'null') == 0 ||
                        strcasecmp($value, 'pending') == 0 ||
                        strcasecmp($value, 'active') == 0 ||
                        strcasecmp($value, 'n/a') == 0 ||
                        strcasecmp($value, 'na') == 0 ||
                        strcasecmp($value, '-') == 0
                    ) {
                        Log::channel('import')->debug("Row {$rowIndex}: Skipping {$field} (invalid placeholder: '{$value}')");
                        return null;
                    }

                    try {
                        // Preprocess if defined
                        if (isset($preprocessDate)) {
                            $value = $preprocessDate($value, $field, $rowIndex);
                        }

                        // Parse with your custom logic, fallback to strtotime
                        $parsed = isset($parseDate)
                            ? $parseDate($value, $rowIndex, $field)
                            : date('Y-m-d H:i:s', strtotime($value));

                        if (!$parsed || strtotime($parsed) == false) {
                            throw new \Exception("Invalid date format: '{$value}'");
                        }

                        return $parsed;
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                        return null;
                    }
                };

                $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                // Prepare row for insertion
                $processedRow = [
                    'id' => $row['id'] ?? null,
                    'ip_address' => $row['ip_address'],
                    'user_id' => $row['user_id'] ?? null,
                    'mac_address' => $row['mac_address'],
                    'device_type' => $row['device_type'],
                    'status' => $row['status'] == 'active' ? 1 : 0,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
                $processedData[] = $processedRow;
            }

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $index => $row) {
                            try {
                                IPAddress::updateOrCreate(
                                    ['id' => $row['id']],
                                    $row
                                );
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {
                                Log::channel('import')->error("Failed to save row " . ($index + 2) . ": " . $e->getMessage());
                                $failedRows[] = ['row' => $index + 2, 'error' => $e->getMessage()];
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::channel('import')->error("Transaction failed for chunk: " . $e->getMessage());
                }
            }

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            Log::channel('import')->info("IP Address CSV import completed. Successful: {$successfulRows}, Failed: " . count($failedRows));

            return response()->json([
                'message' => 'IP Address CSV import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0]], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('IP Address CSV import failed: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json(['error' => 'An error occurred while processing the CSV: ' . $e->getMessage()], 500);
        }
    }
    public function moduleNotesImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // 🪵 Ensure log directory writable
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('📥 Starting Module Notes CSV import');

            // 🗂️ Store uploaded file
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('✅ File stored at: ' . $filePath);

            // 🔄 Detect and convert encoding if needed
            $encoding = $this->detectEncodingd($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                $handleOut = fopen($tempFile, 'w');

                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }

                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV from {$encoding} to UTF-8");
            }

            // 🧩 Parse CSV in streaming mode
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);

            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $batchSize = 100;
            $batch = [];
            $successfulRows = 0;
            $failedRows = [];

            $rowIndex = 1;
            foreach ($records as $row) {
                $rowIndex++;

                // Normalize row columns
                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);

                if (!$row || !isset($row['user_id'], $row['module_noteable_id'])) {
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Missing required columns'];
                    continue;
                }

                // Clean up strings
                $row = array_map(function ($val) {
                    if (is_string($val)) {
                        $val = preg_replace('/\s+/', ' ', trim($val));
                        $val = preg_replace('/[^\x20-\x7E]/', '', $val);
                    }
                    return $val;
                }, $row);

                // Date preprocessing
                $preprocessDate = function ($dateString, $field, $rowIndex) {
                    if (empty($dateString) || !is_string($dateString)) {
                        return null;
                    }

                    // Fix malformed numeric formats (e.g., 1122024 1230)
                    if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s?(\d{1,2})(\d{2})?$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} " . ($matches[4] ?? '00') . ":" . ($matches[5] ?? '00');
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    }

                    return $dateString;
                };

                // Parse dates (corrected format order)
                $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'Y-m-d',
                        'm/d/Y H:i',  // US format first
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd/m/Y H:i',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'j F Y',
                        'j F Y H:i',
                        'j F Y g:i A',
                        'd F Y',
                        'd F Y g:i A'
                    ];

                    foreach ($formats as $format) {
                        try {
                            return Carbon::createFromFormat($format, $dateString)->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            // Skip silently for cleaner logs
                        }
                    }

                    try {
                        return Carbon::parse($dateString)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Final fallback failed for {$field}: {$e->getMessage()}");
                        return null;
                    }
                };

                // Normalizer (unchanged except keeping created_at & updated_at intact)
                $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                    $value = trim((string)($value ?? ''));

                    // Skip invalid placeholders
                    if (
                        $value == '' ||
                        in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                    ) {
                        return null;
                    }

                    try {
                        $value = $preprocessDate($value, $field, $rowIndex);
                        $parsed = $parseDate($value, $rowIndex, $field);

                        if (!$parsed || strtotime($parsed) == false) {
                            throw new \Exception("Invalid date format: '{$value}'");
                        }

                        return $parsed;
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                        return null;
                    }
                };

                $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                $batch[] = [
                    'id' => $row['id'] ?? null,
                    'module_note_uid' => md5($row['id'] ?? uniqid()),
                    'user_id' => $row['user_id'],
                    'module_noteable_id' => $row['module_noteable_id'],
                    'module_noteable_type' => $row['module_noteable_type'] ?? '',
                    'details' => $row['details'] ?? '',
                    'status' => ($row['status'] ?? '') == 'active' ? 1 : 0,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];

                // 🧱 Insert batch every 1000 rows
                if (count($batch) >= $batchSize) {
                    $this->insertBatch($batch, $successfulRows, $failedRows);
                    $batch = [];
                }
            }

            // Insert remaining rows
            if (!empty($batch)) {
                $this->insertBatch($batch, $successfulRows, $failedRows);
            }

            // 🧹 Clean up file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("🧽 Deleted temp file: {$filePath}");
            }

            Log::channel('import')->info("✅ Module Notes import complete: {$successfulRows} success, " . count($failedRows) . " failed.");

            return response()->json([
                'message' => 'Module Notes import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0] ?? 'Invalid file'], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('Import failed: ' . $e->getMessage());
            return response()->json(['error' => 'Error: ' . $e->getMessage()], 500);
        }
    }
    private function insertBatch(array $batch, &$successfulRows, array &$failedRows)
    {
        try {
            DB::transaction(function () use ($batch, &$successfulRows, &$failedRows) {
                foreach ($batch as $row) {
                    try {
                        ModuleNote::withoutTimestamps(function () use ($row) {
                            ModuleNote::updateOrCreate(['id' => $row['id']], $row);
                        });
                        $successfulRows++;
                    } catch (\Exception $e) {
                        $failedRows[] = ['id' => $row['id'], 'error' => $e->getMessage()];
                    }
                }
            });
        } catch (\Exception $e) {
            Log::channel('import')->error("Transaction failed: " . $e->getMessage());
        }
    }
    private function detectEncodingd($filePath)
    {
        $sample = file_get_contents($filePath, false, null, 0, 100);
        $encoding = mb_detect_encoding($sample, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        return $encoding ?: 'UTF-8';
    }
    public function qualityNotesImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // Check log directory writability
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('Starting Quality Notes CSV import');

            // Store file with unique timestamped name
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Stream encoding conversion
            $encoding = $this->detectEncoding($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                if ($handleIn == false) {
                    Log::channel('import')->error("Failed to open file for reading: {$filePath}");
                    return response()->json(['error' => 'Failed to read uploaded file.'], 500);
                }
                $handleOut = fopen($tempFile, 'w');
                if ($handleOut == false) {
                    fclose($handleIn);
                    Log::channel('import')->error("Failed to create temporary file: {$tempFile}");
                    return response()->json(['error' => 'Failed to create temporary file.'], 500);
                }
                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }
                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                if ($rowIndex % 100 == 0) {
                    Log::channel('import')->info("Processing row {$rowIndex}");
                }

                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);
                if ($row == false || !isset($row['user_id'], $row['applicant_id'], $row['sale_id'])) {
                    Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                    continue;
                }

                // Clean string values
                $row = array_map(function ($value) {
                    if (is_string($value)) {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                    return $value;
                }, $row);

                // Date preprocessing
                $preprocessDate = function ($dateString, $field, $rowIndex) {
                    if (empty($dateString) || !is_string($dateString)) {
                        return null;
                    }

                    // Fix malformed numeric formats (e.g., 1122024 1230)
                    if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s?(\d{1,2})(\d{2})?$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} " . ($matches[4] ?? '00') . ":" . ($matches[5] ?? '00');
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    }

                    return $dateString;
                };

                // Parse dates (corrected format order)
                $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'Y-m-d',
                        'm/d/Y H:i',  // US format first
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd/m/Y H:i',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'j F Y',
                        'j F Y H:i',
                        'j F Y g:i A',
                        'd F Y',
                        'd F Y g:i A'
                    ];

                    foreach ($formats as $format) {
                        try {
                            return Carbon::createFromFormat($format, $dateString)->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            // Skip silently for cleaner logs
                        }
                    }

                    try {
                        return Carbon::parse($dateString)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Final fallback failed for {$field}: {$e->getMessage()}");
                        return null;
                    }
                };

                // Normalizer (unchanged except keeping created_at & updated_at intact)
                $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                    $value = trim((string)($value ?? ''));

                    // Skip invalid placeholders
                    if (
                        $value == '' ||
                        in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                    ) {
                        return null;
                    }

                    try {
                        $value = $preprocessDate($value, $field, $rowIndex);
                        $parsed = $parseDate($value, $rowIndex, $field);

                        if (!$parsed || strtotime($parsed) == false) {
                            throw new \Exception("Invalid date format: '{$value}'");
                        }

                        return $parsed;
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                        return null;
                    }
                };

                $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                // Prepare row for insertion
                $processedRow = [
                    'id' => $row['id'] ?? null,
                    'quality_notes_uid' => md5($row['id']),
                    'user_id' => $row['user_id'] ?? null,
                    'applicant_id' => $row['applicant_id'],
                    'sale_id' => $row['sale_id'],
                    'moved_tab_to' => $row['moved_tab_to'],
                    'details' => $row['details'] ?? '',
                    'status' => $row['status'] == 'active' ? 1 : 0,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
                $processedData[] = $processedRow;
            }

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $index => $row) {
                            try {
                                QualityNotes::withoutTimestamps(function () use ($row) {
                                    QualityNotes::updateOrCreate(['id' => $row['id']], $row);
                                });
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {
                                Log::channel('import')->error("Failed to save row " . ($index + 2) . ": " . $e->getMessage());
                                $failedRows[] = ['row' => $index + 2, 'error' => $e->getMessage()];
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::channel('import')->error("Transaction failed for chunk: " . $e->getMessage());
                }
            }

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            Log::channel('import')->info("Quality Notes CSV import completed. Successful: {$successfulRows}, Failed: " . count($failedRows));

            return response()->json([
                'message' => 'Quality Notes CSV import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0]], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('Quality Notes CSV import failed: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json(['error' => 'An error occurred while processing the CSV: ' . $e->getMessage()], 500);
        }
    }
    public function regionsImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // Check log directory writability
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('Starting Regions CSV import');

            // Store file with unique timestamped name
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Stream encoding conversion
            $encoding = $this->detectEncoding($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                if ($handleIn == false) {
                    Log::channel('import')->error("Failed to open file for reading: {$filePath}");
                    return response()->json(['error' => 'Failed to read uploaded file.'], 500);
                }
                $handleOut = fopen($tempFile, 'w');
                if ($handleOut == false) {
                    fclose($handleIn);
                    Log::channel('import')->error("Failed to create temporary file: {$tempFile}");
                    return response()->json(['error' => 'Failed to create temporary file.'], 500);
                }
                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }
                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                if ($rowIndex % 100 == 0) {
                    Log::channel('import')->info("Processing row {$rowIndex}");
                }

                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);
                if ($row == false || !isset($row['districts_code'], $row['districts_code'])) {
                    Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                    continue;
                }

                // Clean string values
                $row = array_map(function ($value) {
                    if (is_string($value)) {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                    return $value;
                }, $row);

                // Prepare row for insertion
                $processedRow = [
                    'id' => $row['id'] ?? null,
                    'name' => $row['name'] ?? null,
                    'districts_code' => $row['districts_code']
                ];
                $processedData[] = $processedRow;
            }

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $index => $row) {
                            try {
                                Region::updateOrCreate(
                                    ['id' => $row['id']],
                                    $row
                                );
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {
                                Log::channel('import')->error("Failed to save row " . ($index + 2) . ": " . $e->getMessage());
                                $failedRows[] = ['row' => $index + 2, 'error' => $e->getMessage()];
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::channel('import')->error("Transaction failed for chunk: " . $e->getMessage());
                }
            }

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            Log::channel('import')->info("Regions CSV import completed. Successful: {$successfulRows}, Failed: " . count($failedRows));

            return response()->json([
                'message' => 'Regions CSV import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0]], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('Regions CSV import failed: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json(['error' => 'An error occurred while processing the CSV: ' . $e->getMessage()], 500);
        }
    }
    public function revertStageImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // Check log directory writability
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('Starting Revert Stage CSV import');

            // Store file with unique timestamped name
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Stream encoding conversion
            $encoding = $this->detectEncoding($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                if ($handleIn == false) {
                    Log::channel('import')->error("Failed to open file for reading: {$filePath}");
                    return response()->json(['error' => 'Failed to read uploaded file.'], 500);
                }
                $handleOut = fopen($tempFile, 'w');
                if ($handleOut == false) {
                    fclose($handleIn);
                    Log::channel('import')->error("Failed to create temporary file: {$tempFile}");
                    return response()->json(['error' => 'Failed to create temporary file.'], 500);
                }
                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }
                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                if ($rowIndex % 100 == 0) {
                    Log::channel('import')->info("Processing row {$rowIndex}");
                }

                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);
                if ($row == false || !isset($row['user_id'], $row['applicant_id'], $row['sale_id'])) {
                    Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                    continue;
                }

                // Clean string values
                $row = array_map(function ($value) {
                    if (is_string($value)) {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                    return $value;
                }, $row);

                // Date preprocessing
                $preprocessDate = function ($dateString, $field, $rowIndex) {
                    if (empty($dateString) || !is_string($dateString)) {
                        return null;
                    }

                    // Fix malformed numeric formats (e.g., 1122024 1230)
                    if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s?(\d{1,2})(\d{2})?$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} " . ($matches[4] ?? '00') . ":" . ($matches[5] ?? '00');
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    }

                    return $dateString;
                };

                // Parse dates (corrected format order)
                $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'Y-m-d',
                        'm/d/Y H:i',  // US format first
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd/m/Y H:i',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'j F Y',
                        'j F Y H:i',
                        'j F Y g:i A',
                        'd F Y',
                        'd F Y g:i A'
                    ];

                    foreach ($formats as $format) {
                        try {
                            return Carbon::createFromFormat($format, $dateString)->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            // Skip silently for cleaner logs
                        }
                    }

                    try {
                        return Carbon::parse($dateString)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Final fallback failed for {$field}: {$e->getMessage()}");
                        return null;
                    }
                };

                // Normalizer (unchanged except keeping created_at & updated_at intact)
                $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                    $value = trim((string)($value ?? ''));

                    // Skip invalid placeholders
                    if (
                        $value == '' ||
                        in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                    ) {
                        return null;
                    }

                    try {
                        $value = $preprocessDate($value, $field, $rowIndex);
                        $parsed = $parseDate($value, $rowIndex, $field);

                        if (!$parsed || strtotime($parsed) == false) {
                            throw new \Exception("Invalid date format: '{$value}'");
                        }

                        return $parsed;
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                        return null;
                    }
                };

                $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                // Prepare row for insertion
                $processedRow = [
                    'id' => $row['id'] ?? null,
                    'user_id' => $row['user_id'] ?? null,
                    'applicant_id' => $row['applicant_id'],
                    'sale_id' => $row['sale_id'],
                    'notes' => $row['notes'] ?? '',
                    'stage' => $row['stage'] ?? '',
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
                $processedData[] = $processedRow;
            }

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $index => $row) {
                            try {
                                RevertStage::withoutTimestamps(function () use ($row) {
                                    RevertStage::updateOrCreate(['id' => $row['id']], $row);
                                });
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {
                                Log::channel('import')->error("Failed to save row " . ($index + 2) . ": " . $e->getMessage());
                                $failedRows[] = ['row' => $index + 2, 'error' => $e->getMessage()];
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::channel('import')->error("Transaction failed for chunk: " . $e->getMessage());
                }
            }

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            Log::channel('import')->info("CV Notes CSV import completed. Successful: {$successfulRows}, Failed: " . count($failedRows));

            return response()->json([
                'message' => 'CV Notes CSV import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0]], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('CV Notes CSV import failed: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json(['error' => 'An error occurred while processing the CSV: ' . $e->getMessage()], 500);
        }
    }
    public function saleDocumentsImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // Check log directory writability
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('Starting Sale Documents CSV import');

            // Store file with unique timestamped name
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Stream encoding conversion
            $encoding = $this->detectEncoding($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                if ($handleIn == false) {
                    Log::channel('import')->error("Failed to open file for reading: {$filePath}");
                    return response()->json(['error' => 'Failed to read uploaded file.'], 500);
                }
                $handleOut = fopen($tempFile, 'w');
                if ($handleOut == false) {
                    fclose($handleIn);
                    Log::channel('import')->error("Failed to create temporary file: {$tempFile}");
                    return response()->json(['error' => 'Failed to create temporary file.'], 500);
                }
                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }
                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                if ($rowIndex % 100 == 0) {
                    Log::channel('import')->info("Processing row {$rowIndex}");
                }

                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);
                if ($row == false || !isset($row['sale_id'], $row['document_path'])) {
                    Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                    continue;
                }

                // Clean string values
                $row = array_map(function ($value) {
                    if (is_string($value)) {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                    return $value;
                }, $row);

                // Date preprocessing
                $preprocessDate = function ($dateString, $field, $rowIndex) {
                    if (empty($dateString) || !is_string($dateString)) {
                        return null;
                    }
                    if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s(\d{1,2})(\d{2})$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}";
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    } elseif (preg_match('/^(\d{1})(\d{1})(\d{4})\s(\d{1,2})(\d{2})$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}";
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    }
                    return $dateString;
                };

                // Parse dates
                $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                    // Put the most common format first
                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'Y-m-d',
                        'm/d/Y H:i',  // US format first
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd/m/Y H:i',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'j F Y',
                        'j F Y H:i',
                        'j F Y g:i A',
                        'd F Y',
                        'd F Y g:i A'
                    ];
                    foreach ($formats as $format) {
                        try {
                            return Carbon::createFromFormat($format, $dateString)->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            // Only log verbose debug for unusual cases
                            if (!in_array($format, ['Y-m-d H:i:s', 'Y-m-d'])) {
                                Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$dateString}' with format {$format}");
                            }
                        }
                    }

                    try {
                        return Carbon::parse($dateString)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Final fallback failed for {$field}: {$e->getMessage()}");
                        return null;
                    }
                };

                // Normalize dates
                $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                    $value = trim((string)($value ?? ''));

                    if (
                        $value == '' ||
                        in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                    ) {
                        Log::channel('import')->debug("Row {$rowIndex}: Skipping {$field} (invalid placeholder: '{$value}')");
                        return null;
                    }

                    try {
                        if (isset($preprocessDate)) {
                            $value = $preprocessDate($value, $field, $rowIndex);
                        }

                        $parsed = isset($parseDate)
                            ? $parseDate($value, $rowIndex, $field)
                            : date('Y-m-d H:i:s', strtotime($value));

                        if (!$parsed || strtotime($parsed) == false) {
                            throw new \Exception("Invalid date format: '{$value}'");
                        }

                        return $parsed;
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                        return null;
                    }
                };

                $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                // Prepare row for insertion
                $processedRow = [
                    'id' => $row['id'] ?? null,
                    'user_id' => $row['user_id'] ?? null,
                    'sale_id' => $row['sale_id'],
                    'document_name' => $row['document_name'],
                    'document_path' => $row['document_path'] ?? '',
                    'document_size' => $row['document_size'] ?? '',
                    'document_extension' => $row['document_extension'] ?? '',
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
                $processedData[] = $processedRow;
            }

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $index => $row) {
                            try {
                                SaleDocument::updateOrCreate(
                                    ['id' => $row['id']],
                                    $row
                                );
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {
                                Log::channel('import')->error("Failed to save row " . ($index + 2) . ": " . $e->getMessage());
                                $failedRows[] = ['row' => $index + 2, 'error' => $e->getMessage()];
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::channel('import')->error("Transaction failed for chunk: " . $e->getMessage());
                }
            }

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            Log::channel('import')->info("Sale Documents CSV import completed. Successful: {$successfulRows}, Failed: " . count($failedRows));

            return response()->json([
                'message' => 'Sale Documents CSV import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0]], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('Sale Documents CSV import failed: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json(['error' => 'An error occurred while processing the CSV: ' . $e->getMessage()], 500);
        }
    }
    public function saleNotesImport(Request $request)
    {
        // Set PHP limits
        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // Validate file (115 MB limit, CSV only)
            $request->validate([
                'csv_file' => 'required|file|mimes:csv'
            ]);

            // Check log directory writability
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('Starting Sale Notes CSV import');

            // Store file with unique timestamped name
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Stream encoding conversion
            $encoding = $this->detectEncoding($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                if ($handleIn == false) {
                    Log::channel('import')->error("Failed to open file for reading: {$filePath}");
                    return response()->json(['error' => 'Failed to read uploaded file.'], 500);
                }
                $handleOut = fopen($tempFile, 'w');
                if ($handleOut == false) {
                    fclose($handleIn);
                    Log::channel('import')->error("Failed to create temporary file: {$tempFile}");
                    return response()->json(['error' => 'Failed to create temporary file.'], 500);
                }
                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }
                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                if ($rowIndex % 100 == 0) {
                    Log::channel('import')->info("Processing row {$rowIndex}");
                }

                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);
                if ($row == false || !isset($row['user_id'], $row['sale_id'])) {
                    Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                    continue;
                }

                // Clean string values
                $row = array_map(function ($value) {
                    if (is_string($value)) {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                    return $value;
                }, $row);

                // Date preprocessing
                $preprocessDate = function ($dateString, $field, $rowIndex) {
                    if (empty($dateString) || !is_string($dateString)) {
                        return null;
                    }
                    if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s(\d{1,2})(\d{2})$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}";
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    } elseif (preg_match('/^(\d{1})(\d{1})(\d{4})\s(\d{1,2})(\d{2})$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} {$matches[4]}:{$matches[5]}";
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    }
                    return $dateString;
                };

                // Parse dates
                $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                    // Put the most common format first
                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'Y-m-d',
                        'm/d/Y H:i',  // US format first
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd/m/Y H:i',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'j F Y',
                        'j F Y H:i',
                        'j F Y g:i A',
                        'd F Y',
                        'd F Y g:i A'
                    ];
                    foreach ($formats as $format) {
                        try {
                            return Carbon::createFromFormat($format, $dateString)->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            // Only log verbose debug for unusual cases
                            if (!in_array($format, ['Y-m-d H:i:s', 'Y-m-d'])) {
                                Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$dateString}' with format {$format}");
                            }
                        }
                    }

                    try {
                        return Carbon::parse($dateString)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Final fallback failed for {$field}: {$e->getMessage()}");
                        return null;
                    }
                };

                // Normalize dates
                $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                    $value = trim((string)($value ?? ''));

                    if (
                        $value == '' ||
                        in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                    ) {
                        Log::channel('import')->debug("Row {$rowIndex}: Skipping {$field} (invalid placeholder: '{$value}')");
                        return null;
                    }

                    try {
                        if (isset($preprocessDate)) {
                            $value = $preprocessDate($value, $field, $rowIndex);
                        }

                        $parsed = isset($parseDate)
                            ? $parseDate($value, $rowIndex, $field)
                            : date('Y-m-d H:i:s', strtotime($value));

                        if (!$parsed || strtotime($parsed) == false) {
                            throw new \Exception("Invalid date format: '{$value}'");
                        }

                        return $parsed;
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                        return null;
                    }
                };

                $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);


                // Prepare row for insertion
                $processedRow = [
                    'id' => $row['id'] ?? null,
                    'sales_notes_uid' => md5($row['id']),
                    'user_id' => $row['user_id'] ?? null,
                    'sale_id' => $row['sale_id'],
                    'sale_note' => $row['sale_note'] ?? '',
                    'status' => $row['status'] == 'active' ? 1 : 0,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
                $processedData[] = $processedRow;
            }

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $index => $row) {
                            try {
                                SaleNote::withoutTimestamps(function () use ($row) {
                                    SaleNote::updateOrCreate(['id' => $row['id']], $row);
                                });
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {
                                Log::channel('import')->error("Failed to save row " . ($index + 2) . ": " . $e->getMessage());
                                $failedRows[] = ['row' => $index + 2, 'error' => $e->getMessage()];
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::channel('import')->error("Transaction failed for chunk: " . $e->getMessage());
                }
            }

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            Log::channel('import')->info("Sale Notes CSV import completed. Successful: {$successfulRows}, Failed: " . count($failedRows));

            return response()->json([
                'message' => 'Sale Notes CSV import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0]], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('Sale Notes CSV import failed: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json(['error' => 'An error occurred while processing the CSV: ' . $e->getMessage()], 500);
        }
    }
    public function sentEmailDataImport(Request $request)
    {
        $request->validate([
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'mimes:csv,txt',
                'max:5242880'
            ],
        ]);

        if ($request->hasFile('csv_file')) {
            $ext = strtolower($request->file('csv_file')->getClientOriginalExtension());
            if ($ext !== 'csv') {
                return response()->json([
                    'error' => 'Invalid file type. Please upload a CSV file only.',
                    'success' => false
                ], 422);
            }
        }

        ini_set('max_execution_time', 10000);
        ini_set('memory_limit', '-1');

        try {
            // Check log directory writability
            $logFile = storage_path('logs/laravel.log');
            if (!is_writable(dirname($logFile))) {
                return response()->json(['error' => 'Log directory is not writable.'], 500);
            }

            Log::channel('import')->info('Starting Sent Email CSV import');

            // Store file with unique timestamped name
            $file = $request->file('csv_file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/import_files', $filename);
            $filePath = storage_path("app/{$path}");

            if (!file_exists($filePath)) {
                Log::channel('import')->error("Failed to store file at: {$filePath}");
                return response()->json(['error' => 'Failed to store uploaded file.'], 500);
            }
            Log::channel('import')->info('File stored at: ' . $filePath);

            // Stream encoding conversion
            $encoding = $this->detectEncoding($filePath);
            if ($encoding != 'UTF-8') {
                $tempFile = $filePath . '.utf8';
                $handleIn = fopen($filePath, 'r');
                if ($handleIn == false) {
                    Log::channel('import')->error("Failed to open file for reading: {$filePath}");
                    return response()->json(['error' => 'Failed to read uploaded file.'], 500);
                }
                $handleOut = fopen($tempFile, 'w');
                if ($handleOut == false) {
                    fclose($handleIn);
                    Log::channel('import')->error("Failed to create temporary file: {$tempFile}");
                    return response()->json(['error' => 'Failed to create temporary file.'], 500);
                }
                while (!feof($handleIn)) {
                    $chunk = fread($handleIn, 8192);
                    fwrite($handleOut, mb_convert_encoding($chunk, 'UTF-8', $encoding));
                }
                fclose($handleIn);
                fclose($handleOut);
                unlink($filePath);
                rename($tempFile, $filePath);
                Log::channel('import')->info("Converted CSV to UTF-8 from {$encoding}");
            }

            // Parse CSV with league/csv
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(',');
            $csv->setEnclosure('"');
            $csv->setEscape('\\');

            $headers = $csv->getHeader();
            $records = $csv->getRecords();
            $expectedColumnCount = count($headers);
            Log::channel('import')->info('Headers: ' . json_encode($headers) . ', Count: ' . $expectedColumnCount);

            $processedData = [];
            $failedRows = [];
            $successfulRows = 0;
            $rowIndex = 1;

            // Process CSV rows
            foreach ($records as $row) {
                $rowIndex++;
                if ($rowIndex % 100 == 0) {
                    Log::channel('import')->info("Processing row {$rowIndex}");
                }

                $row = array_pad($row, $expectedColumnCount, null);
                $row = array_slice($row, 0, $expectedColumnCount);
                $row = array_combine($headers, $row);
                if ($row == false || !isset($row['sent_from'], $row['sent_to'])) {
                    Log::channel('import')->warning("Skipped row {$rowIndex}: Invalid or incomplete data.");
                    $failedRows[] = ['row' => $rowIndex, 'error' => 'Invalid or incomplete data'];
                    continue;
                }

                // Clean string values
                $row = array_map(function ($value) {
                    if (is_string($value)) {
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
                    }
                    return $value;
                }, $row);

                // Date preprocessing
                $preprocessDate = function ($dateString, $field, $rowIndex) {
                    if (empty($dateString) || !is_string($dateString)) {
                        return null;
                    }

                    // Fix malformed numeric formats (e.g., 1122024 1230)
                    if (preg_match('/^(\d{1,2})(\d{2})(\d{4})\s?(\d{1,2})(\d{2})?$/', $dateString, $matches)) {
                        $fixedDate = "{$matches[1]}/{$matches[2]}/{$matches[3]} " . ($matches[4] ?? '00') . ":" . ($matches[5] ?? '00');
                        Log::channel('import')->debug("Row {$rowIndex}: Fixed malformed {$field} from '{$dateString}' to '{$fixedDate}'");
                        return $fixedDate;
                    }

                    return $dateString;
                };

                // Parse dates (corrected format order)
                $parseDate = function ($dateString, $rowIndex, $field = 'created_at') {
                    if (empty($dateString)) {
                        return null;
                    }

                    $formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d H:i',
                        'Y-m-d',
                        'm/d/Y H:i',  // US format first
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd/m/Y H:i',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'j F Y',
                        'j F Y H:i',
                        'j F Y g:i A',
                        'd F Y',
                        'd F Y g:i A'
                    ];

                    foreach ($formats as $format) {
                        try {
                            $dt = Carbon::createFromFormat($format, $dateString);
                            // Log::channel('import')->debug("Row {$rowIndex}: Parsed {$field} '{$dateString}' with format '{$format}'");
                            return $dt->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            // continue
                        }
                    }


                    Log::channel('import')->debug("Row {$rowIndex}: All formats failed for {$field} '{$dateString}'");
                    return null;
                };

                // Normalizer (keeps created_at & updated_at null if invalid)
                $normalizeDate = function ($value, $field, $rowIndex) use ($preprocessDate, $parseDate) {
                    $value = trim((string)($value ?? ''));

                    // Skip invalid placeholders
                    if (
                        $value == '' ||
                        in_array(strtolower($value), ['null', 'pending', 'active', 'n/a', 'na', '-'])
                    ) {
                        return null;
                    }

                    try {
                        $value = $preprocessDate($value, $field, $rowIndex);
                        $parsed = $parseDate($value, $rowIndex, $field);

                        if (!$parsed || strtotime($parsed) == false) {
                            throw new \Exception("Invalid date format: '{$value}'");
                        }

                        return $parsed;
                    } catch (\Exception $e) {
                        Log::channel('import')->debug("Row {$rowIndex}: Failed to parse {$field} '{$value}' — {$e->getMessage()}");
                        return null;
                    }
                };

                $createdAt = $normalizeDate($row['created_at'] ?? null, 'created_at', $rowIndex);
                $updatedAt = $normalizeDate($row['updated_at'] ?? null, 'updated_at', $rowIndex);

                // Prepare row for insertion
                $processedRow = [
                    'id' => $row['id'],
                    'user_id' => $row['user_id'] ?? null,
                    'applicant_id' => $row['applicant_id'] != 'NULL' ? $row['applicant_id'] : null,
                    'sale_id' => $row['sale_id'] != 'NULL' ? $row['sale_id'] : null,
                    'action_name' => $row['action_name'] ?? '',
                    'sent_from' => $row['sent_from'] ?? '',
                    'sent_to' => $row['sent_to'] ?? '',
                    'cc_emails' => $row['cc_emails'] ?? '',
                    'subject' => $row['subject'] ?? '',
                    'title' => $row['title'] ?? '',
                    'template' => $row['template'] ?? '',
                    'status' => $row['status'] == '1' ? '1' : '0',
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
                $processedData[] = $processedRow;
            }

            // Insert rows in batches
            foreach (array_chunk($processedData, 100) as $chunk) {
                try {
                    DB::transaction(function () use ($chunk, &$successfulRows, &$failedRows) {
                        foreach ($chunk as $index => $row) {
                            try {
                                SentEmail::withoutTimestamps(function () use ($row) {
                                    SentEmail::updateOrCreate(['id' => $row['id']], $row);
                                });
                                $successfulRows++;
                                if (($index + 1) % 100 == 0) {
                                    Log::channel('import')->info("Processed " . ($index + 1) . " rows in chunk");
                                }
                            } catch (\Exception $e) {
                                Log::channel('import')->error("Failed to save row " . ($index + 2) . ": " . $e->getMessage());
                                $failedRows[] = ['row' => $index + 2, 'error' => $e->getMessage()];
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::channel('import')->error("Transaction failed for chunk: " . $e->getMessage());
                }
            }

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::channel('import')->info("Deleted temporary file: {$filePath}");
            }

            Log::channel('import')->info("Sent Email CSV import completed. Successful: {$successfulRows}, Failed: " . count($failedRows));

            return response()->json([
                'message' => 'Sent Email CSV import completed.',
                'successful_rows' => $successfulRows,
                'failed_rows' => count($failedRows),
                'failed_details' => $failedRows,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('import')->error('Validation failed: ' . json_encode($e->errors()));
            return response()->json(['error' => $e->errors()['csv_file'][0]], 422);
        } catch (\Exception $e) {
            Log::channel('import')->error('Sent Email CSV import failed: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            if (isset($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json(['error' => 'An error occurred while processing the CSV: ' . $e->getMessage()], 500);
        }
    }
    protected function detectEncoding($filePath)
    {
        $handle = fopen($filePath, 'r');
        if ($handle == false) {
            Log::channel('import')->error("Failed to open file for encoding detection: {$filePath}");
            throw new \Exception('Unable to open file for encoding detection.');
        }
        $sample = fread($handle, 4096);
        fclose($handle);
        return mb_detect_encoding($sample, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) ?: 'UTF-8';
    }
    public function applicantsProcessFile(Request $request)
    {

        // $request->validate([
        //     'file' => 'required|file|mimes:pdf,doc,docx|max:2048',
        //     'keywords' => 'required|string',
        // ]);

        $file = $request->file('process_file');
        $keywords = explode(',', $request->input('keywords'));
        $keywords = array_map('trim', $keywords);

        // Extract text based on file type
        $text = $this->extractText($file);
        return $text;
        if (!$text) {
            return back()->with('error', 'Unable to extract text from the file.');
        }

        // Search for keywords
        $foundKeywords = $this->searchKeywords($text, $keywords);

        // Save to database
        $document = $this->saveToDatabase($file, $foundKeywords);

        return back()->with('success', 'File processed successfully. Found keywords: ' . implode(', ', $foundKeywords));
    }
    /** PRIVATE FUNCTIONS */
    private function extractText($file)
    {
        $extension = $file->getClientOriginalExtension();
        $path = $file->store('documents');

        if ($extension == 'pdf') {
            // try {
            return Pdf::getText(Storage::path($path), 'C:\poppler\bin\pdftotext.exe'); // Adjust path if needed
            // } catch (\Exception $e) {
            //     Log::channel('import')->error('PDF text extraction failed: ' . $e->getMessage());
            //     return null;
            // }
        } elseif (in_array($extension, ['doc', 'docx'])) {
            try {
                $phpWord = IOFactory::load(Storage::path($path));
                $text = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        if (method_exists($element, 'getText')) {
                            // $text .= $element->getText() . ' ';
                        }
                    }
                }
                return $text;
            } catch (\Exception $e) {
                Log::channel('import')->error('DOC text extraction failed: ' . $e->getMessage());
                return null;
            }
        } elseif ($extension == 'csv') {
            try {
                $csv = Reader::createFromPath(Storage::path($path), 'r');
                $csv->setHeaderOffset(0); // Assumes first row is header, adjust if needed
                $text = '';
                foreach ($csv->getRecords() as $record) {
                    $text .= implode(' ', $record) . ' ';
                }
                return $text;
            } catch (\Exception $e) {
                Log::channel('import')->error('CSV text extraction failed: ' . $e->getMessage());
                return null;
            }
        } elseif (in_array($extension, ['xlsx', 'xls'])) {
            try {
                $sheets = Excel::toArray([], Storage::path($path));
                $text = '';
                foreach ($sheets as $sheet) {
                    foreach ($sheet as $row) {
                        $text .= implode(' ', array_filter($row, fn($cell) => !is_null($cell))) . ' ';
                    }
                }
                return $text;
            } catch (\Exception $e) {
                Log::channel('import')->error('Excel text extraction failed: ' . $e->getMessage());
                return null;
            }
        }

        return null;
    }
    private function searchKeywords($text, $keywords)
    {
        $keywords = ['skills', 'qualification', 'education', 'name', 'contact', 'phone', 'experience', 'postcode'];
        $found = [];
        foreach ($keywords as $keyword) {
            if (stripos($text, $keyword) != false) {
                $found[] = $keyword;
            }
        }
        return $found;
    }
    private function saveToDatabase($file, $foundKeywords)
    {
        return Applicant::create([
            'job_category_id',
            'job_type',
            'job_title_id',
            'job_source_id',
            'applicant_name',
            'applicant_email',
            'applicant_email_secondary',
            'applicant_postcode',
            'applicant_phone',
            'applicant_landline',
            'applicant_experience',
            'applicant_notes',
            'have_nursing_home_experience',
            'gender',
        ]);
    }
}

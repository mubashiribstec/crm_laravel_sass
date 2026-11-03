<?php

namespace App\Http\Controllers;

use App\Support\ApplicantAgeWindows;
use App\Services\Xplogent\SaleInterestService;
use Illuminate\Http\Request;
use App\Support\CvStorage;

use Horsefly\Sale;
use Horsefly\Office;
use Horsefly\ApplicantNote;
use Horsefly\ApplicantPivotSale;
use Horsefly\NotesForRangeApplicant;
use Horsefly\Applicant;
use Horsefly\JobCategory;
use Horsefly\JobSource;
use Horsefly\Setting;
use Horsefly\CrmNote;
use Horsefly\JobTitle;
use Horsefly\User;
use Horsefly\ModuleNote;

use App\Exports\EmailExport;

use App\Support\DialLink;

use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Controllers\Controller;
use Yajra\DataTables\Facades\DataTables;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use App\Traits\HidesPrivateContacts;

use Exception;

class ResourceController extends Controller
{
    use HidesPrivateContacts;

    public function __construct()
    {
        $this->middleware('permission:resource-direct-index')->only(['directIndex', 'getResourcesDirectSales']);
        $this->middleware('permission:resource-indirect-index')->only(['indirectIndex', 'getResourcesIndirectApplicants']);
        $this->middleware('permission:resource-rejected-index')->only(['rejectedApplicantsIndex', 'getResourcesRejectedApplicants']);
        $this->middleware('permission:resource-blocked-index')->only(['blockedApplicantsIndex', 'getResourcesBlockedApplicants']);
        $this->middleware('permission:resource-crm-paid-index')->only(['crmPaidIndex', 'getResourcesPaidApplicants']);
        $this->middleware('permission:resource-no-job-index')->only(['noJobIndex', 'getResourcesNoJobApplicants']);
        $this->middleware('permission:resource-not-interested-index')->only(['notInterestedIndex', 'getResourcesNotInterestedApplicants']);
        $this->middleware('permission:resource-category-index')->only(['categoryWiseApplicantIndex', 'getResourcesCategoryWised']);
        $this->middleware('permission:resource-blocked-mark-unblock')->only(['revertBlockedApplicant']);
        $this->middleware('permission:resource-no-job-revert-btn')->only(['revertNoJobApplicant']);
        $this->middleware('permission:resource-not-interested-revert')->only(['revertNotInterestedApplicant']);
        $this->middleware('permission:resource-indirect-add-updated-data-btn')->only(['markAsNursingHomeExp']);
        $this->middleware('permission:resource-category-create-nursing-home-btn')->only(['markAsNoNursingHomeExp']);
        $this->middleware('permission:resource-direct-send-email-btn')->only(['exportDirectApplicantsEmails']);
        $this->middleware('permission:resource-indirect-view-notes-history,resource-rejected-view-notes-history,resource-blocked-view-notes-history,resource-no-job-view-notes-history,resource-not-interested-view-notes-history,resource-category-view-notes-history,resource-crm-paid-view-notes-history')->only(['getApplicantHistorybyStatus']);
        $this->middleware('permission:resource-category-add-note,resource-indirect-add-note')->only(['markApplicantNotInterestedOnSale', 'markApplicantCallback']);
    }
    public function directIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();
        // The office filter must not list offices hidden by private notes.
        $offices = $this->excludePrivateNotes(
            Office::where('status', 1)->orderBy('office_name', 'asc'),
            'offices.office_notes'
        )->get();
        $users = User::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('resources.direct', compact('jobCategories', 'jobTitles', 'offices', 'users', 'jobSources'));
    }
    public function indirectIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('resources.indirect', compact('jobCategories', 'jobTitles', 'jobSources'));
    }
    public function blockedApplicantsIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('resources.blocked-applicants', compact('jobCategories', 'jobTitles', 'jobSources'));
    }
    public function rejectedApplicantsIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('resources.rejected-applicants', compact('jobCategories', 'jobTitles', 'jobSources'));
    }
    public function crmPaidIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('resources.crm-paid-applicants', compact('jobCategories', 'jobTitles', 'jobSources'));
    }
    public function noJobIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('resources.no-job-applicants', compact('jobCategories', 'jobTitles', 'jobSources'));
    }
    public function notInterestedIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('resources.not-interested-applicants', compact('jobCategories', 'jobTitles', 'jobSources'));
    }
    public function categoryWiseApplicantIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        $query = JobSource::where('is_active', 1);

        if (count($sourceIds) > 0) {
            $query->whereNotIn('id', $sourceIds);
        }

        $jobSources = $query->orderBy('name', 'asc')->get();

        return view('resources.category-wise-applicants', compact('jobCategories', 'jobTitles', 'jobSources'));
    }
    /** ajax functions */
    public function getResourcesDirectSales(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $sourceFilter = $request->input('source_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)
        $filterBySaleDate = $request->input('date_range_filter', ''); // Default is empty (no filter)

        $model = Sale::query()
            ->select([
                'sales.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'job_sources.name as job_source_name',
            ])
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'sales.job_source_id', '=', 'job_sources.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->with(['jobTitle', 'jobCategory', 'unit', 'office', 'user'])
            ->selectRaw(DB::raw("(SELECT COUNT(*) FROM cv_notes WHERE cv_notes.sale_id = sales.id AND cv_notes.status = 1) as no_of_sent_cv"))
            ->where('sales.status', 1)
            ->where('sales.is_on_hold', 0);

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');

        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        // Hide sales whose office/unit notes contain a private word (users without show-private-data).
        $this->excludePrivateOfficeUnitNotes($model);

        // Filter by type if it's not empty
        switch ($typeFilter) {
            case 'specialist':
                $model->where('sales.job_type', 'specialist');
                break;
            case 'regular':
                $model->where('sales.job_type', 'regular');
                break;
        }

        if ($filterBySaleDate) {
            [$start_date, $end_date] = explode('|', $filterBySaleDate);
            $start_date = Carbon::parse(trim($start_date))->startOfDay();
            $end_date = Carbon::parse(trim($end_date))->endOfDay();

            $model->whereBetween('sales.created_at', [$start_date, $end_date]);
        }

        // Filter by head office if it's not empty
        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }

        // Filter by category if it's not empty
        if ($limitCountFilter) {
            if ($limitCountFilter == 'zero') {
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
            } elseif ($limitCountFilter == 'not max') {
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
            } elseif ($limitCountFilter == 'max') {
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw(
                        'count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
            }
        }

        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }

        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        // Filter by job source if it's not empty
        if ($sourceFilter) {
            $model->whereIn('sales.job_source_id', (array) $sourceFilter);
        }

        if ($request->has('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            if (!empty($searchTerm)) {
                $model->where(function ($query) use ($searchTerm) {
                    $likeSearch = "%{$searchTerm}%";

                    $query->whereRaw('LOWER(sales.sale_postcode) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.experience) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.timing) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.job_description) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.job_type) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.position_type) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.cv_limit) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.salary) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.benefits) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.qualification) LIKE ?', [$likeSearch]);

                    // Relationship searches with explicit table names
                    $query->orWhereHas('jobTitle', function ($q) use ($likeSearch) {
                        $q->where('job_titles.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('jobCategory', function ($q) use ($likeSearch) {
                        $q->where('job_categories.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('unit', function ($q) use ($likeSearch) {
                        $q->where('units.unit_name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('office', function ($q) use ($likeSearch) {
                        $q->where('offices.office_name', 'LIKE', "%{$likeSearch}%");
                    });
                });
            }
        }

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn === 'job_source') {
                $model->orderBy('sales.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('sales.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('sales.job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('sales.created_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('sales.created_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn('office_name', function ($sale) {
                    $office_name = $sale->office_name;
                    return $office_name ? ucwords($office_name) : '-';
                })
                ->addColumn('unit_name', function ($sale) {
                    $unit_name = $sale->unit_name;
                    return $unit_name ? ucwords($unit_name) : '-';
                })
                ->addColumn('job_title', function ($sale) {
                    return $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
                })
                ->addColumn('job_source', function ($sale) {
                    return $sale->job_source_name
                        ? '<span class="badge bg-light text-dark">' . e(ucwords($sale->job_source_name)) . '</span>'
                        : '-';
                })
                ->addColumn('sale_postcode', function ($sale) {
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                            data-postcode="' . e($sale->formatted_postcode) . '" title="Copy Postcode">
                            <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                        </button>';

                    if ($sale->lat != null && $sale->lng != null) {
                        $url = url('/sales/fetch-applicants-by-radius/' . $sale->id . '/15');
                        $button = '<a target="_blank" href="' . $url . '" class="active_postcode">' . $sale->formatted_postcode . '</a>';
                        return '<div class="d-flex align-items-center justify-content-between">' . $button . $copyBtn . '</div>';
                    } else {
                        return '<div class="d-flex align-items-center justify-content-between"><span>' . $sale->formatted_postcode . '</span>' . $copyBtn . '</div>';
                    }
                })
                ->addColumn('position_type', function ($sale) {
                    // Imported/scraped sales may use "Full-Time", "full_time", etc.
                    $position = trim(preg_replace('/[\s_-]+/', ' ', strtolower((string) $sale->position_type)));

                    if ($position === '') {
                        return '-';
                    }

                    $badge = match ($position) {
                        'full time' => 'bg-primary',
                        'part time' => 'bg-info',
                        default => 'bg-secondary',
                    };

                    return "<span class='badge w-100 {$badge}'>" . e(ucwords($position)) . '</span>';
                })
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at; // Using accessor
                })
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >' . $sale->no_of_sent_cv . '/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int)$sale->cv_limit - (int)$sale->no_of_sent_cv . '/' . (int)$sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })
                ->addColumn('qualification', function ($sale) {
                    $fullHtml = $sale->qualification; // HTML from Summernote
                    $id = 'qua-' . $sale->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 80);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="javascript:void(0);"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Qualification</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . $fullHtml . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('experience', function ($sale) {
                    $fullHtml = $sale->experience; // HTML from Summernote
                    $id = 'exp-' . $sale->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 80);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="javascript:void(0);"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . $fullHtml . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('salary', function ($sale) {
                    $fullHtml = $sale->salary; // HTML from Summernote
                    $id = 'slry-' . $sale->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 80);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="javascript:void(0);"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Salary</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . $fullHtml . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('action', function ($sale) {
                    $is_disable = ($sale->lat == null || $sale->lng == null);

                    $url = route('emails.sendemailstoapplicants', ['sale_id' => $sale->id]);

                    if ($is_disable) {
                        // Disabled button (not a link)
                        $action = '<button class="btn btn-sm btn-success" disabled title="Coordinates missing" style="width:150px">
                                    <iconify-icon icon="mdi:email-send-outline" class="align-middle"></iconify-icon> Send Email
                                </button>';
                    } else {
                        // Active link styled as button
                        $action = '<a href="' . $url . '" title="Send Email" style="width:150px" class="btn btn-sm btn-success">
                                    <iconify-icon icon="mdi:email-send-outline" class="align-middle"></iconify-icon> Send Email
                                </a>';
                    }

                    return '<div class="btn-group dropstart">' . $action . '</div>';
                })
                ->rawColumns(['sale_notes', 'position_type', 'job_source', 'experience', 'salary', 'qualification', 'sale_postcode', 'job_title', 'cv_limit', 'open_date', 'job_category', 'office_name', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    public function getResourcesIndirectApplicants(Request $request)
    {
        // --- Validation ---
        $validated = $request->validate([
            'category_filter' => 'nullable|array',
            'category_filter.*' => 'exists:job_categories,id',
            'status_filter' => 'nullable|in:all,interested,not interested',
            'date_range_filter' => 'nullable|string',
            'source_filter' => 'nullable|array',
            'source_filter.*' => 'exists:job_sources,id',
            'title_filter' => 'nullable|array',
            'title_filter.*' => 'exists:job_titles,id',
            'type_filter' => 'nullable|string',
        ]);

        // --- Extract filters ---
        $filterByUpdatedSale = $request->updated_sales_filter ?? false;
        $categoryFilter = $validated['category_filter'] ?? [];
        $sourceFilter = $validated['source_filter'] ?? [];
        $titleFilter = $validated['title_filter'] ?? [];
        $typeFilter = strtolower(trim((string) ($validated['type_filter'] ?? '')));
        $status = $validated['status_filter'] ?? 'all';
        $dateRange = $validated['date_range_filter']
            ?? Carbon::today()->format('Y-m-d') . '|' . Carbon::today()->format('Y-m-d');

        $radius = 15; // kilometers

        // --- Handle date range safely ---
        try {
            [$start_date, $end_date] = explode('|', $dateRange);
            $start_date = Carbon::parse($start_date)->startOfDay();
            $end_date = Carbon::parse($end_date)->endOfDay();
        } catch (Exception $e) {
            Log::warning('Invalid date range filter: ' . $dateRange, ['error' => $e->getMessage()]);
            $start_date = Carbon::today()->startOfDay();
            $end_date = Carbon::today()->endOfDay();
        }

        // --- Sortable columns mapping ---
        $sortableColumns = [
            'applicant_name' => 'applicants.applicant_name',
            'applicant_postcode' => 'applicants.applicant_postcode',
            'applicant_job_title' => 'job_titles.name',
            'updated_at' => 'applicants.updated_at',
            'job_category' => 'job_categories.name',
            'job_source' => 'job_sources.name',
            'status' => 'applicants.paid_status',
        ];

        $orderColumnIndex = $request->input('order.0.column', 0);
        $orderColumn = array_keys($sortableColumns)[$orderColumnIndex] ?? 'applicant_name';
        $orderDirection = in_array(strtolower($request->input('order.0.dir', 'desc')), ['asc', 'desc'])
            ? $request->input('order.0.dir')
            : 'desc';

        // --- Subquery for latest module_notes per applicant ---
        $latestNotesSub = DB::table('module_notes as mn')
            ->select('mn.id', 'mn.module_noteable_id', 'mn.user_id', 'mn.details', 'mn.created_at')
            ->where('mn.module_noteable_type', Applicant::class)
            ->whereIn('mn.id', function ($sub) {
                $sub->select(DB::raw('MAX(id)'))
                    ->from('module_notes')
                    ->where('module_noteable_type', Applicant::class)
                    ->groupBy('module_noteable_id');
            });

        // --- Sales Query (filtered by audits + updated/created logic) ---
        $salesQuery = Sale::query()
            ->select(['sales.id', 'sales.lat', 'sales.lng', 'sales.job_title_id'])
            ->where('sales.status', 1)
            ->where('sales.is_on_hold', 0)
            ->where(function ($query) use ($filterByUpdatedSale, $start_date, $end_date) {
                $query->whereExists(function ($q) use ($start_date, $end_date) {
                    $q->from('audits')
                        ->whereColumn('audits.auditable_id', 'sales.id')
                        ->where('audits.auditable_type', Sale::class)
                        ->where('audits.message', 'like', '%sale-opened%')
                        ->whereBetween('audits.updated_at', [$start_date, $end_date]);
                });

                $query->orWhere(function ($subQuery) use ($filterByUpdatedSale, $start_date, $end_date) {
                    if ($filterByUpdatedSale) {
                        // Checkbox checked → use updated_at OR created_at
                        $subQuery->where(function ($q) use ($start_date, $end_date) {
                            $q->whereBetween('sales.updated_at', [$start_date, $end_date])
                                ->orWhereBetween('sales.created_at', [$start_date, $end_date]);
                        });
                    } else {
                        // Default → created_at only
                        $subQuery->whereBetween('sales.created_at', [$start_date, $end_date]);
                    }
                });
            })
            ->distinct();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $salesQuery->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        // Sales hidden by private office/unit notes don't bring their nearby applicants in.
        $this->excludePrivateSales($salesQuery, 'sales.id');

        $salesData = $salesQuery->get();

        // --- Early return if no sales found ---
        if ($salesData->isEmpty()) {
            return DataTables::of(collect())->with('total_sale_count', 0)->make(true);
        }

        // --- Get related job title IDs ---
        $jobTitleIds = $salesData->pluck('job_title_id')->unique()->flatMap(function ($jobTitleId) {
            $jobTitle = JobTitle::find($jobTitleId);
            if (!$jobTitle) return [$jobTitleId];

            $relatedTitles = is_array($jobTitle->related_titles)
                ? $jobTitle->related_titles
                : json_decode($jobTitle->related_titles ?? '[]', true);

            $titles = collect($relatedTitles)
                ->map(fn($t) => strtolower(trim($t)))
                ->push(strtolower(trim($jobTitle->name)))
                ->unique();

            return JobTitle::whereIn(DB::raw('LOWER(name)'), $titles)->pluck('id');
        })->unique()->values()->toArray();

        // --- Main Applicants Query ---
        $query = Applicant::query()
            ->select([
                'applicants.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name',
                'users.name as user_name',
                'module_notes.details as module_notes_details',
                'module_notes.created_at as module_notes_created',
            ])
            ->leftJoinSub($latestNotesSub, 'module_notes', fn($join) => $join->on('applicants.id', '=', 'module_notes.module_noteable_id'))
            ->leftJoin('users', 'module_notes.user_id', '=', 'users.id')
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
            ->where('applicants.status', 1)
            ->where('applicants.is_no_job', true)
            ->whereNotNull('applicants.lat')
            ->whereNotNull('applicants.lng')
            ->whereIn('applicants.job_title_id', $jobTitleIds)
            ->where(function ($q) use ($salesData, $radius) {
                foreach ($salesData as $sale) {
                    $q->orWhereRaw(
                        '(6371 * acos(LEAST(1, GREATEST(-1, cos(radians(?)) * cos(radians(applicants.lat)) * cos(radians(applicants.lng) - radians(?)) + sin(radians(?)) * sin(radians(applicants.lat)))))) <= ?',
                        [$sale->lat, $sale->lng, $sale->lat, $radius]
                    );
                }
            });

        // --- Filters ---
        $query->when($categoryFilter, fn($q) => $q->whereIn('applicants.job_category_id', $categoryFilter))
            ->when($titleFilter, fn($q) => $q->whereIn('applicants.job_title_id', $titleFilter))
            ->when(
                in_array($typeFilter, ['specialist', 'regular'], true),
                fn($q) => $q->where('applicants.job_type', $typeFilter)
            )
            ->when(
                $status !== 'all',
                fn($q) =>
                $q->where('applicants.is_temp_not_interested', $status === 'interested' ? 0 : 1)
            );

        // --- Search ---
        $query->when($request->input('search.value'), function ($q, $term) {
            $term = '%' . addslashes(trim($term)) . '%';
            $q->where(function ($sub) use ($term) {
                $sub->where('applicants.applicant_name', 'LIKE', $term)
                    ->orWhere('applicants.applicant_email', 'LIKE', $term)
                    ->orWhere('applicants.applicant_postcode', 'LIKE', $term)
                    ->orWhere('applicants.applicant_phone', 'LIKE', $term)
                    ->orWhere('applicants.applicant_landline', 'LIKE', $term)
                    ->orWhere('applicants.applicant_experience', 'LIKE', $term)
                    ->orWhere('job_titles.name', 'LIKE', $term)
                    ->orWhere('job_categories.name', 'LIKE', $term)
                    ->orWhere('job_sources.name', 'LIKE', $term)
                    ->orWhere('module_notes.details', 'LIKE', $term)
                    ->orWhere('users.name', 'LIKE', $term);
            });
        });

        // Filter by source if it's not empty
        if ($sourceFilter) {
            $query->whereIn('applicants.job_source_id', $sourceFilter);
        }

        // --- Sorting ---
        $query->when(
            isset($sortableColumns[$orderColumn]),
            fn($q) => $q->orderBy($sortableColumns[$orderColumn], $orderDirection),
            fn($q) => $q->orderBy('applicants.applicant_name', 'desc')
        );

        // Return DataTables response
        if ($request->ajax()) {
            return DataTables::of($query)
                ->addIndexColumn()
                ->with('total_sale_count', $salesData->count())
                ->addColumn('updated_at', fn($applicant) => Carbon::parse($applicant->updated_at)->format('d M Y, h:i A'))
                ->addColumn('applicant_name', fn($applicant) => e(ucwords(strtolower($applicant->applicant_name ?? '-'))))
                ->editColumn('applicant_postcode', function ($applicant) {
                    $rawPostcode = trim($applicant->applicant_postcode);
                    if (empty($rawPostcode))
                        return '<div class="text-center w-100">-</div>';

                    $postcode = $applicant->formatted_postcode;
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                    data-postcode="' . e($applicant->applicant_postcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';

                    if ($applicant->lat != null && $applicant->lng != null && !$applicant->is_blocked) {
                        $url = route('applicants.available_job', ['id' => $applicant->id, 'radius' => 15]);
                        $link = '<a href="' . $url . '" target="_blank" class="active_postcode">' . $postcode . '</a>';
                        return '<div class="d-flex align-items-center justify-content-between">' . $link . $copyBtn . '</div>';
                    } else {
                        return '<div class="d-flex align-items-center justify-content-between"><span>' . $postcode . '</span>' . $copyBtn . '</div>';
                    }
                })
                ->addColumn('job_title', fn($applicant) => e($applicant->job_title_name ?? '-'))
                ->addColumn('job_category', fn($applicant) => $applicant->job_category_name ? strtoupper($applicant->job_category_name) . ($applicant->job_type === 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '') : '-')
                ->addColumn('job_source', function ($applicant) {
                    if (!$applicant->job_source_name)
                        return '-';
                    return '<span class="badge bg-light text-dark">' . e($applicant->job_source_name) . '</span>';
                })
                ->addColumn('applicantPhone', function ($applicant) {

                    if ($applicant->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
                        return "<span class='badge bg-dark'>Blocked</span>";
                    }

                    $showBlockedData = $applicant->is_blocked
                        && Gate::allows('applicant-show-blocked-data');

                    $class = $showBlockedData ? 'show_hidden_phone' : '';

                    $parts = [];

                    if (!empty($applicant->applicant_phone)) {
                        $parts[] = DialLink::render($applicant->applicant_phone, 'Primary Phone', $class);
                    }

                    if (!empty($applicant->applicant_phone_secondary)) {
                        $parts[] = DialLink::render($applicant->applicant_phone_secondary, 'Secondary Phone', $class);
                    }

                    if (!empty($applicant->applicant_landline)) {
                        $parts[] = DialLink::render($applicant->applicant_landline, 'Landline', $class);
                    }

                    $phones = implode('<br>', $parts) ?: '-';

                    if ($showBlockedData) {
                        return '<div class="bg-dark text-white" style="padding:6px 8px; border-radius:4px; color:#ffffff !important;">'
                            . $phones
                            . '</div>';
                    }

                    return $phones;
                })
                // In your DataTable or controller
                ->filterColumn('applicantPhone', function ($query, $keyword) {
                    $clean = preg_replace('/[^0-9]/', '', $keyword); // remove spaces, dashes, etc.

                    $query->where(function ($q) use ($clean) {
                        $q->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone_secondary, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_landline, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"]);
                    });
                })
                ->addColumn('applicant_experience', function ($applicant) {
                    if (empty($applicant->applicant_experience) || $applicant->applicant_experience === 'NULL') {
                        return '-';
                    }
                    $short = Str::limit(strip_tags($applicant->applicant_experience), 80);
                    $full = e($applicant->applicant_experience);
                    $id = 'exp-' . $applicant->id;
                    return '
                        <a href="javascript:void(0);" class="text-primary" data-bs-toggle="modal" data-bs-target="#' . $id . '">' . $short . '</a>
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Applicant Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">' . nl2br($full) . '</div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('applicant_notes', function ($applicant) {
                    $statusValue = $this->getApplicantStatus($applicant);
                    $notes = $applicant->module_notes_details ?? $applicant->applicant_notes ?? '-';
                    if ($applicant->is_blocked == 0 && in_array($statusValue, ['open', 'reject'])) {
                        return '
                            <a href="javascript:void(0);" title="Add Short Note" style="color:blue" onclick="addShortNotesModal(\'' . (int)$applicant->id . '\')">
                                ' . $notes . '
                            </a>
                        ';
                    }
                    return $notes;
                })
                ->addColumn('applicant_resume', fn ($applicant) => CvStorage::linkCell($applicant->id, $applicant->applicant_cv, $applicant->is_blocked, 'original', 'success', 'Download CV'))
                ->addColumn('crm_resume', fn ($applicant) => CvStorage::linkCell($applicant->id, $applicant->updated_cv, $applicant->is_blocked, 'updated', 'primary', 'Download Updated CV'))
                ->addColumn('applicantEmail', function ($applicant) {
                    // Blocked applicant + no permission
                    if ($applicant->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
                        return "<span class='badge bg-dark'>Blocked</span>";
                    }

                    $email = $applicant->applicant_email_secondary
                        ? $applicant->applicant_email . '<br>' . $applicant->applicant_email_secondary
                        : $applicant->applicant_email;

                    // Blocked applicant + has permission
                    if ($applicant->is_blocked && Gate::allows('applicant-show-blocked-data')) {
                        return '<div class="bg-dark text-white p-1 rounded">' . $email . '</div>';
                    }

                    // Normal applicant
                    return $email;
                })
                // In your DataTable or controller
                ->filterColumn('applicantEmail', function ($query, $keyword) {
                    $keyword = strtolower(trim($keyword));

                    $query->where(function ($q) use ($keyword) {
                        $q->whereRaw('LOWER(applicants.applicant_email) LIKE ?', ["%{$keyword}%"])
                            ->orWhereRaw('LOWER(applicants.applicant_email_secondary) LIKE ?', ["%{$keyword}%"]);
                    });
                })
                ->addColumn('customStatus', function ($applicant) {
                    $statusValue = $this->getApplicantStatus($applicant);
                    $colorClass = $statusValue === 'paid' ? 'bg-success' : 'bg-primary';
                    return '<span class="badge w-100 ' . $colorClass . '">' . strtoupper($statusValue) . '</span>';
                })
                ->addColumn('action', function ($applicant) {
                    $status = match (true) {
                        $applicant->is_blocked => '<span class="badge bg-dark">Blocked</span>',
                        $applicant->status == 1 => '<span class="badge bg-success">Active</span>',
                        $applicant->is_no_response => '<span class="badge bg-danger">No Response</span>',
                        $applicant->is_circuit_busy => '<span class="badge bg-warning">Circuit Busy</span>',
                        $applicant->is_no_job => '<span class="badge bg-secondary">No Job</span>',
                        $applicant->status == 0 => '<span class="badge bg-secondary">Inactive</span>',
                        default => '',
                    };
                    $html = '';
                    $html = '<div class="btn-group dropstart">
                            <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                    ' . (int)$applicant->id . ',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_name ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_email ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_email_secondary ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_postcode ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->formatted_landline ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->formatted_phone ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->job_title_name ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->job_category_name ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->job_source_name ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->module_notes_created ? Carbon::parse($applicant->module_notes_created)->format('d M Y, h:i A') : 'N/A')) . '\',
                                    \'' . addslashes(htmlspecialchars($status)) . '\'
                                )">View</a></li>';
                    if (Gate::allows('applicant-upload-resume')) {
                        $html .= '<li>
                                        <a class="dropdown-item" href="javascript:void(0);" onclick="triggerFileInput(' . (int) $applicant->id . ')">Upload Applicant Resume</a>
                                        <!-- Hidden File Input -->
                                        <input type="file" id="fileInput" style="display:none" accept=".pdf,.doc,.docx" onchange="uploadFile()">
                                    </li>';
                    }
                    if (Gate::allows('applicant-upload-crm-resume')) {
                        $html .= '<li>
                                        <a class="dropdown-item" href="javascript:void(0);" onclick="triggerCrmFileInput(' . (int) $applicant->id . ')">Upload CRM Resume</a>
                                        <!-- Hidden File Input -->
                                        <input type="file" id="crmfileInput" style="display:none" accept=".pdf,.doc,.docx" onchange="crmuploadFile()">
                                    </li>';
                    }

                    $html .= '<li><hr class="dropdown-divider"></li>';
                    if (Gate::allows('applicant-view-history')) {
                        $html .= '<li><a class="dropdown-item" target="_blank" href="' . route('applicants.history', ['id' => (int) $applicant->id]) . '">View History</a></li>';
                    }
                    $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $applicant->id . ')">Notes History</a></li>
                            </ul>
                        </div>';

                    return $html;
                })
                ->rawColumns(['applicant_postcode', 'job_source', 'applicant_resume', 'applicantEmail', 'applicantPhone', 'crm_resume', 'applicant_notes', 'customStatus', 'job_category', 'applicant_experience', 'action'])
                ->make(true);
        }

        return response()->json(['error' => 'Invalid request'], 400);
    }
    protected function getApplicantsAgainstSales($lat, $lng, $radius, $job_title_id, $status, $category, $orderColumn, $orderDirection)
    {
        $query = Applicant::query()
            ->select([
                'applicants.*',
                'module_notes.details as module_notes_details',
                'module_notes.created_at as module_notes_created',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name',
                'users.name as user_name',
            ])
            ->where('applicants.status', 1)
            ->whereNull('applicants.deleted_at')
            ->where('applicants.is_no_job', true)
            ->whereNotNull('applicants.lat')
            ->whereNotNull('applicants.lng')
            ->leftJoinSub(
                DB::table('module_notes')
                    ->select('module_notes.*')
                    ->where('module_noteable_type', Applicant::class)
                    ->whereIn('module_notes.id', function ($sub) {
                        $sub->select(DB::raw('MAX(id)'))
                            ->from('module_notes')
                            ->where('module_noteable_type', Applicant::class)
                            ->groupBy('module_noteable_id');
                    }),
                'module_notes',
                fn($join) => $join->on('applicants.id', '=', 'module_notes.module_noteable_id')
            )
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
            ->leftJoin('users', 'module_notes.user_id', '=', 'users.id')
            ->whereRaw(
                '(6371 * acos(LEAST(1, GREATEST(-1, cos(radians(?)) * cos(radians(applicants.lat)) * cos(radians(applicants.lng) - radians(?)) + sin(radians(?)) * sin(radians(applicants.lat)))))) <= ?',
                [$lat, $lng, $lat, $radius]
            );

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $query->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('applicants.job_source_id', $sourceIds)
                    ->orWhereNull('applicants.job_source_id');
            });
        }

        // Job Title Filtering
        $jobTitle = JobTitle::find($job_title_id);
        if ($jobTitle) {
            $relatedTitles = is_array($jobTitle->related_titles)
                ? $jobTitle->related_titles
                : json_decode($jobTitle->related_titles ?? '[]', true);
            $titles = collect($relatedTitles)
                ->map(fn($item) => strtolower(trim($item)))
                ->push(strtolower(trim($jobTitle->name)))
                ->unique()
                ->values()
                ->toArray();
            $jobTitleIds = JobTitle::whereIn(DB::raw('LOWER(name)'), $titles)->pluck('id')->toArray();
            $query->whereIn('applicants.job_title_id', $jobTitleIds);
        }

        // Status and Category Filtering
        $query->when($status !== 'all', fn($q) => $q->where('applicants.is_temp_not_interested', $status === 'interested' ? 0 : 1))
            ->when($category, fn($q) => $q->whereIn('applicants.job_category_id', (array) $category));

        $applicants = $query->orderBy($orderColumn, $orderDirection)->get();
        Log::info('Applicants query executed', [
            'lat' => $lat,
            'lng' => $lng,
            'radius' => $radius,
            'job_title_id' => $job_title_id,
            'count' => $applicants->count(),
        ]);

        return $applicants;
    }
    protected function getApplicantStatus($applicant)
    {
        return $applicant->paid_status === 'close'
            ? 'paid'
            : ($applicant->cv_notes_status === 'active'
                ? 'sent'
                : ($applicant->cv_notes_status === 'disable' ? 'reject' : 'open'));
    }
    public function getResourcesRejectedApplicants(Request $request)
    {
        $typeFilter     = $request->input('type_filter', '');
        $categoryFilter = $request->input('category_filter', '');
        $titleFilter    = $request->input('title_filter', '');
        $searchTerm     = $request->input('search.value', '');
        $dateFilter     = $request->input('date_filter', '');
        $radius         = 15; // in kilometers

        $movedTabValues = [
            'interview_not_attended',
            'request_reject',
            'cv_sent_reject',
            'start_date_hold',
            'start_date_hold_save',
        ];

        $subStageValues = [
            'crm_interview_not_attended',
            'crm_request_reject',
            'crm_reject',
            'crm_start_date_hold',
            'crm_start_date_hold_save',
        ];

        // Filter BEFORE ranking, so ROW_NUMBER() only partitions the rows
        // that could ever survive the outer WHERE — much smaller sort/window cost.
        $latestNotes = DB::table('crm_notes')
            ->select(
                'id',
                'applicant_id',
                'sale_id',
                'details',
                'moved_tab_to',
                'created_at',
                'updated_at',
                DB::raw('ROW_NUMBER() OVER (PARTITION BY applicant_id, sale_id ORDER BY id DESC) as row_num')
            )
            ->whereIn('moved_tab_to', $movedTabValues);

        $latestHistory = DB::table('history')
            ->select(
                'id',
                'applicant_id',
                'sale_id',
                'sub_stage',
                'status',
                'created_at',
                DB::raw('ROW_NUMBER() OVER (PARTITION BY applicant_id, sale_id ORDER BY id DESC) as row_num')
            )
            ->whereIn('sub_stage', $subStageValues)
            ->where('status', 1);

        $model = Applicant::query()
            ->select([
                'crm_notes.details',
                'crm_notes.created_at as crm_notes_created',

                'applicants.id',
                'applicants.applicant_name',
                'applicants.job_title_id',
                'applicants.job_category_id',
                'applicants.applicant_postcode',
                'applicants.applicant_phone',
                'applicants.applicant_phone_secondary',
                'applicants.applicant_experience',
                'applicants.applicant_notes',
                'applicants.paid_status',
                'applicants.applicant_landline',
                'applicants.job_source_id',
                'applicants.status as applicant_status',
                'applicants.created_at as applicant_created',
                'applicants.lat',
                'applicants.lng',
                'applicants.applicant_email',
                'applicants.applicant_email_secondary',
                'applicants.is_blocked',

                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name',
                DB::raw('
                    CASE 
                        WHEN history.sub_stage = "crm_reject" THEN "Rejected CV" 
                        WHEN history.sub_stage = "crm_request_reject" THEN "Rejected By Request"
                        WHEN history.sub_stage = "crm_interview_not_attended" THEN "Not Attended"
                        WHEN history.sub_stage IN ("crm_start_date_hold", "crm_start_date_hold_save") THEN "Start Date Hold"
                        ELSE "Unknown Status"
                    END AS sub_stage
                ')
            ])
            ->joinSub($latestNotes, 'crm_notes', function ($join) {
                $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                    ->where('crm_notes.row_num', 1);
            })
            ->joinSub($latestHistory, 'history', function ($join) {
                $join->on('applicants.id', '=', 'history.applicant_id')
                    ->on('crm_notes.sale_id', '=', 'history.sale_id')
                    ->where('history.row_num', 1);
            })
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
            ->where([
                'applicants.status'             => 1,
                'applicants.is_in_nurse_home'   => false,
                'applicants.is_blocked'         => false,
                'applicants.is_callback_enable' => false,
                'applicants.is_no_job'          => false,
            ])
            ->whereNull('applicants.deleted_at');

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('applicants.job_source_id', $sourceIds)
                    ->orWhereNull('applicants.job_source_id');
            });
        }

        // Hide rows for sales under offices/units with private notes, and rows whose CRM note
        // contains a private word (users without show-private-data), as on the CRM page.
        $this->excludePrivateSales($model, 'crm_notes.sale_id');
        $this->excludePrivateNotes($model, 'crm_notes.details');

        // ✅ Date filter
        if ($dateFilter) {
            $now        = Carbon::now();
            $start_date = null;
            $end_date   = $now->copy()->endOfDay();

            switch ($dateFilter) {
                case 'last-3-months':
                    $start_date = $now->copy()->subMonths(3)->startOfDay();
                    break;
                case 'last-6-months':
                    $end_date   = $now->copy()->subMonths(3)->endOfDay();
                    $start_date = $end_date->copy()->subMonths(6)->startOfDay();
                    break;
                case 'last-9-months':
                    $end_date   = $now->copy()->subMonths(6)->endOfDay();
                    $start_date = $end_date->copy()->subMonths(9)->startOfDay();
                    break;
                case 'others':
                    $end_date   = $now->copy()->subMonths(15)->endOfDay();
                    $start_date = $end_date->copy()->subYears(5)->startOfDay();
                    break;
            }

            if ($start_date && $end_date) {
                $model->whereBetween('crm_notes.updated_at', [$start_date, $end_date]);
            }
        }

        // ✅ Sorting — whitelist to prevent invalid/unsafe columns and ambiguous-column errors
        $sortMap = [
            'job_source'      => 'applicants.job_source_id',
            'job_category'    => 'applicants.job_category_id',
            'job_title'       => 'applicants.job_title_id',
            'applicant_name'  => 'applicants.applicant_name',
            'applicant_email' => 'applicants.applicant_email',
            'created_at'      => 'applicants.created_at',
            'updated_at'      => 'crm_notes.updated_at',
            // add any other DataTables-sortable columns explicitly here
        ];

        if ($request->has('order')) {
            $orderColumn    = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

            if (isset($sortMap[$orderColumn])) {
                $model->orderBy($sortMap[$orderColumn], $orderDirection);
            } else {
                $model->orderBy('crm_notes.updated_at', 'desc');
            }
        } else {
            $model->orderBy('crm_notes.updated_at', 'desc');
        }

        // ✅ Search — filter on already-joined columns directly instead of orWhereHas
        // (avoids a correlated EXISTS subquery per relation on every row)
        if (!empty($searchTerm)) {
            $model->where(function ($query) use ($searchTerm) {
                $like = "%{$searchTerm}%";
                $query->where('applicants.applicant_name', 'LIKE', $like)
                    ->orWhere('applicants.applicant_postcode', 'LIKE', $like)
                    ->orWhere('applicants.applicant_phone', 'LIKE', $like)
                    ->orWhere('applicants.applicant_experience', 'LIKE', $like)
                    ->orWhere('applicants.applicant_landline', 'LIKE', $like)
                    ->orWhere('job_titles.name', 'LIKE', $like)
                    ->orWhere('job_categories.name', 'LIKE', $like)
                    ->orWhere('job_sources.name', 'LIKE', $like);
            });
        }

        // ✅ Filters
        if ($typeFilter === 'specialist') {
            $model->where('applicants.job_type', 'specialist');
        } elseif ($typeFilter === 'regular') {
            $model->where('applicants.job_type', 'regular');
        }

        if ($categoryFilter) {
            $model->whereIn('applicants.job_category_id', $categoryFilter);
        }

        if ($titleFilter) {
            $model->whereIn('applicants.job_title_id', $titleFilter);
        }

        // ✅ DataTables response
        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn()
                ->addColumn('job_title', fn($a) => $a->jobTitle ? strtoupper($a->jobTitle->name) : '-')
                ->addColumn('job_category', function ($a) {
                    $stype = $a->job_type === 'specialist' ? '<br>(Specialist)' : '';
                    return $a->jobCategory ? ucwords($a->jobCategory->name) . $stype : '-';
                })
                ->addColumn('job_source', fn($a) => $a->jobSource ? ucwords($a->jobSource->name) : '-')
                ->addColumn('applicant_name', fn($a) => $a->formatted_applicant_name)
                ->editColumn('applicant_postcode', function ($a) {
                    $rawPostcode = trim($a->applicant_postcode);
                    if (empty($rawPostcode))
                        return '<div class="text-center w-100">-</div>';

                    $postcode = $a->formatted_postcode;
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                    data-postcode="' . e($a->applicant_postcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';

                    if ($a->lat != null && $a->lng != null && !$a->is_blocked) {
                        $url = route('applicants.available_job', ['id' => $a->id, 'radius' => 15]);
                        $link = '<a href="' . $url . '" target="_blank" class="active_postcode">' . $postcode . '</a>';
                        return '<div class="d-flex align-items-center justify-content-between">' . $link . $copyBtn . '</div>';
                    } else {
                        return '<div class="d-flex align-items-center justify-content-between"><span>' . $postcode . '</span>' . $copyBtn . '</div>';
                    }
                })
                ->addColumn('applicant_notes', function ($a) {
                    $notes = e(htmlspecialchars($a->details, ENT_QUOTES, 'UTF-8'));
                    $notes = $notes ? $notes : 'N/A';
                    $name  = e($a->applicant_name);
                    $shortNotes = Str::limit(trim($notes), 80);
                    $postcode = htmlspecialchars($a->applicant_postcode, ENT_QUOTES, 'UTF-8');

                    // Tooltip content with additional data-bs-placement and title
                    return '<a href="javascript:void(0);" title="View Note" onclick="showNotesModal(\'' . (int)$a->id . '\',\'' . $notes . '\', \'' . $name . '\', \'' . $postcode . '\')">
                               ' . $shortNotes . '
                            </a>';
                })
                ->addColumn('applicantPhone', function ($a) {

                    if ($a->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
                        return "<span class='badge bg-dark'>Blocked</span>";
                    }

                    $showBlockedData = $a->is_blocked
                        && Gate::allows('applicant-show-blocked-data');

                    $class = $showBlockedData ? 'show_hidden_phone' : '';

                    $parts = [];

                    if (!empty($a->applicant_phone)) {
                        $parts[] = DialLink::render($a->applicant_phone, 'Primary Phone', $class);
                    }

                    if (!empty($a->applicant_phone_secondary)) {
                        $parts[] = DialLink::render($a->applicant_phone_secondary, 'Secondary Phone', $class);
                    }

                    if (!empty($a->applicant_landline)) {
                        $parts[] = DialLink::render($a->applicant_landline, 'Landline', $class);
                    }

                    $phones = implode('<br>', $parts) ?: '-';

                    if ($showBlockedData) {
                        return '<div class="bg-dark text-white" style="padding:6px 8px; border-radius:4px; color:#ffffff !important;">'
                            . $phones
                            . '</div>';
                    }

                    return $phones;
                })
                ->filterColumn('applicantPhone', function ($query, $keyword) {
                    $clean = preg_replace('/[^0-9]/', '', $keyword); // remove spaces, dashes, etc.

                    $query->where(function ($q) use ($clean) {
                        $q->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone_secondary, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_landline, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"]);
                    });
                })
                ->addColumn('applicant_experience', function ($a) {
                    if (empty($a->applicant_experience) || $a->applicant_experience === 'NULL') {
                        return '-';
                    }

                    $short = Str::limit(strip_tags($a->applicant_experience), 80);
                    $full  = e($a->applicant_experience);
                    $id    = 'exp-' . $a->id;

                    return '
                        <a href="javascript:void(0);" class="text-primary" data-bs-toggle="modal" data-bs-target="#' . $id . '">' . $short . '</a>
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Applicant Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">' . nl2br($full) . '</div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('crm_notes_created', fn($a) => Carbon::parse($a->crm_notes_created)->format('d M Y, h:i A'))
                ->addColumn('sub_stage', function ($a) {
                    return match ($a->sub_stage) {
                        'Rejected CV'         => '<span class="badge bg-danger">Rejected CV</span>',
                        'Rejected By Request' => '<span class="badge bg-primary">Rejected By Request</span>',
                        'Not Attended'        => '<span class="badge bg-warning">Not Attended</span>',
                        'Start Date Hold'     => '<span class="badge bg-info">Start Date Hold</span>',
                        default               => '<span class="badge bg-warning">Unknown</span>',
                    };
                })
                ->addColumn('applicantEmail', function ($a) {
                    // Blocked applicant + no permission
                    if ($a->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
                        return "<span class='badge bg-dark'>Blocked</span>";
                    }

                    $email = $a->applicant_email_secondary
                        ? $a->applicant_email . '<br>' . $a->applicant_email_secondary
                        : $a->applicant_email;

                    // Blocked applicant + has permission
                    if ($a->is_blocked && Gate::allows('applicant-show-blocked-data')) {
                        return '<div class="bg-dark text-white p-1 rounded">' . $email . '</div>';
                    }

                    // Normal applicant
                    return $email;
                })
                ->filterColumn('applicantEmail', function ($query, $keyword) {
                    $keyword = trim($keyword);
                    $query->where('applicants.applicant_email', 'LIKE', "{$keyword}%")
                        ->orWhere('applicants.applicant_email_secondary', 'LIKE', "{$keyword}%");
                })
                ->addColumn('action', function ($a) {
                    $landline = $a->formatted_landline;
                    $phone    = $a->formatted_phone;
                    $postcode = $a->formatted_postcode;
                    $posted   = Carbon::parse($a->applicant_created)->format('d M Y, h:i A');
                    $jobTitle = $a->jobTitle ? strtoupper($a->jobTitle->name) : '-';
                    $jobCat   = $a->jobCategory ? ucwords($a->jobCategory->name) : '-';
                    $jobSrc   = $a->jobSource ? ucwords($a->jobSource->name) : '-';

                    $status = '';
                    if ($a->is_blocked) {
                        $status = '<span class="badge bg-dark">Blocked</span>';
                    } elseif ($a->applicant_status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($a->is_no_response) {
                        $status = '<span class="badge bg-warning">No Response</span>';
                    } elseif ($a->is_circuit_busy) {
                        $status = '<span class="badge bg-warning">Circuit Busy</span>';
                    } elseif ($a->is_no_job) {
                        $status = '<span class="badge bg-warning">No Job</span>';
                    } elseif ($a->applicant_status == 0) {
                        $status = '<span class="badge bg-danger">Inactive</span>';
                    }

                    return '<div class="btn-group dropstart">
                            <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown">
                                <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                    ' . $a->id . ',
                                    \'' . addslashes(e($a->applicant_name)) . '\',
                                    \'' . addslashes(e($a->applicant_email)) . '\',
                                    \'' . addslashes(e($a->applicant_email_secondary ?? '-')) . '\',
                                    \'' . addslashes(e($postcode)) . '\',
                                    \'' . addslashes(e($landline)) . '\',
                                    \'' . addslashes(e($phone)) . '\',
                                    \'' . addslashes(e($jobTitle)) . '\',
                                    \'' . addslashes(e($jobCat)) . '\',
                                    \'' . addslashes(e($jobSrc)) . '\',
                                    \'' . addslashes(e($posted)) . '\',
                                    \'' . addslashes(e($status)) . '\'
                                )">View</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $a->id . ')">Notes History</a></li>
                            </ul>
                        </div>';
                })
                ->rawColumns([
                    'applicant_notes',
                    'applicant_experience',
                    'applicant_postcode',
                    'applicantPhone',
                    'applicantEmail',
                    'job_title',
                    'sub_stage',
                    'job_category',
                    'job_source',
                    'action'
                ])
                ->make(true);
        }

        return response()->json(['error' => 'Invalid request'], 400);
    }
    public function getApplicantHistorybyStatus(Request $request)
    {
        $applicant_id = $request->input('id');
        $status = $request->input('status');

        try {
            $history = CrmNote::join('sales', 'sales.id', '=', 'crm_notes.sale_id')
                ->join('units', 'units.id', '=', 'sales.unit_id')
                ->select(
                    'sales.job_title_id',
                    'sales.sale_postcode',
                    'sales.id',
                    'units.unit_name',
                    'crm_notes.created_at',
                    'crm_notes.details',
                    'crm_notes.moved_tab_to',
                    'crm_notes.status',
                )
                ->where('crm_notes.applicant_id', '=', $applicant_id)
                // Leave out notes for sales hidden by private office/unit notes.
                ->tap(fn($query) => $this->excludePrivateSales($query, 'crm_notes.sale_id'))
                ->latest('created_at')
                ->get();

            if ($status == 'rejected') {
                $history->whereIn('crm_notes.moved_tab_to', [
                    'cv_sent_reject',
                    'request_reject',
                    'interview_not_attended',
                    'start_date_hold',
                    'dispute'
                ]);
            }

            // Return the specific fields you need (e.g., applicant name, notes, etc.)
            return response()->json([
                'data' => $history,
                'success' => true
            ]);
        } catch (Exception $e) {
            // If an error occurs, catch it and return a meaningful error message
            return response()->json([
                'error' => 'An unexpected error occurred. Please try again later.',
                'message' => $e->getMessage(),
                'success' => false
            ], 500); // Internal server error
        }
    }
    public function getResourcesBlockedApplicants(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $searchTerm = $request->input('search', ''); // This will get the search query

        $model = Applicant::query()
            ->select([
                'applicants.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name'
            ])
            ->where('applicants.is_blocked', true)
            ->where('applicants.status', 1)
            ->whereNull('applicants.deleted_at')

            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
            ->leftJoin('applicants_pivot_sales', 'applicants.id', '=', 'applicants_pivot_sales.applicant_id')
            // ->with(['jobTitle', 'jobCategory', 'jobSource'])
            ->with(['cv_notes' => function ($query) {
                $query->select('status', 'applicant_id', 'sale_id', 'user_id')
                    ->with(['user:id,name'])->latest();
            }])
            ->whereNull('applicants_pivot_sales.applicant_id');

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('applicants.job_source_id', $sourceIds)
                    ->orWhereNull('applicants.job_source_id');
            });
        }


        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn === 'job_source') {
                $model->orderBy('applicants.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('applicants.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('applicants.job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'checkbox') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('applicants.updated_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('applicants.updated_at', 'desc');
        }

        if ($request->has('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            if (!empty($searchTerm)) {
                $model->where(function ($query) use ($searchTerm) {
                    // Direct column searches
                    $query->where('applicants.applicant_name', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_email', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_postcode', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_phone', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_experience', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_landline', 'LIKE', "%{$searchTerm}%");

                    // Relationship searches with explicit table names
                    $query->orWhereHas('jobTitle', function ($q) use ($searchTerm) {
                        $q->where('job_titles.name', 'LIKE', "%{$searchTerm}%");
                    });

                    $query->orWhereHas('jobCategory', function ($q) use ($searchTerm) {
                        $q->where('job_categories.name', 'LIKE', "%{$searchTerm}%");
                    });

                    $query->orWhereHas('jobSource', function ($q) use ($searchTerm) {
                        $q->where('job_sources.name', 'LIKE', "%{$searchTerm}%");
                    });
                });
            }
        }

        // Filter by type if it's not empty
        if ($typeFilter == 'specialist') {
            $model->where('applicants.job_type', 'specialist');
        } elseif ($typeFilter == 'regular') {
            $model->where('applicants.job_type', 'regular');
        }

        // Filter by type if it's not empty
        if ($categoryFilter) {
            $model->whereIn('applicants.job_category_id', $categoryFilter);
        }

        // Filter by type if it's not empty
        if ($titleFilter) {
            $model->whereIn('applicants.job_title_id', $titleFilter);
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addColumn('checkbox', function ($applicant) {
                    return '<input type="checkbox" name="applicant_checkbox[]" class="applicant_checkbox" value="' . $applicant->id . '"/>';
                })
                ->addColumn('job_title', function ($applicant) {
                    return $applicant->jobTitle ? strtoupper($applicant->jobTitle->name) : '-';
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype = $type === 'specialist'
                        ? '<br><span class="badge bg-secondary-subtle text-muted text-uppercase mt-1" style="font-size:10px;">Specialist</span>'
                        : '';
                    return $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
                })
                ->addColumn('job_source', function ($applicant) {
                    if (!$applicant->jobSource)
                        return '-';
                    return '<span class="badge bg-light text-dark">' . e($applicant->jobSource->name) . '</span>';
                })
                ->addColumn('applicant_name', function ($applicant) {
                    return $applicant->formatted_applicant_name; // Using accessor
                })
                ->addColumn('applicantEmail', function ($applicant) {
                    // Blocked applicant + no permission
                    if ($applicant->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
                        return "<span class='badge bg-dark'>Blocked</span>";
                    }

                    $email = $applicant->applicant_email_secondary
                        ? $applicant->applicant_email . '<br>' . $applicant->applicant_email_secondary
                        : $applicant->applicant_email;

                    // Blocked applicant + has permission
                    if ($applicant->is_blocked && Gate::allows('applicant-show-blocked-data')) {
                        return '<div class="bg-dark text-white p-1 rounded">' . $email . '</div>';
                    }

                    // Normal applicant
                    return $email;
                })
                ->filterColumn('applicantEmail', function ($query, $keyword) {
                    $keyword = trim($keyword);
                    $query->where('applicants.applicant_email', 'LIKE', "{$keyword}%")
                        ->orWhere('applicants.applicant_email_secondary', 'LIKE', "{$keyword}%");
                })
                ->addColumn('applicant_postcode', function ($applicant) {
                    $status_value = 'open';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                    } else {
                        foreach ($applicant->cv_notes as $key => $value) {
                            if ($value->status == 1) {
                                $status_value = 'sent';
                                break;
                            } elseif ($value->status == 0) {
                                $status_value = 'reject';
                            }
                        }
                    }

                    $rawPostcode = trim($applicant->applicant_postcode);
                    if (empty($rawPostcode))
                        return '<div class="text-center w-100">-</div>';

                    $postcode = $applicant->formatted_postcode;
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                    data-postcode="' . e($applicant->applicant_postcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';

                    if ($applicant->lat != null && $applicant->lng != null && !$applicant->is_blocked && $status_value == 'open' || $status_value == 'reject') {
                        $url = route('applicants.available_job', ['id' => $applicant->id, 'radius' => 15]);
                        $link = '<a href="' . $url . '" target="_blank" class="active_postcode">' . $postcode . '</a>';
                        return '<div class="d-flex align-items-center justify-content-between">' . $link . $copyBtn . '</div>';
                    } else {
                        return '<div class="d-flex align-items-center justify-content-between"><span>' . $postcode . '</span>' . $copyBtn . '</div>';
                    }
                })
                ->addColumn('applicant_notes', function ($applicant) {
                    if (empty($applicant->applicant_notes) || $applicant->applicant_notes === 'NULL') {
                        return '-';
                    }

                    $short = Str::limit(strip_tags($applicant->applicant_notes), 80);
                    $full = e($applicant->applicant_notes);
                    $id = 'notes-' . $applicant->id;

                    return '
                        <a href="javascript:void(0);" class="text-primary" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Applicant Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('applicantPhone', function ($applicant) {

                    if ($applicant->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
                        return "<span class='badge bg-dark'>Blocked</span>";
                    }

                    $showBlockedData = $applicant->is_blocked
                        && Gate::allows('applicant-show-blocked-data');

                    $class = $showBlockedData ? 'show_hidden_phone' : '';

                    $parts = [];

                    if (!empty($applicant->applicant_phone)) {
                        $parts[] = DialLink::render($applicant->applicant_phone, 'Primary Phone', $class);
                    }

                    if (!empty($applicant->applicant_phone_secondary)) {
                        $parts[] = DialLink::render($applicant->applicant_phone_secondary, 'Secondary Phone', $class);
                    }

                    if (!empty($applicant->applicant_landline)) {
                        $parts[] = DialLink::render($applicant->applicant_landline, 'Landline', $class);
                    }

                    $phones = implode('<br>', $parts) ?: '-';

                    if ($showBlockedData) {
                        return '<div class="bg-dark text-white" style="padding:6px 8px; border-radius:4px; color:#ffffff !important;">'
                            . $phones
                            . '</div>';
                    }

                    return $phones;
                })
                ->filterColumn('applicantPhone', function ($query, $keyword) {
                    $clean = preg_replace('/[^0-9]/', '', $keyword); // remove spaces, dashes, etc.

                    $query->where(function ($q) use ($clean) {
                        $q->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone_secondary, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_landline, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"]);
                    });
                })
                ->addColumn('applicant_experience', function ($applicant) {
                    if (empty($applicant->applicant_experience) || $applicant->applicant_experience === 'NULL') {
                        return '-';
                    }

                    $short = Str::limit(strip_tags($applicant->applicant_experience), 80);
                    $full = e($applicant->applicant_experience);
                    $id = 'exp-' . $applicant->id;

                    return '
                        <a href="javascript:void(0);" class="text-primary" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Applicant Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('updated_at', function ($applicant) {
                    return $applicant->formatted_updated_at; // Using accessor
                })
                ->addColumn('customStatus', function ($applicant) {
                    $status_value = 'open';
                    $color_class = 'bg-success';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                        $color_class = 'bg-success';
                    } else {
                        foreach ($applicant->cv_notes as $key => $value) {
                            if ($value->status == 1) {
                                $status_value = 'sent';
                                $color_class = 'bg-success';
                                break;
                            } elseif ($value->status == 0) {
                                $status_value = 'reject';
                                $color_class = 'bg-danger';
                            }
                        }
                    }

                    $status = '';
                    $status .= '<span class="badge ' . $color_class . '">';
                    $status .= strtoupper($status_value);
                    $status .= '</span>';
                    return $status;
                })
                ->addColumn('action', function ($applicant) {
                    $is_blocked = $applicant->is_blocked;
                    $showBlock = '<span class="badge bg-dark">Blocked</span>';

                    $landline = $is_blocked ? $showBlock : $applicant->formatted_landline;
                    $phone = $is_blocked ? $showBlock : $applicant->formatted_phone;
                    $phone_secondary = $is_blocked ? $showBlock : $applicant->formatted_phone_secondary;
                    $applicant_email_secondary = $is_blocked ? $showBlock : $applicant->applicant_email_secondary;
                    $email = $is_blocked ? $showBlock : $applicant->applicant_email;
                    $postcode = $applicant->formatted_postcode;
                    $posted_date = $applicant->formatted_created_at;
                    $job_title = $applicant->jobTitle ? strtoupper($applicant->jobTitle->name) : '-';
                    $job_category = $applicant->jobCategory ? ucwords($applicant->jobCategory->name) : '-';
                    $job_source = $applicant->jobSource ? ucwords($applicant->jobSource->name) : '-';
                    $have_nursing_home_experience = $applicant->have_nursing_home_experience ? ($applicant->have_nursing_home_experience == true ? 'Yes' : 'No') : '-';
                    $experience = trim((string) $applicant->applicant_experience);

                    if ($experience === '' || strtoupper($experience) === 'NULL') {
                        $experience = '-';
                    }

                    $experience = str_replace(["\r", "\n"], ' ', $experience);


                    $status = '';

                    if ($is_blocked) {
                        $status = '<span class="badge bg-dark">Blocked</span>';
                    } elseif ($applicant->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($applicant->is_no_response) {
                        $status = '<span class="badge bg-danger">No Response</span>';
                    } elseif ($applicant->is_circuit_busy) {
                        $status = '<span class="badge bg-warning">Circuit Busy</span>';
                    } elseif ($applicant->is_no_job) {
                        $status = '<span class="badge bg-secondary">No Job</span>';
                    } elseif ($applicant->status == 0) {
                        $status = '<span class="badge bg-secondary">Inactive</span>';
                    }

                    return '<div class="btn-group dropstart">
                            <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                            </button>
                            <ul class="dropdown-menu">
                               
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                    ' . (int)$applicant->id . ',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_name)) . '\',
                                    \'' . addslashes(htmlspecialchars($email)) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant_email_secondary)) . '\',
                                    \'' . addslashes(htmlspecialchars($postcode)) . '\',
                                    \'' . addslashes(htmlspecialchars($landline)) . '\',
                                    \'' . addslashes(htmlspecialchars($phone)) . '\',
                                    \'' . addslashes(htmlspecialchars($job_title)) . '\',
                                    \'' . addslashes(htmlspecialchars($job_category)) . '\',
                                    \'' . addslashes(htmlspecialchars($job_source)) . '\',
                                    \'' . addslashes(htmlspecialchars($posted_date)) . '\',
                                    \'' . addslashes(htmlspecialchars($status)) . '\',
                                    \'' . addslashes(htmlspecialchars($phone_secondary)) . '\',
                                    \'' . addslashes(htmlspecialchars($experience)) . '\',
                                    \'' . addslashes(htmlspecialchars($have_nursing_home_experience)) . '\'
                                )">View</a></li>
                                 <li>
                                    <a class="dropdown-item" href="javascript:void(0);" title="Add Short Note" onclick="addShortNotesModal(\'' . (int)$applicant->id . '\')">
                                        Add Notes
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $applicant->id . ')">Notes History</a></li>
                            </ul>
                        </div>';
                })
                ->rawColumns(['checkbox', 'applicant_notes', 'applicantEmail', 'applicant_experience', 'applicant_postcode', 'applicant_landline', 'applicantPhone', 'job_title', 'resume', 'customStatus', 'job_category', 'job_source', 'action'])
                ->make(true);
        }
    }
    public function getResourcesPaidApplicants(Request $request)
    {
        $typeFilter = $request->input('type_filter');
        $categoryFilter = (array) $request->input('category_filter', []);
        $titleFilter = (array) $request->input('title_filter', []);
        $sourceFilter = (array) $request->input('source_filter', []);
        $searchTerm = $request->input('search.value', '');

        // Same rules as the CRM "Paid" tab (CrmController::getCrmApplicantsAjaxRequest):
        // latest 'paid' CRM note per applicant + sale, the sale (not deleted) with its
        // office and unit, and an active 'crm_paid' history row for that applicant + sale.
        $latestCrmNotes = DB::table('crm_notes as cn')
            ->select('cn.applicant_id', 'cn.sale_id', DB::raw('MAX(cn.id) as latest_id'))
            ->where('cn.moved_tab_to', 'paid')
            ->groupBy('cn.applicant_id', 'cn.sale_id');

        // Main query
        $query = Applicant::query()
            ->select([
                'applicants.id',
                'applicants.applicant_name',
                'applicants.applicant_email',
                'applicants.applicant_email_secondary',
                'applicants.applicant_postcode',
                'applicants.applicant_phone',
                'applicants.applicant_phone_secondary',
                'applicants.applicant_landline',
                'applicants.applicant_experience',
                'applicants.lat',
                'applicants.lng',
                'applicants.is_blocked',
                'applicants.is_no_response',
                'applicants.is_circuit_busy',
                'applicants.job_category_id',
                'applicants.job_title_id',
                'applicants.job_source_id',
                'applicants.job_type',
                'applicants.created_at',
                'applicants.status',
                'applicants.is_no_job',
                'applicants.paid_status',
                'applicants.deleted_at',

                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name',

                'crm_notes.details',
                'crm_notes.created_at as crm_notes_created',
                'crm_notes.moved_tab_to',
                'crm_notes.sale_id',

                'sales.id as sale_record_id',
                'sales.sale_postcode',
                'sales.job_category_id as sale_category_id',
                'sales.job_title_id as sale_title_id',
                'sales.job_type as sale_job_type',
                'sales.position_type',
                'sales.status as sale_status',
                'sales.is_on_hold as sale_is_on_hold',
                'sales.timing',
                'sales.experience as sale_experience',
                'sales.salary',
                'sales.qualification as sale_qualification',
                'sales.benefits',
                'sales.created_at as sale_posted_date',
                'offices.office_name as sale_office',
                'units.unit_name as sale_unit',
                'sale_job_sources.name as sale_source_name',
                'sale_job_categories.name as sale_category_name',
                'sale_job_titles.name as sale_title_name',
            ])
            ->joinSub($latestCrmNotes, 'latest_crm', function ($join) {
                $join->on('applicants.id', '=', 'latest_crm.applicant_id');
            })
            ->join('crm_notes', 'crm_notes.id', '=', 'latest_crm.latest_id')
            ->join('sales', function ($join) {
                $join->on('crm_notes.sale_id', '=', 'sales.id')
                    ->whereNull('sales.deleted_at');
            })
            ->join('offices', 'sales.office_id', '=', 'offices.id')
            ->join('units', 'sales.unit_id', '=', 'units.id')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('history')
                    ->whereColumn('history.applicant_id', 'crm_notes.applicant_id')
                    ->whereColumn('history.sale_id', 'crm_notes.sale_id')
                    ->where('history.sub_stage', 'crm_paid')
                    ->where('history.status', 1);
            })
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
            ->leftJoin('job_sources as sale_job_sources', 'sales.job_source_id', '=', 'sale_job_sources.id')
            ->leftJoin('job_categories as sale_job_categories', 'sales.job_category_id', '=', 'sale_job_categories.id')
            ->leftJoin('job_titles as sale_job_titles', 'sales.job_title_id', '=', 'sale_job_titles.id')
            ->where('applicants.status', 1)
            ->whereNull('applicants.deleted_at')
            ->with(['cv_notes' => function ($query) {
                $query->select('status', 'applicant_id', 'sale_id', 'user_id')
                    ->with(['user:id,name'])
                    ->latest();
            }])
            ->distinct('applicants.id', 'sales.id'); // Ensure no duplicates

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');

        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $query->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('sales.job_source_id', $sourceIds)
                    ->orWhereNull('sales.job_source_id');
            });
        }

        // Hide paid applicants for sales under offices/units with private notes (users without show-private-data).
        $this->excludePrivateOfficeUnitNotes($query);

        // Search filter (same columns as the CRM Paid tab)
        $searchTerm = trim((string) $searchTerm);
        if ($searchTerm !== '') {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('applicants.applicant_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_email', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_email_secondary', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_postcode', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_phone', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_phone_secondary', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_experience', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_landline', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('crm_notes.details', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('crm_notes.moved_tab_to', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('crm_notes.created_at', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('sales.timing', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('sales.experience', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('sales.salary', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('sales.qualification', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('sales.benefits', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('sales.sale_postcode', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('offices.office_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('units.unit_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('sale_job_sources.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('sale_job_categories.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('sale_job_titles.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_titles.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_categories.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('job_sources.name', 'LIKE', "%{$searchTerm}%");

                if (ctype_digit($searchTerm)) {
                    $q->orWhere('sales.id', (int) $searchTerm);
                }
            });
        }

        // Type, category, and title filters
        if ($typeFilter) {
            $query->where('applicants.job_type', $typeFilter);
        }

        if (!empty($categoryFilter)) {
            $query->whereIn('applicants.job_category_id', $categoryFilter);
        }

        if (!empty($titleFilter)) {
            $query->whereIn('applicants.job_title_id', $titleFilter);
        }

        // Filter by source if it's not empty
        if ($sourceFilter) {
            $query->whereIn('sales.job_source_id', $sourceFilter);
        }

        // Sorting
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            $sortableColumns = [
                'job_source' => 'sale_job_sources.name',
                'job_category' => 'applicants.job_category_id',
                'job_title' => 'applicants.job_title_id',
                'customStatus' => 'crm_notes.moved_tab_to',
                'job_details' => 'sales.id',
                'sale_office' => 'offices.office_name',
                'sale_unit' => 'units.unit_name',
                'sale_postcode' => 'sales.sale_postcode',
            ];

            $orderByColumn = $sortableColumns[$orderColumn] ?? 'crm_notes.created_at';
            $query->orderBy($orderByColumn, $orderDirection);
        } else {
            $query->orderBy('crm_notes.created_at', 'desc');
        }

        // Return DataTables response
        if ($request->ajax()) {
            $jobTitleNamesById = JobTitle::pluck('name', 'id');
            $jobCategoryNamesById = JobCategory::pluck('name', 'id');

            return DataTables::of($query)
                // Search is already applied above; stop DataTables adding its own column LIKEs on top
                ->filter(function ($query) {})
                ->addIndexColumn()
                ->addColumn('job_title', fn($applicant) => $applicant->job_title_name ? strtoupper($applicant->job_title_name) : '-')
                ->addColumn('job_category', function ($applicant) {
                    $type = $applicant->job_type;
                    $stype = $type === 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $applicant->job_category_name ? ucwords($applicant->job_category_name) . $stype : '-';
                })
                ->addColumn('sale_job_source', function ($applicant) {
                    if (!$applicant->sale_source_name)
                        return '-';
                    return '<span class="badge bg-light text-dark">' . e($applicant->sale_source_name) . '</span>';
                })
                ->addColumn('job_details', function ($applicant) use ($jobTitleNamesById, $jobCategoryNamesById) {
                    $position_type = strtoupper(str_replace('-', ' ', $applicant->position_type ?? ''));
                    $status = '';
                    if ($applicant->sale_status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($applicant->sale_status == 0 && $applicant->is_on_hold == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($applicant->sale_status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($applicant->sale_status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    $postcode = strtoupper($applicant->sale_postcode);
                    $posted_date = Carbon::parse($applicant->sale_posted_date)->format('d M Y, h:i A');
                    $office_name = ucwords($applicant->sale_office) ?? '-';
                    $unit_name = ucwords($applicant->sale_unit) ?? '-';
                    $jobTitleNameValue = $jobTitleNamesById->get($applicant->sale_title_id);
                    $jobTitle = $jobTitleNameValue ? strtoupper($jobTitleNameValue) : '-';
                    $stype  = $applicant->sale_job_type && $applicant->sale_job_type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    $jobCategoryNameValue = $jobCategoryNamesById->get($applicant->sale_category_id);
                    $jobCategory = $jobCategoryNameValue ? (ucwords($jobCategoryNameValue) . $stype) : '-';

                    $jobData = [
                        'sale_id'       => (int)$applicant->sale_id,
                        'posted_date'   => $posted_date,
                        'office_name'   => $office_name,
                        'unit_name'     => $unit_name,
                        'postcode'      => $postcode,
                        'job_category'  => $jobCategory,
                        'job_title'     => $jobTitle,
                        'sale_source_name' => $applicant->sale_source_name ?: '-',
                        'status'        => $status,
                        'timing'        => $applicant->timing,
                        'experience'    => $applicant->sale_experience,
                        'salary'        => $applicant->salary,
                        'position'      => $position_type,
                        'qualification' => $applicant->sale_qualification,
                        'benefits'      => $applicant->benefits,
                    ];

                    return '<a href="javascript:void(0);"
                            class="dropdown-item job-details"
                            data-job=\'' . json_encode(
                        $jobData,
                        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                    ) . '\'>
                            <iconify-icon icon="solar:square-arrow-right-up-bold" class="text-info fs-24"></iconify-icon>
                        </a>';
                })
                ->addColumn('applicant_name', fn($applicant) => $applicant->formatted_applicant_name)
                ->addColumn('applicant_postcode', function ($applicant) {
                    $rawPostcode = trim((string) $applicant->applicant_postcode);
                    if ($rawPostcode === '') {
                        return '<div class="text-center w-100">-</div>';
                    }

                    $status_value = $applicant->paid_status === 'close' ? 'paid' : 'open';
                    foreach ($applicant->cv_notes as $note) {
                        if ($note->status === 'active') {
                            $status_value = 'sent';
                            break;
                        } elseif ($note->status === 'disable') {
                            $status_value = 'reject';
                        }
                    }

                    $postcode = e($applicant->formatted_postcode);
                    if ($applicant->lat && $applicant->lng && in_array($status_value, ['open', 'reject'])) {
                        $url = route('applicants.available_job', ['id' => $applicant->id, 'radius' => 15]);
                        $postcode = '<a href="' . $url . '" style="color:blue;">' . $postcode . '</a>';
                    }

                    $copyButton = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode"
                                    data-postcode="' . e($rawPostcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';

                    return '<div class="d-flex align-items-center justify-content-between">' . $postcode . $copyButton . '</div>';
                })
                ->addColumn('applicant_notes', function ($applicant) {
                    $notes = e(htmlspecialchars($applicant->details, ENT_QUOTES, 'UTF-8'));
                    $name = htmlspecialchars($applicant->applicant_name, ENT_QUOTES, 'UTF-8');
                    $postcode = htmlspecialchars($applicant->applicant_postcode, ENT_QUOTES, 'UTF-8');
                    return '<a href="javascript:void(0);" title="View Note" onclick="showNotesModal(\'' . (int)$applicant->id . '\', \'' . $notes . '\', \'' . $name . '\', \'' . $postcode . '\')">
                            <iconify-icon icon="solar:eye-scan-bold" class="text-primary fs-24"></iconify-icon>
                        </a>';
                })
                ->addColumn('applicant_experience', function ($applicant) {
                    if (empty($applicant->applicant_experience) || $applicant->applicant_experience === 'NULL') {
                        return '-';
                    }
                    $short = Str::limit(strip_tags($applicant->applicant_experience), 80);
                    $full = e($applicant->applicant_experience);
                    $id = 'exp-' . $applicant->id;
                    return '
                        <a href="javascript:void(0);" class="text-primary" data-bs-toggle="modal" data-bs-target="#' . $id . '">' . $short . '</a>
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Applicant Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">' . nl2br($full) . '</div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('applicant_email', function ($applicant) {
                    // Blocked applicant + no permission
                    if ($applicant->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
                        return "<span class='badge bg-dark'>Blocked</span>";
                    }

                    $email = $applicant->applicant_email_secondary
                        ? $applicant->applicant_email . '<br>' . $applicant->applicant_email_secondary
                        : $applicant->applicant_email;

                    // Blocked applicant + has permission
                    if ($applicant->is_blocked && Gate::allows('applicant-show-blocked-data')) {
                        return '<div class="bg-dark text-white p-1 rounded">' . $email . '</div>';
                    }

                    // Normal applicant
                    return $email;
                })
                ->addColumn('applicant_phone', function ($applicant) {
                    if ($applicant->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
                        return "<span class='badge bg-dark'>Blocked</span>";
                    }

                    $showBlockedData = $applicant->is_blocked
                        && Gate::allows('applicant-show-blocked-data');

                    $class = $showBlockedData ? 'show_hidden_phone' : '';

                    $parts = [];

                    if (!empty($applicant->applicant_phone)) {
                        $parts[] = DialLink::render($applicant->applicant_phone, 'Primary Phone', $class);
                    }

                    if (!empty($applicant->applicant_phone_secondary)) {
                        $parts[] = DialLink::render($applicant->applicant_phone_secondary, 'Secondary Phone', $class);
                    }

                    if (!empty($applicant->applicant_landline)) {
                        $parts[] = DialLink::render($applicant->applicant_landline, 'Landline', $class);
                    }

                    $phones = implode('<br>', $parts) ?: '-';

                    if ($showBlockedData) {
                        return '<div class="bg-dark text-white" style="padding:6px 8px; border-radius:4px; color:#ffffff !important;">'
                            . $phones
                            . '</div>';
                    }

                    return $phones;
                })
                ->addColumn('crm_notes_created_at', fn($applicant) => Carbon::parse($applicant->crm_notes_created)->format('d M Y, h:i A'))
                ->addColumn('customStatus', function ($applicant) {
                    $statusColors = [
                        'dispute' => 'bg-warning',
                        'paid' => 'bg-success',
                        'declined' => 'bg-danger',
                        'default' => 'bg-primary',
                    ];
                    $statusClr = $statusColors[$applicant->moved_tab_to] ?? $statusColors['default'];
                    return '<span class="badge ' . $statusClr . '">' . strtoupper($applicant->moved_tab_to) . '</span>';
                })
                ->addColumn('action', function ($applicant) {
                    $status = match (true) {
                        $applicant->is_blocked => '<span class="badge bg-dark">Blocked</span>',
                        $applicant->status == 1 => '<span class="badge bg-success">Active</span>',
                        $applicant->is_no_response => '<span class="badge bg-danger">No Response</span>',
                        $applicant->is_circuit_busy => '<span class="badge bg-warning">Circuit Busy</span>',
                        $applicant->is_no_job => '<span class="badge bg-secondary">No Job</span>',
                        $applicant->status == 0 => '<span class="badge bg-secondary">Inactive</span>',
                        default => '',
                    };

                    return '<div class="btn-group dropstart">
                            <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                    ' . (int)$applicant->id . ',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_name)) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_email)) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_email_secondary)) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->formatted_postcode)) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->formatted_landline)) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->formatted_phone)) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->job_title_name ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->job_category_name ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->job_source_name ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->formatted_created_at)) . '\',
                                    \'' . addslashes(htmlspecialchars($status)) . '\'
                                )">View</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $applicant->id . ')">Notes History</a></li>
                            </ul>
                        </div>';
                })
                ->rawColumns(['applicant_notes', 'applicant_postcode', 'applicant_email', 'applicant_experience', 'applicant_phone', 'job_title', 'job_details', 'customStatus', 'crm_notes_created_at', 'job_category', 'sale_job_source', 'action'])
                ->make(true);
        }

        return response()->json(['error' => 'Invalid request'], 400);
    }
    public function getResourcesNoJobApplicants(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $searchTerm = $request->input('search', ''); // This will get the search query

        // Subquery for latest module_notes per applicant
        $latestNotesSub = DB::table('module_notes as mn')
            ->select('mn.id', 'mn.module_noteable_id', 'mn.user_id', 'mn.details', 'mn.created_at')
            ->join(
                DB::raw('(
                    SELECT MAX(id) AS id
                    FROM module_notes
                    WHERE module_noteable_type = "Horsefly\\\\Applicant"
                    GROUP BY module_noteable_id
                ) latest'),
                'latest.id',
                '=',
                'mn.id'
            )
            ->where('mn.module_noteable_type', 'Horsefly\\Applicant');

        $model = Applicant::query()
            ->select([
                'applicants.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name',
                'users.name as user_name',
                'module_notes.details as module_notes_details',
                'module_notes.created_at as module_notes_created_at',
            ])
            ->where('applicants.is_no_job', true)
            ->where('applicants.status', 1)
            ->whereNull('applicants.deleted_at')
            ->joinSub($latestNotesSub, 'module_notes', function ($join) {
                $join->on('applicants.id', '=', 'module_notes.module_noteable_id');
            })
            ->leftJoin('users', 'module_notes.user_id', '=', 'users.id')
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
            ->with(['jobTitle', 'jobCategory', 'jobSource'])
            ->distinct();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('applicants.job_source_id', $sourceIds)
                    ->orWhereNull('applicants.job_source_id');
            });
        }

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            if ($orderColumn === 'job_source') {
                $model->orderBy('applicants.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('applicants.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('applicants.job_title_id', $orderDirection);
            } elseif ($orderColumn && $orderColumn !== 'checkbox') {
                $model->orderBy($orderColumn, $orderDirection);
            } else {
                $model->orderBy('module_notes_created_at', 'desc');
            }
        } else {
            $model->orderBy('module_notes_created_at', 'desc');
        }

        if ($request->has('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            if (!empty($searchTerm)) {
                $model->where(function ($query) use ($searchTerm) {
                    // Direct column searches
                    $query->where('applicants.applicant_name', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_email', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_postcode', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_phone', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_experience', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_landline', 'LIKE', "%{$searchTerm}%");

                    // Relationship searches with explicit table names
                    $query->orWhereHas('jobTitle', function ($q) use ($searchTerm) {
                        $q->where('job_titles.name', 'LIKE', "%{$searchTerm}%");
                    });

                    $query->orWhereHas('jobCategory', function ($q) use ($searchTerm) {
                        $q->where('job_categories.name', 'LIKE', "%{$searchTerm}%");
                    });

                    $query->orWhereHas('jobSource', function ($q) use ($searchTerm) {
                        $q->where('job_sources.name', 'LIKE', "%{$searchTerm}%");
                    });

                    $query->orWhereHas('user', function ($q) use ($searchTerm) {
                        $q->where('users.name', 'LIKE', "%{$searchTerm}%");
                    });
                });
            }
        }

        // Filter by type if it's not empty
        if ($typeFilter == 'specialist') {
            $model->where('applicants.job_type', 'specialist');
        } elseif ($typeFilter == 'regular') {
            $model->where('applicants.job_type', 'regular');
        }

        // Filter by type if it's not empty
        if ($categoryFilter) {
            $model->whereIn('applicants.job_category_id', $categoryFilter);
        }

        // Filter by type if it's not empty
        if ($titleFilter) {
            $model->whereIn('applicants.job_title_id', $titleFilter);
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addColumn('checkbox', function ($applicant) {
                    return '<input type="checkbox" name="applicant_checkbox[]" class="applicant_checkbox" value="' . $applicant->id . '"/>';
                })
                ->addColumn('job_title', function ($applicant) {
                    return $applicant->jobTitle ? strtoupper($applicant->jobTitle->name) : '-';
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
                })
                ->addColumn('job_source', function ($applicant) {
                    return $applicant->jobSource ? ucwords($applicant->jobSource->name) : '-';
                })
                ->addColumn('user_name', function ($applicant) {
                    return $applicant->user_name ? ucwords($applicant->user_name) : '-';
                })
                ->addColumn('applicant_name', function ($applicant) {
                    return $applicant->formatted_applicant_name; // Using accessor
                })
                ->addColumn('applicant_postcode', function ($applicant) {
                    $status_value = 'open';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                    } else {
                        foreach ($applicant->cv_notes as $key => $value) {
                            if ($value->status == 'active') {
                                $status_value = 'sent';
                                break;
                            } elseif ($value->status == 'disable') {
                                $status_value = 'reject';
                            }
                        }
                    }

                    if ($applicant->lat != null && $applicant->lng != null && $status_value == 'open' || $status_value == 'reject') {
                        $url = route('applicants.available_no_job', ['id' => (int)$applicant->id, 'radius' => 15]);
                        $button = '<a href="' . $url . '" style="color:blue;" target="_blank">' . $applicant->formatted_postcode . '</a>'; // Using accessor
                    } else {
                        $button = $applicant->formatted_postcode;
                    }
                    return $button;
                })
                ->addColumn('applicant_notes', function ($applicant) {
                    if (empty($applicant->module_notes_details) || $applicant->module_notes_details === 'NULL') {
                        return '-';
                    }

                    $short = Str::limit(strip_tags($applicant->module_notes_details), 80);
                    $full = e($applicant->module_notes_details);
                    $id = 'note-' . $applicant->id;

                    return '
                        <a href="javascript:void(0);" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Applicant Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('applicant_phone', function ($applicant) {
                    $strng = '';
                    if ($applicant->applicant_landline) {
                        $phone = '<strong>P:</strong> ' . $applicant->applicant_phone;
                        $landline = '<strong>L:</strong> ' . $applicant->applicant_landline;

                        $strng = $applicant->is_blocked ? "<span class='badge bg-dark'>Blocked</span>" : $phone . '<br>' . $landline;
                    } else {
                        $phone = '<strong>P:</strong> ' . $applicant->applicant_phone;
                        $strng = $applicant->is_blocked ? "<span class='badge bg-dark'>Blocked</span>" : $phone;
                    }

                    return $strng;
                })
                ->addColumn('applicant_resume', fn ($applicant) => CvStorage::linkCell($applicant->id, $applicant->applicant_cv, $applicant->is_blocked, 'original', 'success', 'Download CV'))
                ->addColumn('crm_resume', fn ($applicant) => CvStorage::linkCell($applicant->id, $applicant->updated_cv, $applicant->is_blocked, 'updated', 'primary', 'Download Updated CV'))
                ->addColumn('applicant_experience', function ($applicant) {
                    if (empty($applicant->applicant_experience) || $applicant->applicant_experience === 'NULL') {
                        return '-';
                    }

                    $short = Str::limit(strip_tags($applicant->applicant_experience), 80);
                    $full = e($applicant->applicant_experience);
                    $id = 'exp-' . $applicant->id;

                    return '
                        <a href="javascript:void(0);" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Applicant Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('created_at', function ($applicant) {
                    return Carbon::parse($applicant->module_notes_created_at)->format('d M Y, h:i A'); // Using accessor
                })
                ->addColumn('customStatus', function ($applicant) {
                    $status_value = 'open';
                    $color_class = 'bg-primary';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                        $color_class = 'bg-info';
                    } else {
                        foreach ($applicant->cv_notes as $key => $value) {
                            if ($value->status == 'active') {
                                $status_value = 'sent';
                                $color_class = 'bg-success';
                                break;
                            } elseif ($value->status == 'disable') {
                                $status_value = 'reject';
                                $color_class = 'bg-danger';
                            }
                        }
                    }

                    $status = '';
                    $status .= '<span class="badge ' . $color_class . '">';
                    $status .= strtoupper($status_value);
                    $status .= '</span>';
                    return $status;
                })
                ->addColumn('action', function ($applicant) {
                    $landline = $applicant->formatted_landline;
                    $phone = $applicant->formatted_phone;
                    $postcode = $applicant->formatted_postcode;
                    $posted_date = $applicant->formatted_created_at;
                    $job_title = $applicant->jobTitle ? strtoupper($applicant->jobTitle->name) : '-';
                    $job_category = $applicant->jobCategory ? ucwords($applicant->jobCategory->name) : '-';
                    $job_source = $applicant->jobSource ? ucwords($applicant->jobSource->name) : '-';
                    $status = '';

                    if ($applicant->is_blocked) {
                        $status = '<span class="badge bg-dark">Blocked</span>';
                    } elseif ($applicant->status) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($applicant->is_no_response) {
                        $status = '<span class="badge bg-danger">No Response</span>';
                    } elseif ($applicant->is_circuit_busy) {
                        $status = '<span class="badge bg-warning">Circuit Busy</span>';
                    } elseif ($applicant->is_no_job) {
                        $status = '<span class="badge bg-secondary">No Job</span>';
                    } else {
                        $status = '<span class="badge bg-secondary">Inactive</span>';
                    }

                    return '<div class="btn-group dropstart">
                            <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                    ' . (int)$applicant->id . ',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_name)) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_email)) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_email_secondary)) . '\',
                                    \'' . addslashes(htmlspecialchars($postcode)) . '\',
                                    \'' . addslashes(htmlspecialchars($landline)) . '\',
                                    \'' . addslashes(htmlspecialchars($phone)) . '\',
                                    \'' . addslashes(htmlspecialchars($job_title)) . '\',
                                    \'' . addslashes(htmlspecialchars($job_category)) . '\',
                                    \'' . addslashes(htmlspecialchars($job_source)) . '\',
                                    \'' . addslashes(htmlspecialchars($posted_date)) . '\',
                                    \'' . addslashes(htmlspecialchars($status)) . '\'
                                )">View</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $applicant->id . ')">Notes History</a></li>
                            </ul>
                        </div>';
                })
                ->rawColumns(['checkbox', 'applicant_resume', 'crm_resume', 'user_name', 'applicant_experience', 'applicant_notes', 'applicant_postcode', 'applicant_phone', 'job_title', 'resume', 'customStatus', 'job_category', 'job_source', 'action'])
                ->make(true);
        }
    }
    public function getResourcesNotInterestedApplicants(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)

        $model = Applicant::query()
            ->select([
                'applicants.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name',
                // Offices
                'offices.office_name',
                // Sales
                'sales.id as sale_id',
                'sales.job_category_id as sale_category_id',
                'sales.job_title_id as sale_title_id',
                'sales.sale_postcode',
                'sales.job_type as sale_job_type',
                'sales.timing',
                'sales.salary',
                'sales.experience as sale_experience',
                'sales.qualification as sale_qualification',
                'sales.benefits',
                'sales.office_id as sale_office_id',
                'sales.unit_id as sale_unit_id',
                'sales.position_type',
                'sales.status as sale_status',
                'sales.created_at as sale_posted_date',
                // Units
                'units.unit_name',
                'units.unit_postcode',
                'units.unit_website',
                // Users
                'users.name as user_name',
                'notes_for_range_applicants.reason',
                'applicants_pivot_sales.created_at as pivot_created_at',
            ])
            ->where('applicants.is_no_job', 0)
            ->where('applicants.is_temp_not_interested', 1)
            ->where('applicants.status', 1)
            ->whereNull('applicants.deleted_at')

            ->join('applicants_pivot_sales', 'applicants_pivot_sales.applicant_id', '=', 'applicants.id')
            ->join('notes_for_range_applicants', 'applicants_pivot_sales.id', '=', 'notes_for_range_applicants.applicants_pivot_sales_id')
            ->join('sales', 'sales.id', '=', 'applicants_pivot_sales.sale_id')
            ->join('module_notes', 'applicants.id', '=', 'module_notes.module_noteable_id')
            ->leftJoin('users', 'module_notes.user_id', '=', 'users.id')
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
            ->join('offices', 'offices.id', '=', 'sales.office_id')
            ->join('units', 'units.id', '=', 'sales.unit_id')
            ->with(['jobTitle', 'jobCategory', 'jobSource'])
            ->distinct();

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('applicants.job_source_id', $sourceIds)
                    ->orWhereNull('applicants.job_source_id');
            });
        }

        // Hide rows for sales under offices/units with private notes (users without show-private-data).
        $this->excludePrivateOfficeUnitNotes($model);

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn === 'job_source') {
                $model->orderBy('applicants.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('applicants.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('applicants.job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'checkbox') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('applicants.updated_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('applicants.updated_at', 'desc');
        }

        if ($request->has('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            if (!empty($searchTerm)) {
                $model->where(function ($query) use ($searchTerm) {
                    // Direct column searches
                    $query->where('applicants.applicant_name', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_email', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_postcode', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_phone', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_experience', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_landline', 'LIKE', "%{$searchTerm}%");

                    // Relationship searches with explicit table names
                    $query->orWhereHas('jobTitle', function ($q) use ($searchTerm) {
                        $q->where('job_titles.name', 'LIKE', "%{$searchTerm}%");
                    });

                    $query->orWhereHas('jobCategory', function ($q) use ($searchTerm) {
                        $q->where('job_categories.name', 'LIKE', "%{$searchTerm}%");
                    });

                    $query->orWhereHas('jobSource', function ($q) use ($searchTerm) {
                        $q->where('job_sources.name', 'LIKE', "%{$searchTerm}%");
                    });

                    $query->orWhereHas('user', function ($q) use ($searchTerm) {
                        $q->where('users.name', 'LIKE', "%{$searchTerm}%");
                    });
                });
            }
        }

        // Filter by type if it's not empty
        if ($typeFilter == 'specialist') {
            $model->where('applicants.job_type', 'specialist');
        } elseif ($typeFilter == 'regular') {
            $model->where('applicants.job_type', 'regular');
        }

        // Filter by type if it's not empty
        if ($categoryFilter) {
            $model->whereIn('applicants.job_category_id', $categoryFilter);
        }

        // Filter by type if it's not empty
        if ($titleFilter) {
            $model->whereIn('applicants.job_title_id', $titleFilter);
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addColumn('checkbox', function ($applicant) {
                    return '<input type="checkbox" name="applicant_checkbox[]" 
                                class="applicant_checkbox" 
                                value="' . $applicant->id . '" 
                                data-sale-id="' . $applicant->sale_id . '"/>';
                })
                ->addColumn('job_title', function ($applicant) {
                    return $applicant->jobTitle ? strtoupper($applicant->jobTitle->name) : '-';
                })
                ->addColumn('job_category', function ($applicant) {
                    $type = $applicant->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $applicant->jobCategory ? ucwords($applicant->jobCategory->name) . $stype : '-';
                })
                ->addColumn('job_source', function ($applicant) {
                    return $applicant->jobSource ? ucwords($applicant->jobSource->name) : '-';
                })
                ->addColumn('job_details', function ($applicant) {
                    $position_type = strtoupper(str_replace('-', ' ', $applicant->position_type));
                    $position = '<span class="badge bg-primary">' . htmlspecialchars($position_type, ENT_QUOTES) . '</span>';

                    if ($applicant->sale_status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($applicant->sale_status == 0 && $applicant->is_on_hold == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($applicant->sale_status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($applicant->sale_status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    // Escape HTML in $status for JavaScript (to prevent XSS)
                    $escapedStatus = htmlspecialchars($status, ENT_QUOTES);

                    // Prepare modal HTML for the "Job Details"
                    $modalHtml = $this->generateJobDetailsModal($applicant);

                    // Return the action link with a modal trigger and the modal HTML
                    return '<a href="javascript:void(0);" class="dropdown-item" style="color: blue;" onclick="showJobDetailsModal('
                        . (int)$applicant->sale_id . ','
                        . '\'' . htmlspecialchars(Carbon::parse($applicant->sale_posted_date)->format('d M Y, h:i A'), ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$applicant->office_name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$applicant->unit_name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$applicant->sale_postcode, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$applicant->jobCategory->name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$applicant->jobTitle->name, ENT_QUOTES) . '\','
                        . '\'' . $escapedStatus . '\','
                        . '\'' . htmlspecialchars((string)$applicant->timing, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$applicant->sale_experience, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$applicant->salary, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$position, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$applicant->sale_qualification, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$applicant->benefits, ENT_QUOTES) . '\')">
                        <iconify-icon icon="solar:square-arrow-right-up-bold" class="text-info fs-24"></iconify-icon>
                        </a>' . $modalHtml;
                })
                ->addColumn('user_name', function ($applicant) {
                    return $applicant->user_name ? ucwords($applicant->user_name) : '-';
                })
                ->addColumn('applicant_email', function ($applicant) {
                    $email = '';
                    if ($applicant->applicant_email_secondary) {
                        $email = $applicant->applicant_email . '<br>' . $applicant->applicant_email_secondary;
                    } else {
                        $email = $applicant->applicant_email;
                    }

                    return $email; // Using accessor
                })
                ->addColumn('applicant_name', function ($applicant) {
                    return $applicant->formatted_applicant_name; // Using accessor
                })
                ->addColumn('applicant_postcode', function ($applicant) {
                    $status_value = 'open';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                    } else {
                        foreach ($applicant->cv_notes as $key => $value) {
                            if ($value->status == 'active') {
                                $status_value = 'sent';
                                break;
                            } elseif ($value->status == 'disable') {
                                $status_value = 'reject';
                            }
                        }
                    }

                    if ($applicant->lat != null && $applicant->lng != null && $status_value == 'open' || $status_value == 'reject') {
                        $url = route('applicants.available_no_job', ['id' => (int)$applicant->id, 'radius' => 15]);
                        $button = '<a href="' . $url . '" style="color:blue;" target="_blank">' . $applicant->formatted_postcode . '</a>'; // Using accessor
                    } else {
                        $button = $applicant->formatted_postcode;
                    }
                    return $button;
                })
                ->addColumn('notes_detail', function ($applicant) {
                    if (empty($applicant->reason) || $applicant->reason === 'NULL') {
                        return '-';
                    }
                    $notes_detail = strip_tags($applicant->reason); // avoid double escaping
                    $notes_created_at = Carbon::parse($applicant->pivot_created_at)->format('d M Y, h:i A');
                    $notes = "<strong>Date: {$notes_created_at}</strong><br>{$notes_detail}";

                    $short = Str::limit($notes, 80);
                    $modalId = 'crm-' . $applicant->id;

                    $name = e($applicant->applicant_name);
                    $postcode = e($applicant->applicant_postcode);
                    $notesEscaped = nl2br(e($notes_detail));

                    return '
                        <a href="javascript:void(0);" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $modalId . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $modalId . '" tabindex="-1" aria-labelledby="' . $modalId . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $modalId . '-label">Applicant\'s Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body modal-body-text-left">
                                        <p><strong>Name:</strong> ' . $name . '</p>
                                        <p><strong>Postcode:</strong> ' . $postcode . '</p>
                                        <p><strong>Date:</strong> ' . $notes_created_at . '</p>
                                        <p class="notes-content"><strong>Notes Detail:</strong><br>' . $notesEscaped . '</p>
                                    </div>
                                     <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('applicant_phone', function ($applicant) {
                    $strng = '';
                    if ($applicant->applicant_landline) {
                        $phone = '<strong>P:</strong> ' . $applicant->applicant_phone;
                        $landline = '<strong>L:</strong> ' . $applicant->applicant_landline;

                        $strng = $applicant->is_blocked ? "<span class='badge bg-dark'>Blocked</span>" : $phone . '<br>' . $landline;
                    } else {
                        $phone = '<strong>P:</strong> ' . $applicant->applicant_phone;
                        $strng = $applicant->is_blocked ? "<span class='badge bg-dark'>Blocked</span>" : $phone;
                    }

                    return $strng;
                })
                ->addColumn('applicant_experience', function ($applicant) {
                    if (empty($applicant->applicant_experience) || $applicant->applicant_experience === 'NULL') {
                        return '-';
                    }
                    $short = Str::limit(strip_tags($applicant->applicant_experience), 80);
                    $full = e($applicant->applicant_experience);
                    $id = 'exp-' . $applicant->id;

                    return '
                        <a href="javascript:void(0);" class="text-primary" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Applicant Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('pivot_created_at', function ($applicant) {
                    return Carbon::parse($applicant->pivot_created_at)->format('d M Y, h:i A'); // Using accessor
                })
                ->addColumn('customStatus', function ($applicant) {
                    $status_value = 'open';
                    $color_class = 'bg-primary';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                        $color_class = 'bg-info';
                    } else {
                        foreach ($applicant->cv_notes as $key => $value) {
                            if ($value->status == 'active') {
                                $status_value = 'sent';
                                $color_class = 'bg-success';
                                break;
                            } elseif ($value->status == 'disable') {
                                $status_value = 'reject';
                                $color_class = 'bg-danger';
                            }
                        }
                    }

                    $status = '';
                    $status .= '<span class="badge ' . $color_class . '">';
                    $status .= strtoupper($status_value);
                    $status .= '</span>';
                    return $status;
                })
                ->addColumn('action', function ($applicant) {
                    $landline = $applicant->formatted_landline;
                    $phone = $applicant->formatted_phone;
                    $postcode = $applicant->formatted_postcode;
                    $posted_date = $applicant->formatted_created_at;
                    $job_title = $applicant->jobTitle ? strtoupper($applicant->jobTitle->name) : '-';
                    $job_category = $applicant->jobCategory ? ucwords($applicant->jobCategory->name) : '-';
                    $job_source = $applicant->jobSource ? ucwords($applicant->jobSource->name) : '-';
                    $status = '';

                    if ($applicant->is_blocked) {
                        $status = '<span class="badge bg-dark">Blocked</span>';
                    } elseif ($applicant->status) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($applicant->is_no_response) {
                        $status = '<span class="badge bg-danger">No Response</span>';
                    } elseif ($applicant->is_circuit_busy) {
                        $status = '<span class="badge bg-warning">Circuit Busy</span>';
                    } elseif ($applicant->is_no_job) {
                        $status = '<span class="badge bg-secondary">No Job</span>';
                    } else {
                        $status = '<span class="badge bg-secondary">Inactive</span>';
                    }

                    return '<div class="btn-group dropstart">
                            <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                    ' . (int)$applicant->id . ',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_name)) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_email)) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_email_secondary)) . '\',
                                    \'' . addslashes(htmlspecialchars($postcode)) . '\',
                                    \'' . addslashes(htmlspecialchars($landline)) . '\',
                                    \'' . addslashes(htmlspecialchars($phone)) . '\',
                                    \'' . addslashes(htmlspecialchars($job_title)) . '\',
                                    \'' . addslashes(htmlspecialchars($job_category)) . '\',
                                    \'' . addslashes(htmlspecialchars($job_source)) . '\',
                                    \'' . addslashes(htmlspecialchars($posted_date)) . '\',
                                    \'' . addslashes(htmlspecialchars($status)) . '\'
                                )">View</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . $applicant->id . ')">Notes History</a></li>
                            </ul>
                        </div>';
                })
                ->rawColumns(['checkbox', 'user_name', 'applicant_email', 'applicant_experience', 'job_details', 'notes_detail', 'applicant_postcode', 'applicant_phone', 'job_title', 'customStatus', 'job_category', 'job_source', 'action'])
                ->make(true);
        }
    }
    private function generateJobDetailsModal($applicant)
    {
        $modalId = 'jobDetailsModal_' . $applicant->sale_id;  // Unique modal ID for each applicant's job details

        return '<div class="modal fade" id="' . $modalId . '" tabindex="-1" aria-labelledby="' . $modalId . 'Label" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-top modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="' . $modalId . 'Label">Job Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body modal-body-text-left">
                                <!-- Job details content will be dynamically inserted here -->
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>';
    }
    public function getResourcesCategoryWised(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $dateRangeFilter = $request->input('date_range_filter', ''); // Default is empty (no filter)
        $statusFilter = $request->input('status_filter', []);
        $allowedStatusFilters = ['', 'interested', 'not interested', 'blocked', 'have nursing home exp', 'all'];
        $request->validate([
            'status_filter' => ['sometimes', function ($attribute, $value, $fail) use ($allowedStatusFilters) {
                foreach ((array) $value as $status) {
                    if (!is_string($status) || !in_array(strtolower(trim($status)), $allowedStatusFilters, true)) {
                        $fail('The selected status filter is invalid.');
                        return;
                    }
                }
            }],
        ]);

        $statusFilters = array_map(
            function ($status) {
                return strtolower(trim((string) $status));
            },
            (array) $statusFilter
        );

        $today = Carbon::today()->toDateString();

        // Per-row lookups (correlated subqueries on indexed columns) instead of joining
        // derived tables built from the whole cv_notes / module_notes tables, which
        // made every request (and every count) scan those tables in full.
        $latestCvUserSub = DB::table('cv_notes as c2')
            ->join('users as cv_users', 'cv_users.id', '=', 'c2.user_id')
            ->select('cv_users.name')
            ->whereColumn('c2.applicant_id', 'applicants.id')
            ->where('c2.status', 1)
            ->orderByDesc('c2.created_at')
            ->orderByDesc('c2.id')
            ->limit(1);

        $activeCvNoteSub = DB::table('cv_notes as c3')
            ->selectRaw('1')
            ->whereColumn('c3.applicant_id', 'applicants.id')
            ->where('c3.status', 1)
            ->limit(1);

        $latestNoteCreatedSub = DB::table('module_notes as mn')
            ->selectRaw('MAX(mn.created_at)')
            ->where('mn.module_noteable_type', 'Horsefly\\Applicant')
            ->whereColumn('mn.module_noteable_id', 'applicants.id');

        $model = Applicant::query()
            ->with(['module_note', 'applicant_notes', 'jobTitle', 'jobCategory', 'jobSource', 'cv_notes'])
            ->select([
                'applicants.id',
                'applicants.applicant_name',
                'applicants.job_category_id',
                'applicants.job_title_id',
                'applicants.job_source_id',
                'applicants.applicant_postcode',
                'applicants.applicant_email',
                'applicants.applicant_email_secondary',
                'applicants.applicant_phone',
                'applicants.applicant_phone_secondary',
                'applicants.applicant_cv',
                'applicants.updated_cv',
                'applicants.is_blocked',
                'applicants.status',
                'applicants.have_nursing_home_experience',
                'applicants.created_at',
                'applicants.updated_at',
                'applicants.is_job_within_radius',
                'applicants.applicant_experience',
                'applicants.applicant_notes',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name',
            ])
            ->selectSub($latestCvUserSub, 'user_name')
            ->selectSub($activeCvNoteSub, 'cv_note_status')
            ->selectSub($latestNoteCreatedSub, 'latest_note_created')
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
            ->where('applicants.status', 1)
            ->whereNull('applicants.deleted_at')
            ->where(function ($query) use ($today) {
                $query->where('applicants.is_job_within_radius', 1)
                    ->orWhereBetween('applicants.created_at', [
                        Carbon::parse($today)->startOfDay(),
                        Carbon::parse($today)->endOfDay(),
                    ]);
            });

        $hidePrivateDataSetting = Setting::where('key', 'hide_private_data')->value('value');
        $hidePrivateData = array_filter(
            array_map('trim', explode(',', $hidePrivateDataSetting ?? ''))
        );

        $sourceIds = [];

        if (!Gate::allows('show-private-data') && count($hidePrivateData) > 0) {
            $sourceIds = JobSource::where('is_active', 1)
                ->where(function ($q) use ($hidePrivateData) {
                    foreach ($hidePrivateData as $hideName) {
                        $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        if (count($sourceIds) > 0) {
            $model->where(function ($q) use ($sourceIds) {
                $q->whereNotIn('applicants.job_source_id', $sourceIds)
                    ->orWhereNull('applicants.job_source_id');
            });
        }

        // Applicant is linked to a sale (was a LEFT JOIN that could duplicate rows)
        $inPivotSales = function ($query) {
            $query->select(DB::raw(1))
                ->from('applicants_pivot_sales')
                ->whereColumn('applicants_pivot_sales.applicant_id', 'applicants.id');
        };

        // Apply selected statuses as alternatives while keeping them grouped with the other filters.
        $statusPredicates = [
            'interested' => function ($query) use ($inPivotSales) {
                $query->whereNotExists($inPivotSales)
                    ->where('applicants.is_blocked', 0)
                    ->where('applicants.is_no_job', 0)
                    ->where('applicants.is_temp_not_interested', 0)
                    ->where(function ($q) {
                        $q->where('applicants.have_nursing_home_experience', 0)
                            ->orWhereNull('applicants.have_nursing_home_experience');
                    });
            },
            'not interested' => function ($query) use ($inPivotSales) {
                $query->where(function ($q) use ($inPivotSales) {
                    $q->where('applicants.is_temp_not_interested', 1)
                        ->orWhereExists($inPivotSales);
                })
                    ->where('applicants.is_blocked', 0)
                    ->where('applicants.is_no_job', 0)
                    ->where(function ($q) {
                        $q->where('applicants.have_nursing_home_experience', 0)
                            ->orWhereNull('applicants.have_nursing_home_experience');
                    });
            },
            'blocked' => function ($query) use ($inPivotSales) {
                $query->whereNotExists($inPivotSales)
                    ->where('applicants.is_blocked', 1)
                    ->where('applicants.is_no_job', 0)
                    ->where('applicants.is_temp_not_interested', 0)
                    ->where(function ($q) {
                        $q->where('applicants.have_nursing_home_experience', 0)
                            ->orWhereNull('applicants.have_nursing_home_experience');
                    });
            },
            'have nursing home exp' => function ($query) use ($inPivotSales) {
                $query->whereNotExists($inPivotSales)
                    // ->where('applicants.is_blocked', 0)
                    // ->where('applicants.is_temp_not_interested', 0)
                    ->where('applicants.have_nursing_home_experience', 1);
            },
        ];

        if ($statusFilters) {
            $model->where(function ($query) use ($statusFilters, $statusPredicates) {
                foreach ($statusFilters as $status) {
                    $query->orWhere(function ($statusQuery) use ($status, $statusPredicates) {
                        $statusPredicates[$status]($statusQuery);
                    });
                }
            });
        }

        // The age buckets are shared with the dashboard's counts (App\Support\ApplicantAgeWindows).
        ApplicantAgeWindows::apply($model, 'applicants.updated_at', $dateRangeFilter);

        // Sorting is applied by DataTables (see orderColumn mappings below). When the
        // request has no sortable column, default to the latest note first.
        $orderIndex = $request->input('order.0.column');
        $hasSortableOrder = $orderIndex !== null
            && $request->input("columns.{$orderIndex}.orderable") === 'true';
        if (!$hasSortableOrder) {
            $model->orderByDesc('latest_note_created');
        }

        // Global search, run once inside ->filter() so DataTables does not add its own
        // per-column LIKEs on computed columns (user_name, latest_note_created, ...).
        $applySearch = function ($query) use ($request) {
            $searchTerm = trim((string) $request->input('search.value', ''));
            if ($searchTerm === '') {
                return;
            }

            $like = '%' . $searchTerm . '%';
            $digits = preg_replace('/[^0-9]/', '', $searchTerm);

            $query->where(function ($q) use ($like, $digits) {
                foreach (
                    [
                        'applicants.applicant_name',
                        'applicants.applicant_email',
                        'applicants.applicant_email_secondary',
                        'applicants.applicant_postcode',
                        'applicants.applicant_phone',
                        'applicants.applicant_phone_secondary',
                        'applicants.applicant_landline',
                        'applicants.applicant_experience',
                        'job_titles.name',
                        'job_categories.name',
                        'job_sources.name',
                    ] as $column
                ) {
                    $q->orWhere($column, 'LIKE', $like);
                }

                // Phone typed with spaces/dashes, e.g. "07123 456 789"
                if (strlen($digits) >= 4) {
                    foreach (['applicants.applicant_phone', 'applicants.applicant_phone_secondary', 'applicants.applicant_landline'] as $column) {
                        $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?", ['%' . $digits . '%']);
                    }
                }

                // Applicant owner, or the agent shown in the "User" column (latest active CV note)
                $q->orWhereIn('applicants.user_id', User::where('name', 'LIKE', $like)->select('id'))
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))
                            ->from('cv_notes as sc')
                            ->join('users as su', 'su.id', '=', 'sc.user_id')
                            ->whereColumn('sc.applicant_id', 'applicants.id')
                            ->where('sc.status', 1)
                            ->where('su.name', 'LIKE', $like);
                    })
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))
                            ->from('module_notes as smn')
                            ->where('smn.module_noteable_type', 'Horsefly\\Applicant')
                            ->whereColumn('smn.module_noteable_id', 'applicants.id')
                            ->where('smn.details', 'LIKE', $like);
                    })
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))
                            ->from('applicant_notes as san')
                            ->whereColumn('san.applicant_id', 'applicants.id')
                            ->where('san.details', 'LIKE', $like);
                    });
            });
        };

        // Filter by type if it's not empty
        switch ($typeFilter) {
            case 'specialist':
                $model->where('applicants.job_type', 'specialist');
                break;
            case 'regular':
                $model->where('applicants.job_type', 'regular');
        }

        // Filter by type if it's not empty
        if ($categoryFilter) {
            $model->whereIn('applicants.job_category_id', $categoryFilter);
        }

        // Filter by type if it's not empty
        if ($titleFilter) {
            $model->whereIn('applicants.job_title_id', $titleFilter);
        }

        // Filter by job source if it's not empty
        $sourceFilter = array_filter((array) $request->input('source_filter', []), fn($id) => $id !== '' && $id !== null);
        if ($sourceFilter) {
            $model->whereIn('applicants.job_source_id', $sourceFilter);
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->filter($applySearch)
                ->orderColumn('latest_note_created', 'latest_note_created $1')
                ->orderColumn('latest_module_note.latest_note_created', 'latest_note_created $1')
                ->orderColumn('users.name', 'user_name $1')
                ->orderColumn('applicantEmail', 'applicants.applicant_email $1')
                ->orderColumn('applicantPhone', 'applicants.applicant_phone $1')
                ->addColumn('checkbox', function ($applicant) {
                    return '<input type="checkbox" name="applicant_checkbox[]" class="applicant_checkbox" value="' . $applicant->id . '"/>';
                })
                ->addColumn("user_name", function ($applicant) {
                    return $applicant->user_name ?? '-';
                })
                ->addColumn('job_title', function ($applicant) {
                    return $applicant->jobTitle ? strtoupper($applicant->jobTitle->name) : '-';
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
                })
                ->addColumn('job_source', function ($applicant) {
                    if (!$applicant->jobSource)
                        return '-';
                    return '<span class="badge bg-light text-dark">' . e($applicant->jobSource->name) . '</span>';
                })
                ->addColumn('applicant_name', function ($applicant) {
                    return $applicant->formatted_applicant_name; // Using accessor
                })
                ->addColumn('nursing_home_experience', function ($applicant) {
                    return $applicant->getRawOriginal('have_nursing_home_experience');
                })
                ->addColumn('applicant_postcode', function ($applicant) {
                    $status_value = 'open';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                    } else {
                        foreach ($applicant->cv_notes as $key => $value) {
                            if ($value->status == 1) {
                                $status_value = 'sent';
                                break;
                            } elseif ($value->status == 0) {
                                $status_value = 'reject';
                            }
                        }
                    }

                    $postcode = $applicant->formatted_postcode;
                    $copyBtn = '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2 copy-postcode" 
                                    data-postcode="' . e($applicant->applicant_postcode) . '" title="Copy Postcode">
                                    <iconify-icon icon="solar:copy-linear" class="fs-18"></iconify-icon>
                                </button>';

                    if ($applicant->lat != null && $applicant->lng != null && $status_value == 'open' || $status_value == 'reject') {
                        $url = route('applicants.available_job', ['id' => $applicant->id, 'radius' => 15]);
                        $link = '<a href="' . $url . '" target="_blank" class="active_postcode">' . $postcode . '</a>';
                        return '<div class="d-flex align-items-center justify-content-between">' . $link . $copyBtn . '</div>';
                    } else {
                        return '<div class="d-flex align-items-center justify-content-between"><span>' . $postcode . '</span>' . $copyBtn . '</div>';
                    }
                })
                ->addColumn('applicantEmail', function ($applicant) {
                    // Blocked applicant + no permission
                    if ($applicant->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
                        return "<span class='badge bg-dark'>Blocked</span>";
                    }

                    $email = $applicant->applicant_email_secondary
                        ? $applicant->applicant_email . '<br>' . $applicant->applicant_email_secondary
                        : $applicant->applicant_email;

                    // Blocked applicant + has permission
                    if ($applicant->is_blocked && Gate::allows('applicant-show-blocked-data')) {
                        return '<div class="bg-dark text-white p-1 rounded">' . $email . '</div>';
                    }

                    // Normal applicant
                    return $email;
                })
                ->filterColumn('applicantEmail', function ($query, $keyword) {
                    $keyword = strtolower(trim($keyword));

                    $query->where(function ($q) use ($keyword) {
                        $q->whereRaw('LOWER(applicants.applicant_email) LIKE ?', ["%{$keyword}%"])
                            ->orWhereRaw('LOWER(applicants.applicant_email_secondary) LIKE ?', ["%{$keyword}%"]);
                    });
                })
                ->addColumn('applicant_notes', function ($applicant) {
                    $note = null;

                    // Ensure module_note is iterable and get the one with max ID
                    if (!empty($applicant->module_note) && is_iterable($applicant->module_note)) {
                        foreach ($applicant->module_note as $item) {
                            if (!empty($item->details)) {
                                if ($note === null || $item->id > $note->id) {
                                    $note = $item; // pick the item with the max id
                                }
                            }
                        }
                    }

                    // Determine final note content
                    $notes = $note
                        ? strip_tags($note->details, '<strong><br>')
                        : (!empty($applicant->applicant_notes)
                            ? strip_tags($applicant->applicant_notes, '<strong><br>')
                            : '-'); // ✅ if no notes exist

                    // Determine status value
                    $status_value = 'open';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                    } elseif ($applicant->cv_note_status !== null) {
                        $status_value = $applicant->cv_note_status == 1 ? 'sent' : 'reject';
                    }

                    // ✅ Show dash if truly no note content
                    if ($notes === '-' || trim($notes) === '') {
                        return '-';
                    }

                    // Decide whether to show modal link or plain text
                    if (($applicant->is_blocked == 0 && $status_value == 'open') || $status_value == 'reject') {
                        return '
                            <a href="javascript:void(0);" style="color:blue" onclick="addShortNotesModal(' . (int)$applicant->id . ')">
                                ' . $notes . '
                            </a>
                        ';
                    }

                    return $notes;
                })
                ->addColumn('applicantPhone', function ($applicant) {

                    if ($applicant->is_blocked && !Gate::allows('applicant-show-blocked-data')) {
                        return "<span class='badge bg-dark'>Blocked</span>";
                    }

                    $showBlockedData = $applicant->is_blocked
                        && Gate::allows('applicant-show-blocked-data');

                    $class = $showBlockedData ? 'show_hidden_phone' : '';

                    $parts = [];

                    if (!empty($applicant->applicant_phone)) {
                        $parts[] = DialLink::render($applicant->applicant_phone, 'Primary Phone', $class);
                    }

                    if (!empty($applicant->applicant_phone_secondary)) {
                        $parts[] = DialLink::render($applicant->applicant_phone_secondary, 'Secondary Phone', $class);
                    }

                    if (!empty($applicant->applicant_landline)) {
                        $parts[] = DialLink::render($applicant->applicant_landline, 'Landline', $class);
                    }

                    $phones = implode('<br>', $parts) ?: '-';

                    if ($showBlockedData) {
                        return '<div class="bg-dark text-white" style="padding:6px 8px; border-radius:4px; color:#ffffff !important;">'
                            . $phones
                            . '</div>';
                    }

                    return $phones;
                })
                ->filterColumn('applicantPhone', function ($query, $keyword) {
                    $clean = preg_replace('/[^0-9]/', '', $keyword); // remove spaces, dashes, etc.

                    $query->where(function ($q) use ($clean) {
                        $q->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone_secondary, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_landline, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"]);
                    });
                })
                ->addColumn('created_at', function ($applicant) {
                    $latestNote = null;

                    if (!empty($applicant->module_note) && is_iterable($applicant->module_note)) {
                        foreach ($applicant->module_note as $item) {
                            if (!empty($item->created_at)) {
                                if ($latestNote === null || $item->id > $latestNote->id) {
                                    $latestNote = $item; // pick the item with max id
                                }
                            }
                        }
                    }

                    return $latestNote
                        ? Carbon::parse($latestNote->created_at)->format('d M Y h:i A')
                        : $applicant->formatted_updated_at; // fallback
                })
                ->addColumn('applicant_experience', function ($applicant) {
                    if (empty($applicant->applicant_experience) || $applicant->applicant_experience === 'NULL') {
                        return '-';
                    }

                    $short = Str::limit(strip_tags($applicant->applicant_experience), 80);
                    $full = e($applicant->applicant_experience);
                    $id = 'exp-' . $applicant->id;

                    return '
                        <a href="javascript:void(0);" class="text-primary" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Applicant Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('applicant_resume', fn ($applicant) => CvStorage::linkCell($applicant->id, $applicant->applicant_cv, $applicant->is_blocked, 'original', 'success', 'Download CV'))
                ->addColumn('crm_resume', fn ($applicant) => CvStorage::linkCell($applicant->id, $applicant->updated_cv, $applicant->is_blocked, 'updated', 'primary', 'Download Updated CV'))
                ->addColumn('customStatus', function ($applicant) {
                    $status_value = 'open';
                    $color_class = 'bg-dark';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                        $color_class = 'bg-info';
                    } else {
                        foreach ($applicant->cv_notes as $key => $value) {
                            if ($value->status == 1) {
                                $status_value = 'sent';
                                $color_class = 'bg-success';
                                break;
                            } elseif ($value->status == 0) {
                                $status_value = 'reject';
                                $color_class = 'bg-danger';
                            }
                        }
                    }

                    $status = '';
                    $status .= '<span class="badge ' . $color_class . '">';
                    $status .= strtoupper($status_value);
                    $status .= '</span>';
                    return $status;
                })
                ->addColumn('action', function ($applicant) {
                    $landline = $applicant->formatted_landline ?? '-';
                    $phone = $applicant->formatted_phone ?? '-';
                    $posted_date = $applicant->formatted_created_at;
                    $postcode = $applicant->formatted_postcode ?? '-';
                    $job_title = $applicant->jobTitle ? $applicant->jobTitle->name : '-';
                    $job_category = $applicant->jobCategory ? $applicant->jobCategory->name : '-';
                    $job_source = $applicant->jobSource ? $applicant->jobSource->name : '-';
                    $status = '';

                    if ($applicant->is_blocked) {
                        $status = '<span class="badge bg-dark">Blocked</span>';
                    } elseif ($applicant->status) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($applicant->is_no_response) {
                        $status = '<span class="badge bg-danger">No Response</span>';
                    } elseif ($applicant->is_circuit_busy) {
                        $status = '<span class="badge bg-warning">Circuit Busy</span>';
                    } elseif ($applicant->is_no_job) {
                        $status = '<span class="badge bg-secondary">No Job</span>';
                    } else {
                        $status = '<span class="badge bg-secondary">Inactive</span>';
                    }
                    $html = '';
                    $html .= '<div class="btn-group dropstart">
                            <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                            </button>
                            <ul class="dropdown-menu">';
                    if (Gate::allows('resource-category-view')) {
                        $html .= '<li>
                                <a class="dropdown-item" href="javascript:void(0);" onclick="showDetailsModal(
                                    ' . (int)$applicant->id . ',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_name)) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_email ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($applicant->applicant_email_secondary ?? '-')) . '\',
                                    \'' . addslashes(htmlspecialchars($postcode)) . '\',
                                    \'' . addslashes(htmlspecialchars($landline)) . '\',
                                    \'' . addslashes(htmlspecialchars($phone)) . '\',
                                    \'' . addslashes(htmlspecialchars($job_title)) . '\',
                                    \'' . addslashes(htmlspecialchars($job_category)) . '\',
                                    \'' . addslashes(htmlspecialchars($job_source)) . '\',
                                    \'' . addslashes(htmlspecialchars($posted_date)) . '\',
                                    \'' . addslashes(htmlspecialchars($status)) . '\'
                                )">View</a>
                            </li>';
                    }
                    if (Gate::allows('resource-category-upload-applicant-resume')) {
                        $html .= '<li>
                                    <a class="dropdown-item" href="javascript:void(0);" onclick="triggerFileInput(' . (int)$applicant->id . ')">Upload Applicant Resume</a>
                                    <input type="file" id="fileInput" style="display:none" accept=".pdf,.doc,.docx" onchange="uploadFile()">
                                </li>';
                    }
                    if (Gate::allows('resource-category-upload-crm-resume')) {
                        $html .= '<li>
                                    <a class="dropdown-item" href="javascript:void(0);" onclick="triggerCrmFileInput(' . (int)$applicant->id . ')">Upload CRM Resume</a>
                                    <!-- Hidden File Input -->
                                    <input type="file" id="crmfileInput" style="display:none" accept=".pdf,.doc,.docx" onchange="crmuploadFile()">
                                </li>';
                    }
                    $html .= '<li><hr class="dropdown-divider"></li>';
                    if (Gate::allows('applicant-view-history')) {
                        $html .= '<li><a class="dropdown-item" target="_blank" href="' . route('applicants.history', ['id' => (int) $applicant->id]) . '">View History</a></li>';
                    }
                    if (Gate::allows('resource-category-view-notes-history')) {
                        $html .= '<li><a class="dropdown-item" href="javascript:void(0);" onclick="viewNotesHistory(' . (int)$applicant->id . ')">Notes History</a></li>';
                    }
                    $html .= '</ul>
                            </div>';
                    return $html;
                })
                // ->setRowClass(function ($applicant) {
                //     $row_class = '';

                //     // First check nursing home experience status
                //     if (isset($applicant->have_nursing_home_experience) && $applicant->have_nursing_home_experience == 0) {
                //         $row_class = 'have-no-nursing-home-exp';  // Specific class for no experience
                //     }elseif (isset($applicant->have_nursing_home_experience) && $applicant->have_nursing_home_experience == 1) {
                //         $row_class = 'have-nursing-home-exp';  // Specific class for no experience
                //     } else {
                //         if ($applicant->paid_status == 'close') {
                //             $row_class = 'class_dark';
                //         } elseif ($applicant->is_no_job == true) {
                //             $row_class = 'class_noJob';
                //         } else {
                //             /*** $applicant->paid_status == 'open' || $applicant->paid_status == 'pending' */
                //             foreach ($applicant->cv_notes as $key => $value) {
                //                 if ($value->status == 1) {
                //                     $row_class = 'class_success';
                //                     break;
                //                 } elseif ($value->status == 0) {
                //                     $row_class = 'class_danger';
                //                 }
                //             }
                //         }
                //     }
                //     return $row_class;
                // })
                ->rawColumns(['checkbox', 'applicantEmail', 'applicant_experience', 'applicant_notes', 'applicant_postcode', 'applicantPhone', 'job_title', 'applicant_resume', 'crm_resume', 'customStatus', 'job_category', 'job_source', 'action'])
                ->make(true);
        }
    }
    public function revertBlockedApplicant(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:applicants,id',
        ]);

        try {
            DB::beginTransaction();

            $applicantIds = $request->input('ids');
            $unblockedCount = 0;

            foreach ($applicantIds as $applicantId) {
                $applicant = Applicant::find($applicantId);

                if ($applicant && $applicant->is_blocked) {
                    $applicant->update([
                        'is_blocked' => false,
                        'applicant_notes' => 'Applicant has been unblocked',
                    ]);

                    // Deactivate previous active notes
                    ModuleNote::where('module_noteable_id', $applicant->id)
                        ->where('module_noteable_type', 'Horsefly\Applicant')
                        ->where('status', 1)
                        ->update(['status' => 0]);

                    // Create new module note
                    $moduleNote = ModuleNote::create([
                        'user_id' => Auth::id(),
                        'module_noteable_id' => $applicant->id,
                        'module_noteable_type' => 'Horsefly\Applicant',
                        'details' => 'Applicant has been unblocked',
                    ]);

                    $moduleNote->update([
                        'module_note_uid' => md5($moduleNote->id),
                    ]);

                    $unblockedCount++;
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "$unblockedCount applicant(s) unblocked successfully.",
            ], 200);
        } catch (Exception $exception) {
            DB::rollBack();

            Log::error("Failed to revert blocked applicants: " . $exception->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong! Please try again.',
            ], 500);
        }
    }
    public function revertNoJobApplicant(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:applicants,id',
        ]);

        try {
            DB::beginTransaction();

            $applicantIds = $request->input('ids');
            $revertedCount = 0;

            foreach ($applicantIds as $applicantId) {
                $applicant = Applicant::find($applicantId);

                if ($applicant && $applicant->is_no_job) {
                    $applicant->update([
                        'is_no_job' => false,
                        'applicant_notes' => 'No job applicant has been reverted.',
                    ]);

                    // Soft-close previous active notes
                    ModuleNote::where('module_noteable_id', $applicant->id)
                        ->where('module_noteable_type', 'Horsefly\Applicant')
                        ->where('status', 1)
                        ->update(['status' => 0]);

                    // Create new module note
                    $moduleNote = ModuleNote::create([
                        'user_id' => Auth::id(),
                        'module_noteable_id' => $applicant->id,
                        'module_noteable_type' => 'Horsefly\Applicant',
                        'details' => 'No job applicant has been reverted.',
                    ]);

                    $moduleNote->update([
                        'module_note_uid' => md5($moduleNote->id),
                    ]);

                    $revertedCount++;
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "$revertedCount applicant(s) reverted from 'No Job'."
            ]);
        } catch (Exception $exception) {
            DB::rollBack();

            Log::error("Failed to revert no job applicants: " . $exception->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong! Please try again.'
            ], 500);
        }
    }
    public function markAsNursingHomeExp(Request $request)
    {
        // Validate the request
        $request->validate([
            'selectedCheckboxes' => 'required|array',
            'selectedCheckboxes.*' => 'integer|exists:applicants,id',
        ]);

        try {
            DB::beginTransaction();

            $selectedIds = $request->input('selectedCheckboxes');

            // Bulk update applicants
            $updatedCount = Applicant::whereIn('id', $selectedIds)
                ->update(['have_nursing_home_experience' => true]);

            // Disable existing module notes for those applicants
            ModuleNote::whereIn('module_noteable_id', $selectedIds)
                ->where('module_noteable_type', 'Horsefly\Applicant')
                ->where('status', 1)
                ->update(['status' => 0]);

            // Create new module notes
            foreach ($selectedIds as $id) {
                $moduleNote = ModuleNote::create([
                    'user_id' => Auth::id(),
                    'module_noteable_id' => $id,
                    'module_noteable_type' => 'Horsefly\Applicant',
                    'details' => 'Applicant has been marked as having nursing home experience',
                ]);

                $moduleNote->update([
                    'module_note_uid' => md5($moduleNote->id)
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "$updatedCount applicant(s) marked as having nursing home experience.",
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to mark applicants as nursing home experience: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating applicants.',
            ], 500);
        }
    }
    public function markAsNoNursingHomeExp(Request $request)
    {
        // Validate the request
        $request->validate([
            'selectedCheckboxes' => 'required|array',
            'selectedCheckboxes.*' => 'integer|exists:applicants,id',
        ]);

        try {
            DB::beginTransaction();

            $selectedIds = $request->input('selectedCheckboxes');

            // Bulk update applicants
            $updatedCount = Applicant::whereIn('id', $selectedIds)
                ->update(['have_nursing_home_experience' => false]);

            // Disable previous active module notes
            ModuleNote::whereIn('module_noteable_id', $selectedIds)
                ->where('module_noteable_type', 'Horsefly\Applicant')
                ->where('status', 1)
                ->update(['status' => 0]);

            // Create new module notes
            foreach ($selectedIds as $id) {
                $moduleNote = ModuleNote::create([
                    'user_id' => Auth::id(),
                    'module_noteable_id' => $id,
                    'module_noteable_type' => 'Horsefly\Applicant',
                    'details' => 'Applicant has been marked as having no nursing home experience',
                ]);

                $moduleNote->update([
                    'module_note_uid' => md5($moduleNote->id)
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "$updatedCount applicant(s) marked as having no nursing home experience.",
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to mark applicants as no nursing home experience: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating applicants.',
            ], 500);
        }
    }
    public function revertNotInterestedApplicant(Request $request)
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();

            $applicant_ids = $request->input('applicant_ids', []);
            $sale_ids = $request->input('sale_ids', []);

            // Shared with the Xplogent API (App\Services\Xplogent\SaleInterestService).
            $interest = app(SaleInterestService::class);

            if (!empty($applicant_ids) && !empty($sale_ids)) {
                foreach ($applicant_ids as $index => $applicant_id) {
                    $sale_id = $sale_ids[$index] ?? null;
                    if (!$sale_id) {
                        continue; // skip if missing
                    }
                    $interest->revertNotInterested((int) $applicant_id, (int) $sale_id, $user);
                }
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => "Applicants marked as interested successfully."
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to mark applicant as interested: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong! Please try again.'
            ], 500);
        }
    }
    public function markApplicantNotInterestedOnSale(Request $request)
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();

            $applicant_id = $request->input('applicant_id');
            $sale_id = $request->input('sale_id');
            $details = $request->input('details');
            // Shared with the Xplogent API (App\Services\Xplogent\SaleInterestService).
            app(SaleInterestService::class)->markNotInterested((int) $applicant_id, (int) $sale_id, (string) $details, $user);

            DB::commit();
            return redirect()->back()->with('success', 'Applicant marked as not interested successfully.');
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to mark applicant not interested: ' . $e->getMessage());

            return redirect()->back()->with('error', 'Something went wrong. Please try again.');
        }
    }
    public function markApplicantCallback(Request $request)
    {
        $request->validate([
            'applicant_id' => 'required|integer|exists:applicants,id',
            'sale_id' => 'nullable|integer|exists:sales,id',
            'details' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();

        $applicant_id = $request->input('applicant_id');
        $sale_id = $request->input('sale_id');
        $details = $request->input('details');
        $notes = $details . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

        try {
            DB::beginTransaction();

            // Handle pivot sale if sale_id is given
            if ($sale_id) {
                $pivotSale = ApplicantPivotSale::where('applicant_id', $applicant_id)
                    ->where('sale_id', $sale_id)
                    ->first();

                if ($pivotSale) {
                    // Delete range notes
                    NotesForRangeApplicant::where('applicants_pivot_sales_id', $pivotSale->id)->delete();
                    $pivotSale->delete();
                }
            }

            // Disable previous callback/revert_callback notes
            ApplicantNote::where('applicant_id', $applicant_id)
                ->whereIn('moved_tab_to', ['callback', 'revert_callback'])
                ->update(['status' => false]);

            // Create new ApplicantNote
            $applicantNote = ApplicantNote::create([
                'user_id' => $user->id,
                'applicant_id' => $applicant_id,
                'details' => $notes,
                'moved_tab_to' => 'callback',
            ]);

            $applicantNote->update([
                'note_uid' => md5($applicantNote->id)
            ]);

            // Mark applicant as callback enabled
            Applicant::where('id', $applicant_id)
                ->update(['is_callback_enable' => true]);

            // Disable previous active module notes
            ModuleNote::where([
                'module_noteable_id' => $applicant_id,
                'module_noteable_type' => 'Horsefly\Applicant',
                'status' => 1
            ])->update(['status' => 0]);

            // Create new module note
            $moduleNote = ModuleNote::create([
                'details' => $notes,
                'user_id' => $user->id,
                'module_noteable_id' => $applicant_id,
                'module_noteable_type' => 'Horsefly\Applicant'
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id)
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Callback marked successfully!',
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to mark applicant callback: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong. Please try again.',
            ], 500);
        }
    }
    public function exportDirectApplicantsEmails(Request $request)
    {
        $emailData = $request->input('app_email'); // string: "a@a.com, b@b.com"
        $dataEmail = array_filter(array_map('trim', explode(',', $emailData))); // remove spaces and empty values

        return Excel::download(new EmailExport($dataEmail), 'applicants.csv');
    }
}
